<?php
declare(strict_types=1);

namespace App\Controller;

use App\Exception\AppException;
use App\Service\ApiResponse;
use App\Service\AuthService;
use App\Service\DatabaseService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class ConsegneController extends AbstractController
{
    public function __construct(private DatabaseService $db, private AuthService $auth) {}

    #[Route('/api/consegne/costo', methods: ['GET'])]
    #[Route('/consegne/costo', methods: ['GET'])]
    public function costo(): JsonResponse
    {
        $this->auth->richiediLogin();
        $costo=$this->db->getConfigValue('costo_spedizione', 4.90);
        return ApiResponse::ok(['costo_spedizione'=>(float)$costo]);
    }

    #[Route('/api/consegne/scelta', methods: ['POST'])]
    #[Route('/consegne/scelta', methods: ['POST'])]
    public function scelta(Request $request): JsonResponse
    {
        $io=$this->auth->richiediUtenteAttivo();
        $d=ApiResponse::corpo($request); $pid=ApiResponse::campoInt($d,'prenotazione_id'); $modalita=trim((string)($d['modalita']??'')); if(!in_array($modalita,['ritiro_sede','consegna_domicilio'],true)) throw new AppException('MODALITA_NON_VALIDA','Modalità non valida');
        $pdo=$this->db->getPdo(); $pdo->beginTransaction();
        try{
            $st=$pdo->prepare('SELECT id, stato FROM prenotazioni WHERE id=? AND id_utente=? FOR UPDATE'); $st->execute([$pid,$io]); $p=$st->fetch(); if(!$p) { $pdo->rollBack(); throw new AppException('PRENOTAZIONE_NON_TROVATA','Prenotazione non trovata',404); }
            if($p['stato']!=='confermata'){ $pdo->rollBack(); throw new AppException('SCELTA_NON_CONSENTITA','Scelta consegna consentita solo in stato confermata'); }
            if($modalita==='consegna_domicilio'){
                $stU=$pdo->prepare('SELECT indirizzo, cap, citta, provincia FROM utenti WHERE id=?'); $stU->execute([$io]); $u=$stU->fetch();
                if(empty($u['indirizzo'])||empty($u['cap'])||empty($u['citta'])){ $pdo->rollBack(); throw new AppException('INDIRIZZO_MANCANTE','Completa indirizzo, cap e città nel profilo prima di scegliere la spedizione',403); }
                $indirizzo=trim($u['indirizzo']).', '.$u['cap'].' '.$u['citta'].($u['provincia']?' ('.$u['provincia'].')':'');
                $importo=(float)$this->db->getConfigValue('costo_spedizione',4.90);
            } else { $indirizzo=null; $importo=0.0; }
            $pdo->prepare('INSERT INTO consegne (id_prenotazione, modalita, indirizzo_consegna, importo_consegna, stato) VALUES (?,?,?,?, \'in_attesa\') ON DUPLICATE KEY UPDATE modalita=VALUES(modalita), indirizzo_consegna=VALUES(indirizzo_consegna), importo_consegna=VALUES(importo_consegna)')->execute([$pid,$modalita,$indirizzo,$importo]);
            $pdo->commit(); return ApiResponse::ok(['modalita'=>$modalita,'importo_consegna'=>$importo]);
        }catch(\Throwable $e){ if($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }

    #[Route('/api/admin/consegne', methods: ['GET'])]
    #[Route('/admin/consegne', methods: ['GET'])]
    public function adminElenco(): JsonResponse
    {
        if(!$this->auth->sonoAdmin()) throw new AppException('NON_AUTORIZZATO','Solo gli admin',403);
        $st=$this->db->getPdo()->query('SELECT co.*, p.id_utente, u.nome, u.cognome, u.email, c.id AS id_colletta, pr.nome AS prodotto FROM consegne co JOIN prenotazioni p ON p.id=co.id_prenotazione JOIN utenti u ON u.id=p.id_utente JOIN collette c ON c.id=p.id_colletta JOIN prodotti pr ON pr.id=c.id_prodotto ORDER BY co.id DESC');
        return ApiResponse::ok($st->fetchAll());
    }

    #[Route('/api/consegne/{id}/spedisci', methods: ['POST'], requirements: ['id'=>'\d+'])]
    #[Route('/consegne/{id}/spedisci', methods: ['POST'], requirements: ['id'=>'\d+'])]
    public function spedisci(int $id): JsonResponse
    {
        if(!$this->auth->sonoAdmin()) throw new AppException('NON_AUTORIZZATO','Solo gli admin',403);
        $pdo=$this->db->getPdo(); $pdo->beginTransaction();
        try{
            $st=$pdo->prepare('SELECT co.*, p.id_colletta FROM consegne co JOIN prenotazioni p ON p.id=co.id_prenotazione WHERE co.id=? FOR UPDATE'); $st->execute([$id]); $co=$st->fetch(); if(!$co){ $pdo->rollBack(); throw new AppException('CONSEGNA_INESISTENTE','Consegna non trovata',404); }
            if($co['modalita']!=='consegna_domicilio'){ $pdo->rollBack(); throw new AppException('MODALITA_NON_VALIDA','Solo consegne a domicilio possono essere spedite'); }
            if(in_array($co['stato'],['spedita','consegnata'],true)){ $pdo->rollBack(); throw new AppException('GIA_SPEDITA','Consegna già spedita'); }
            $st=$pdo->prepare('SELECT stato FROM ordini_fornitore WHERE id_colletta=?'); $st->execute([$co['id_colletta']]); $statoOrd=$st->fetchColumn();
            if(!in_array($statoOrd,['evaso','consegnato'],true)){ $pdo->rollBack(); throw new AppException('SPEDIZIONE_BLOCCATA','Ordine fornitore non ancora evaso'); }
            $pdo->prepare('UPDATE consegne SET stato=\'spedita\', data_effettiva=NOW() WHERE id=?')->execute([$id]);
            $pdo->prepare('INSERT INTO notifiche (id_utente, tipo, titolo, messaggio, tipo_riferimento, id_riferimento) VALUES ((SELECT id_utente FROM prenotazioni WHERE id=?), \'SPEDITO\', \'Ordine Spedito\', \'Il tuo ordine è stato spedito!\', \'consegna\', ?)')->execute([$co['id_prenotazione'],$id]);
            $pdo->commit(); return ApiResponse::ok(['spedita'=>true]);
        }catch(\Throwable $e){ if($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }

    #[Route('/api/consegne/{id}/conferma-ricezione', methods: ['POST'], requirements: ['id'=>'\d+'])]
    #[Route('/consegne/{id}/conferma-ricezione', methods: ['POST'], requirements: ['id'=>'\d+'])]
    public function conferma(int $id): JsonResponse
    {
        $io=$this->auth->richiediLogin();
        $pdo=$this->db->getPdo(); $pdo->beginTransaction();
        try{
            $st=$pdo->prepare('SELECT co.*, p.id_utente FROM consegne co JOIN prenotazioni p ON p.id=co.id_prenotazione WHERE co.id=? FOR UPDATE'); $st->execute([$id]); $co=$st->fetch(); if(!$co){ $pdo->rollBack(); throw new AppException('CONSEGNA_INESISTENTE','Consegna non trovata',404); }
            if((int)$co['id_utente']!==$io) { $pdo->rollBack(); throw new AppException('NON_AUTORIZZATO','Non autorizzato',403); }
            if($co['modalita']!=='consegna_domicilio'){ $pdo->rollBack(); throw new AppException('MODALITA_NON_VALIDA','Solo consegne a domicilio'); }
            if($co['stato']!=='spedita'){ $pdo->rollBack(); throw new AppException('STATO_NON_VALIDO','Consegna non spedita',409); }
            $pdo->prepare('UPDATE consegne SET stato=\'consegnata\', data_consegna=NOW() WHERE id=?')->execute([$id]);
            $pdo->prepare('INSERT INTO notifiche (id_utente, tipo, titolo, messaggio, tipo_riferimento, id_riferimento) VALUES (?, \'ORDINE_RICEVUTO\', \'Ordine Ricevuto\', \'Hai confermato la ricezione dell\'ordine\', \'consegna\', ?)')->execute([$io,$id]);
            $pdo->commit(); return ApiResponse::ok(['consegnata'=>true]);
        }catch(\Throwable $e){ if($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }
}
