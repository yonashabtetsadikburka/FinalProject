<?php
declare(strict_types=1);

namespace App\Controller;

use App\Exception\AppException;
use App\Service\ApiResponse;
use App\Service\AuthService;
use App\Service\DatabaseService;
use App\Service\StatoService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class PartecipazioniController extends AbstractController
{
    public function __construct(private DatabaseService $db, private AuthService $auth, private StatoService $stato) {}

    #[Route('/api/campagne/{id}/partecipazioni', methods: ['POST'], requirements: ['id'=>'\d+'])]
    #[Route('/campagne/{id}/partecipazioni', methods: ['POST'], requirements: ['id'=>'\d+'])]
    public function aderisci(int $id, Request $request): JsonResponse
    {
        $io = $this->auth->richiediUtenteAttivo();
        $d = ApiResponse::corpo($request);
        $quantita = ApiResponse::campoInt($d, 'quantita', 1);
        $statoPrima = $this->stato->ricalcolaStato($id);
        if ($statoPrima==='fallita') throw new AppException('CAMPAGNA_FALLITA','La campagna e\' fallita');
        if ($statoPrima==='riuscita') throw new AppException('CAMPAGNA_COMPLETA','La campagna ha gia\' raggiunto la soglia');
        if ($statoPrima==='ordine_pronto') throw new AppException('CAMPAGNA_ORDINATA','La campagna e\' in fase di conferma');
        if ($statoPrima==='ordine_fornitore') throw new AppException('CAMPAGNA_ORDINATA','La campagna e\' gia\' stata ordinata al fornitore');
        if ($statoPrima==='consegnata') throw new AppException('CAMPAGNA_CONSEGNATA','La campagna e\' gia\' stata consegnata');
        if ($statoPrima==='annullata') throw new AppException('CAMPAGNA_ANNULLATA','La campagna e\' stata annullata');
        $st=$this->db->getPdo()->prepare('SELECT id FROM prenotazioni WHERE id_colletta = ? AND id_utente = ?'); $st->execute([$id,$io]); if($st->fetch()) throw new AppException('GIA_PARTECIPI','Hai gia\' partecipato a questa campagna',409);
        $st=$this->db->getPdo()->prepare('INSERT INTO prenotazioni (id_colletta, id_utente, quantita, importo_acconto, stato) VALUES (?,?, ?, 0, \'prenotata\')'); $st->execute([$id,$io,$quantita]);
        $st=$this->db->getPdo()->prepare('UPDATE collette SET quantita_attuale = quantita_attuale + ? WHERE id = ?'); $st->execute([$quantita,$id]);
        $nuovoStato=$this->stato->ricalcolaStato($id);
        if($statoPrima==='in_corso' && $nuovoStato==='riuscita'){
            $st=$this->db->getPdo()->prepare('SELECT DISTINCT id_utente FROM prenotazioni WHERE id_colletta = ?'); $st->execute([$id]); $utenti=$st->fetchAll();
            $stProdotto=$this->db->getPdo()->prepare('SELECT pr.nome FROM collette c JOIN prodotti pr ON pr.id = c.id_prodotto WHERE c.id = ?'); $stProdotto->execute([$id]); $nomeProdotto=$stProdotto->fetchColumn()?:'una campagna';
            $stNotifica=$this->db->getPdo()->prepare('INSERT INTO notifiche (id_utente, tipo, titolo, messaggio, tipo_riferimento, id_riferimento) VALUES (?, \'MOQ_RAGGIUNTO\', \'Soglia Raggiunta\', ?, \'colletta\', ?)');
            foreach($utenti as $u){ $messaggio="La soglia minima per \"$nomeProdotto\" è stata raggiunta! Riceverai istruzioni per il pagamento."; $stNotifica->execute([(int)$u['id_utente'],$messaggio,$id]); }
            try{ $stForn=$this->db->getPdo()->prepare('SELECT f.id_utente FROM collette c JOIN prodotti pr ON pr.id = c.id_prodotto JOIN fornitori f ON f.id = pr.id_fornitore WHERE c.id = ?'); $stForn->execute([$id]); $fornUtente=$stForn->fetchColumn(); if($fornUtente){ $msgForn="Soglia MOQ raggiunta per \"$nomeProdotto\"! Preparati a evadere l'ordine cumulativo."; $stNotifica->execute([(int)$fornUtente,$msgForn,$id]); } }catch(\Throwable $e){ error_log("MOQ fornitore notify fallita per colletta #$id: ".$e->getMessage()); }
        }
        return ApiResponse::ok(['stato'=>$nuovoStato,'quantita'=>$quantita,'messaggio'=>'Partecipazione registrata. Nessun addebito ora.']);
    }

    #[Route('/api/campagne/{id}/partecipazioni', methods: ['DELETE'], requirements: ['id'=>'\d+'])]
    #[Route('/campagne/{id}/partecipazioni', methods: ['DELETE'], requirements: ['id'=>'\d+'])]
    public function ritira(int $id): JsonResponse
    {
        $io=$this->auth->richiediLogin();
        $stato=$this->stato->ricalcolaStato($id);
        if($stato==='ordine_fornitore') throw new AppException('CAMPAGNA_ORDINATA','Non si puo\' uscire da una campagna già ordinata');
        if($stato==='consegnata') throw new AppException('CAMPAGNA_CONSEGNATA','Non si puo\' uscire da una campagna consegnata');
        $st=$this->db->getPdo()->prepare('SELECT id, quantita, stato FROM prenotazioni WHERE id_colletta = ? AND id_utente = ?'); $st->execute([$id,$io]); $p=$st->fetch(); if(!$p) throw new AppException('NON_PARTECIPI','Non risulti iscritto a questa campagna',404);
        if(in_array($p['stato'],['pagata','rimborsata'],true)) throw new AppException('PAGAMENTO_EFFETTUATO','Non puoi annullare: pagamento già effettuato. Contatta l\'assistenza per un rimborso.');
        $st=$this->db->getPdo()->prepare('DELETE FROM prenotazioni WHERE id = ?'); $st->execute([$p['id']]);
        $st=$this->db->getPdo()->prepare('UPDATE collette SET quantita_attuale = GREATEST(0, quantita_attuale - ?) WHERE id = ?'); $st->execute([(int)$p['quantita'],$id]);
        $nuovoStato=$this->stato->ricalcolaStato($id);
        return ApiResponse::ok(['stato'=>$nuovoStato]);
    }

    #[Route('/api/mie/partecipazioni', methods: ['GET'])]
    #[Route('/mie/partecipazioni', methods: ['GET'])]
    public function mie(): JsonResponse
    {
        $io=$this->auth->richiediLogin();
        $st=$this->db->getPdo()->prepare('SELECT p.id, p.id_colletta, p.quantita, p.stato, p.data_prenotazione, c.quantita_minima, c.quantita_attuale, c.data_limite, c.stato AS stato_colletta, pr.nome AS prodotto, f.nome_azienda AS fornitore, qr.stato AS stato_qr, co.id AS id_consegna, co.modalita AS consegna_modalita, co.importo_consegna, co.stato AS consegna_stato FROM prenotazioni p JOIN collette c ON c.id = p.id_colletta JOIN prodotti pr ON pr.id = c.id_prodotto JOIN fornitori f ON f.id = pr.id_fornitore LEFT JOIN qr_codes qr ON qr.id_prenotazione = p.id LEFT JOIN consegne co ON co.id_prenotazione = p.id WHERE p.id_utente = ? ORDER BY p.data_prenotazione DESC');
        $st->execute([$io]);
        return ApiResponse::ok($st->fetchAll());
    }

    #[Route('/api/mie/statistiche', methods: ['GET'])]
    #[Route('/mie/statistiche', methods: ['GET'])]
    public function mieStatistiche(): JsonResponse
    {
        $io=$this->auth->richiediLogin();
        $pdo=$this->db->getPdo();
        // Replicate pagamenti.php mie_statistiche logic
        $st=$pdo->prepare('SELECT COUNT(*) FROM prenotazioni WHERE id_utente = ? AND stato != \'annullata\''); $st->execute([$io]); $nAttive=(int)$st->fetchColumn();
        $st=$pdo->prepare('SELECT SUM(p.quantita*c.prezzo_corrente*(1+c.percentuale_commissione/100)) FROM prenotazioni p JOIN collette c ON c.id=p.id_colletta WHERE p.id_utente=? AND p.stato=\'confermata\''); $st->execute([$io]); $daPagare=(float)($st->fetchColumn()??0);
        $st=$pdo->prepare('SELECT SUM(pg.importo) FROM pagamenti pg JOIN prenotazioni p ON p.id=pg.id_prenotazione WHERE p.id_utente=?'); $st->execute([$io]); $spesa=(float)($st->fetchColumn()??0);
        $st=$pdo->prepare('SELECT SUM((c.prezzo_base-c.prezzo_corrente)*p.quantita) FROM prenotazioni p JOIN collette c ON c.id=p.id_colletta JOIN pagamenti pg ON pg.id_prenotazione=p.id WHERE p.id_utente=?'); $st->execute([$io]); $risparmio=(float)($st->fetchColumn()??0);
        $st=$pdo->prepare('SELECT COUNT(*) FROM prenotazioni p JOIN qr_codes qr ON qr.id_prenotazione=p.id WHERE p.id_utente=? AND qr.stato=\'generato\''); $st->execute([$io]); $nRitirare=(int)$st->fetchColumn();
        $st=$pdo->prepare('SELECT COUNT(*) FROM prenotazioni p JOIN qr_codes qr ON qr.id_prenotazione=p.id WHERE p.id_utente=? AND qr.stato=\'scansionato\''); $st->execute([$io]); $nRitirati=(int)$st->fetchColumn();
        return ApiResponse::ok(['spesa_totale'=>round($spesa,2),'risparmio_stimato'=>round($risparmio,2),'da_pagare'=>round($daPagare,2),'n_attive'=>$nAttive,'n_ritirare'=>$nRitirare,'n_ritirati'=>$nRitirati]);
    }
}
