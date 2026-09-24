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

class RecensioniController extends AbstractController
{
    public function __construct(private DatabaseService $db, private AuthService $auth) {}

    #[Route('/api/fornitori/{id}/recensioni', methods: ['GET'], requirements: ['id'=>'\d+'])]
    #[Route('/fornitori/{id}/recensioni', methods: ['GET'], requirements: ['id'=>'\d+'])]
    public function elencoFornitore(int $id): JsonResponse
    {
        $this->auth->richiediLogin();
        $io=$this->auth->utenteCorrenteId();
        $st=$this->db->getPdo()->prepare('SELECT r.*, u.nome, u.cognome FROM recensioni_fornitore r JOIN utenti u ON u.id=r.id_utente WHERE r.id_fornitore=? ORDER BY r.data_recensione DESC'); $st->execute([$id]); $rows=$st->fetchAll();
        $st2=$this->db->getPdo()->prepare('SELECT AVG(voto) AS media, COUNT(*) AS tot FROM recensioni_fornitore WHERE id_fornitore=?'); $st2->execute([$id]); $agg=$st2->fetch();
        $media=$agg && $agg['tot']>0?round((float)$agg['media'],1):null; $tot=(int)($agg['tot']??0);
        $mia=null; foreach($rows as $r) if((int)$r['id_utente']===$io) $mia=$r;
        $stPaid=$this->db->getPdo()->prepare('SELECT COUNT(*) FROM prenotazioni p JOIN collette c ON c.id=p.id_colletta JOIN prodotti pr ON pr.id=c.id_prodotto WHERE p.id_utente=? AND pr.id_fornitore=? AND p.stato=\'pagata\''); $stPaid->execute([$io,$id]); $puo=(int)$stPaid->fetchColumn()>0;
        return ApiResponse::ok(['recensioni'=>$rows,'media'=>$media,'totale'=>$tot,'mia'=>$mia,'puo_recensire'=>$puo]);
    }

    #[Route('/api/fornitori/{id}/recensioni', methods: ['POST'], requirements: ['id'=>'\d+'])]
    #[Route('/fornitori/{id}/recensioni', methods: ['POST'], requirements: ['id'=>'\d+'])]
    public function salvaFornitore(int $id, Request $request): JsonResponse
    {
        $io=$this->auth->richiediUtenteAttivo();
        $d=ApiResponse::corpo($request); $voto=ApiResponse::campoInt($d,'voto',1); if($voto<1||$voto>5) throw new AppException('VOTO_NON_VALIDO','Voto deve essere 1-5');
        $testo=trim((string)($d['testo']??$d['commento']??'')); if(mb_strlen($testo)>1000) throw new AppException('TESTO_TROPPO_LUNGO','Massimo 1000 caratteri');
        $stPaid=$this->db->getPdo()->prepare('SELECT COUNT(*) FROM prenotazioni p JOIN collette c ON c.id=p.id_colletta JOIN prodotti pr ON pr.id=c.id_prodotto WHERE p.id_utente=? AND pr.id_fornitore=? AND p.stato=\'pagata\''); $stPaid->execute([$io,$id]); if((int)$stPaid->fetchColumn()===0) throw new AppException('NON_AUTORIZZATO','Devi aver acquistato da questo fornitore per recensire',403);
        $st=$this->db->getPdo()->prepare('INSERT INTO recensioni_fornitore (id_fornitore, id_utente, voto, testo) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE voto=VALUES(voto), testo=VALUES(testo), data_recensione=NOW()'); $st->execute([$id,$io,$voto,$testo]);
        return ApiResponse::ok(['salvata'=>true]);
    }

    #[Route('/api/fornitori/recensioni/{id}', methods: ['DELETE'], requirements: ['id'=>'\d+'])]
    #[Route('/fornitori/recensioni/{id}', methods: ['DELETE'], requirements: ['id'=>'\d+'])]
    public function eliminaFornitore(int $id): JsonResponse
    {
        $io=$this->auth->richiediLogin();
        $st=$this->db->getPdo()->prepare('SELECT id_utente FROM recensioni_fornitore WHERE id=?'); $st->execute([$id]); $r=$st->fetch(); if(!$r) throw new AppException('RECENSIONE_INESISTENTE','Recensione non trovata',404);
        if((int)$r['id_utente']!==$io && !$this->auth->sonoAdmin()) throw new AppException('NON_AUTORIZZATO','Non autorizzato',403);
        $this->db->getPdo()->prepare('DELETE FROM recensioni_fornitore WHERE id=?')->execute([$id]);
        return ApiResponse::ok(['eliminata'=>true]);
    }

    #[Route('/api/campagne/{id}/recensioni', methods: ['GET'], requirements: ['id'=>'\d+'])]
    #[Route('/campagne/{id}/recensioni', methods: ['GET'], requirements: ['id'=>'\d+'])]
    public function elencoCampagna(int $id): JsonResponse
    {
        $this->auth->richiediLogin(); $io=$this->auth->utenteCorrenteId();
        $st=$this->db->getPdo()->prepare('SELECT r.*, u.nome, u.cognome FROM recensioni_campagna r JOIN utenti u ON u.id=r.id_utente WHERE r.id_colletta=? ORDER BY r.data_recensione DESC'); $st->execute([$id]); $rows=$st->fetchAll();
        $st2=$this->db->getPdo()->prepare('SELECT AVG(voto) AS media, COUNT(*) AS tot FROM recensioni_campagna WHERE id_colletta=?'); $st2->execute([$id]); $agg=$st2->fetch();
        $media=$agg && $agg['tot']>0?round((float)$agg['media'],1):null; $tot=(int)($agg['tot']??0);
        $mia=null; foreach($rows as $r) if((int)$r['id_utente']===$io) $mia=$r;
        // eligibility: pagata AND (consegna consegnata/ritirata OR qr scansionato)
        $stE=$this->db->getPdo()->prepare('SELECT COUNT(*) FROM prenotazioni p LEFT JOIN consegne co ON co.id_prenotazione=p.id LEFT JOIN qr_codes qr ON qr.id_prenotazione=p.id WHERE p.id_utente=? AND p.id_colletta=? AND p.stato=\'pagata\' AND (co.stato IN (\'consegnata\',\'ritirata\') OR qr.stato=\'scansionato\')'); $stE->execute([$io,$id]); $puo=(int)$stE->fetchColumn()>0;
        return ApiResponse::ok(['recensioni'=>$rows,'media'=>$media,'totale'=>$tot,'mia'=>$mia,'puo_recensire'=>$puo]);
    }

    #[Route('/api/campagne/{id}/recensioni', methods: ['POST'], requirements: ['id'=>'\d+'])]
    #[Route('/campagne/{id}/recensioni', methods: ['POST'], requirements: ['id'=>'\d+'])]
    public function salvaCampagna(int $id, Request $request): JsonResponse
    {
        $io=$this->auth->richiediUtenteAttivo();
        $d=ApiResponse::corpo($request); $voto=ApiResponse::campoInt($d,'voto',1); if($voto<1||$voto>5) throw new AppException('VOTO_NON_VALIDO','Voto deve essere 1-5');
        $testo=trim((string)($d['testo']??$d['commento']??'')); if(mb_strlen($testo)>1000) throw new AppException('TESTO_TROPPO_LUNGO','Massimo 1000 caratteri');
        $stE=$this->db->getPdo()->prepare('SELECT COUNT(*) FROM prenotazioni p LEFT JOIN consegne co ON co.id_prenotazione=p.id LEFT JOIN qr_codes qr ON qr.id_prenotazione=p.id WHERE p.id_utente=? AND p.id_colletta=? AND p.stato=\'pagata\' AND (co.stato IN (\'consegnata\',\'ritirata\') OR qr.stato=\'scansionato\')'); $stE->execute([$io,$id]); if((int)$stE->fetchColumn()===0) throw new AppException('NON_AUTORIZZATO','Devi aver ricevuto la merce per recensire',403);
        $st=$this->db->getPdo()->prepare('INSERT INTO recensioni_campagna (id_colletta, id_utente, voto, testo) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE voto=VALUES(voto), testo=VALUES(testo), data_recensione=NOW()'); $st->execute([$id,$io,$voto,$testo]);
        return ApiResponse::ok(['salvata'=>true]);
    }

    #[Route('/api/campagne/recensioni/{id}', methods: ['DELETE'], requirements: ['id'=>'\d+'])]
    #[Route('/campagne/recensioni/{id}', methods: ['DELETE'], requirements: ['id'=>'\d+'])]
    public function eliminaCampagna(int $id): JsonResponse
    {
        $io=$this->auth->richiediLogin();
        $st=$this->db->getPdo()->prepare('SELECT id_utente FROM recensioni_campagna WHERE id=?'); $st->execute([$id]); $r=$st->fetch(); if(!$r) throw new AppException('RECENSIONE_INESISTENTE','Recensione non trovata',404);
        if((int)$r['id_utente']!==$io && !$this->auth->sonoAdmin()) throw new AppException('NON_AUTORIZZATO','Non autorizzato',403);
        $this->db->getPdo()->prepare('DELETE FROM recensioni_campagna WHERE id=?')->execute([$id]);
        return ApiResponse::ok(['eliminata'=>true]);
    }
}
