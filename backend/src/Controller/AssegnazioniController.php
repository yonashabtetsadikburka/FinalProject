<?php
declare(strict_types=1);

namespace App\Controller;

use App\Exception\AppException;
use App\Service\ApiResponse;
use App\Service\AuthService;
use App\Service\DatabaseService;
use App\Service\RipartizioneService;
use App\Service\StatoService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class AssegnazioniController extends AbstractController
{
    public function __construct(private DatabaseService $db, private AuthService $auth, private StatoService $stato, private RipartizioneService $ripartizione) {}

    #[Route('/api/campagne/{id}/ripartisci', methods: ['POST'], requirements: ['id'=>'\d+'])]
    #[Route('/campagne/{id}/ripartisci', methods: ['POST'], requirements: ['id'=>'\d+'])]
    public function ripartisci(int $id): JsonResponse
    {
        $io=$this->auth->richiediLogin(); if(!$this->auth->sonoAdmin()) throw new AppException('NON_AUTORIZZATO','Solo un amministratore puo\' confermare un ordine',403);
        $pdo=$this->db->getPdo(); $pdo->beginTransaction();
        try{
            $st=$pdo->prepare('SELECT id, stato, quantita_attuale, quantita_minima, id_prodotto, percentuale_commissione, prezzo_corrente FROM collette WHERE id = ? FOR UPDATE'); $st->execute([$id]); $c=$st->fetch(); if(!$c) throw new AppException('CAMPAGNA_INESISTENTE','Campagna non trovata',404);
            $stato=$this->stato->ricalcolaStato($id); if($stato!=='riuscita') throw new AppException('CAMPAGNA_NON_RIUSCITA','La campagna deve essere in stato "riuscita" per generare gli ordini. Stato attuale: '.$stato);
            $st=$pdo->prepare('SELECT COUNT(*) FROM prenotazioni WHERE id_colletta = ? AND stato = \'confermata\''); $st->execute([$id]); if((int)$st->fetchColumn()>0){ $pdo->rollBack(); throw new AppException('GIA_RIPARTITA','La ripartizione e\' gia\' stata effettuata',409); }
            $st=$pdo->prepare('SELECT p.id AS id_prenotazione, p.id_utente AS utente_id, p.quantita, p.data_prenotazione AS created_at FROM prenotazioni p WHERE p.id_colletta = ? AND p.stato = \'prenotata\' ORDER BY p.data_prenotazione ASC'); $st->execute([$id]); $richieste=$st->fetchAll(); if(empty($richieste)){ $pdo->rollBack(); throw new AppException('NESSUNA_PARTECIPAZIONE','Nessuna prenotazione attiva per questa campagna'); }
            $pezzi=(int)$c['quantita_attuale']; $assegnazioni=$this->ripartizione->ripartisci($richieste,$pezzi);
            foreach($assegnazioni as $asg){ $st=$pdo->prepare('UPDATE prenotazioni SET quantita = ?, stato = \'confermata\' WHERE id = ?'); $st->execute([$asg['quantita'],$asg['id_prenotazione']]); }
            $st=$pdo->prepare('UPDATE collette SET stato = \'ordine_pronto\', id_admin_conferma = ?, data_conferma = NOW(), data_agg_stato = NOW() WHERE id = ?'); $st->execute([$io,$id]);
            $prezzo_corrente=(float)$c['prezzo_corrente']; $commissione_pct=(float)$c['percentuale_commissione'];
            $stProdotto=$pdo->prepare('SELECT pr.nome FROM prodotti pr WHERE pr.id = ?'); $stProdotto->execute([(int)$c['id_prodotto']]); $nomeProdotto=$stProdotto->fetchColumn()?:'una campagna';
            $stNotifica=$pdo->prepare('INSERT INTO notifiche (id_utente, tipo, titolo, messaggio, tipo_riferimento, id_riferimento) VALUES (?, \'ORDINE_CONFERMATO\', \'Ordine Confermato\', ?, \'colletta\', ?)');
            foreach($assegnazioni as $asg){ $importo_pezzi=round($prezzo_corrente*$asg['quantita'],2); $commissione=round($importo_pezzi*$commissione_pct/100,2); $totale=round($importo_pezzi+$commissione,2); $messaggio="L'ordine per \"$nomeProdotto\" è stato confermato. Procedi al pagamento di EUR ".number_format($totale,2,',','.')."."; $stNotifica->execute([$asg['utente_id'],$messaggio,$id]); }
            $pdo->commit();
            return ApiResponse::ok(['totale_pezzi'=>$pezzi,'assegnazioni'=>count($assegnazioni),'messaggio'=>'Ordine confermato. Gli utenti possono procedere al pagamento.']);
        }catch(\Throwable $e){ $pdo->rollBack(); throw $e; }
    }

    #[Route('/api/campagne/{id}/assegnazioni', methods: ['GET'], requirements: ['id'=>'\d+'])]
    #[Route('/campagne/{id}/assegnazioni', methods: ['GET'], requirements: ['id'=>'\d+'])]
    public function elenco(int $id): JsonResponse
    {
        $this->auth->richiediLogin();
        $st=$this->db->getPdo()->prepare('SELECT qr.id, qr.quantita_assegnata, qr.stato, qr.token, p.id_utente, u.nome, u.cognome FROM qr_codes qr JOIN prenotazioni p ON p.id = qr.id_prenotazione JOIN utenti u ON u.id = p.id_utente WHERE p.id_colletta = ? ORDER BY u.nome');
        $st->execute([$id]);
        $out=array_map(fn($row)=>['id'=>(int)$row['id'],'quantita_assegnata'=>(int)$row['quantita_assegnata'],'stato'=>$row['stato'],'token'=>$row['token'],'id_utente'=>(int)$row['id_utente'],'utente_nome'=>trim($row['nome'].' '.$row['cognome'])],$st->fetchAll());
        return ApiResponse::ok($out);
    }
}
