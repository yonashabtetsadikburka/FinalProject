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

class ProfiloController extends AbstractController
{
    public function __construct(private DatabaseService $db, private AuthService $auth) {}

    #[Route('/api/profilo', methods: ['GET'])]
    #[Route('/profilo', methods: ['GET'])]
    public function leggi(): JsonResponse
    {
        $io=$this->auth->richiediLogin();
        $st=$this->db->getPdo()->prepare('SELECT id, nome, cognome, email, ruolo, stato, tipo, telefono, indirizzo, cap, citta, provincia, partita_iva, codice_fiscale, data_iscrizione FROM utenti WHERE id=?'); $st->execute([$io]);
        return ApiResponse::ok($st->fetch());
    }

    #[Route('/api/profilo', methods: ['PUT'])]
    #[Route('/profilo', methods: ['PUT'])]
    public function aggiorna(Request $request): JsonResponse
    {
        $io=$this->auth->richiediLogin();
        $d=ApiResponse::corpo($request);
        $allowed=['nome','cognome','telefono','indirizzo','cap','citta','provincia','partita_iva','codice_fiscale'];
        $campi=[];$val=[];
        foreach($allowed as $k){
            if(array_key_exists($k,$d)){
                $v=trim((string)$d[$k]);
                if($k==='cap' && mb_strlen($v)>10) throw new AppException('CAP_NON_VALIDO','CAP troppo lungo');
                if($k==='citta' && mb_strlen($v)>100) throw new AppException('CITTA_NON_VALIDA','Città troppo lunga');
                if($k==='provincia' && $v!=='') $v=strtoupper(substr($v,0,2));
                if($k==='partita_iva' && $v!=='' && !preg_match('/^\d{11}$/',$v)) throw new AppException('PIVA_NON_VALIDA','Partita IVA deve essere 11 cifre');
                if($k==='codice_fiscale' && $v!=='' && !preg_match('/^[A-Z0-9]{16}$/i',$v)) throw new AppException('CF_NON_VALIDO','Codice fiscale non valido');
                $campi[]="$k = ?"; $val[]=$v!==''?$v:null;
            }
        }
        if(empty($campi)) throw new AppException('NESSUNA_MODIFICA','Nessun campo da aggiornare');
        $val[]=$io; $this->db->getPdo()->prepare('UPDATE utenti SET '.implode(', ',$campi).' WHERE id=?')->execute($val);
        return ApiResponse::ok(['aggiornato'=>true]);
    }

    #[Route('/api/profilo/password', methods: ['POST'])]
    #[Route('/profilo/password', methods: ['POST'])]
    public function cambiaPassword(Request $request): JsonResponse
    {
        $io=$this->auth->richiediLogin();
        $d=ApiResponse::corpo($request); $vecchia=ApiResponse::campo($d,'vecchia_password'); $nuova=ApiResponse::campo($d,'nuova_password');
        if(strlen($nuova)<8) throw new AppException('PASSWORD_DEBOLE','La nuova password deve avere almeno 8 caratteri');
        $st=$this->db->getPdo()->prepare('SELECT password_hash FROM utenti WHERE id=?'); $st->execute([$io]); $hash=$st->fetchColumn();
        if(!password_verify($vecchia,$hash)) throw new AppException('PASSWORD_ERRATA','Vecchia password non corretta',401);
        $nuovoHash=password_hash($nuova,PASSWORD_DEFAULT);
        $this->db->getPdo()->prepare('UPDATE utenti SET password_hash=? WHERE id=?')->execute([$nuovoHash,$io]);
        return ApiResponse::ok(['aggiornata'=>true]);
    }

    #[Route('/api/profilo/export', methods: ['GET'])]
    #[Route('/profilo/export', methods: ['GET'])]
    public function export(): JsonResponse
    {
        $io=$this->auth->richiediLogin();
        $pdo=$this->db->getPdo();
        $st=$pdo->prepare('SELECT id, nome, cognome, email, ruolo, stato, tipo, telefono, indirizzo, cap, citta, provincia, data_iscrizione FROM utenti WHERE id=?'); $st->execute([$io]); $utente=$st->fetch();
        $st=$pdo->prepare('SELECT p.*, c.stato AS stato_colletta, pr.nome AS prodotto FROM prenotazioni p JOIN collette c ON c.id=p.id_colletta JOIN prodotti pr ON pr.id=c.id_prodotto WHERE p.id_utente=?'); $st->execute([$io]); $pren=$st->fetchAll();
        $st=$pdo->prepare('SELECT pg.* FROM pagamenti pg JOIN prenotazioni p ON p.id=pg.id_prenotazione WHERE p.id_utente=?'); $st->execute([$io]); $pag=$st->fetchAll();
        $st=$pdo->prepare('SELECT * FROM notifiche WHERE id_utente=? ORDER BY data_creazione DESC'); $st->execute([$io]); $not=$st->fetchAll();
        $st=$pdo->prepare('SELECT * FROM proposte_prodotti WHERE proponente_id=?'); $st->execute([$io]); $prop=$st->fetchAll();
        $st=$pdo->prepare('SELECT vp.* FROM voti_proposte vp JOIN proposte_prodotti pp ON pp.id_proposta=vp.id_proposta WHERE vp.id_utente=?'); $st->execute([$io]); $voti=$st->fetchAll();
        return ApiResponse::ok(['utente'=>$utente,'prenotazioni'=>$pren,'pagamenti'=>$pag,'notifiche'=>$not,'proposte'=>$prop,'voti'=>$voti]);
    }

    #[Route('/api/profilo/cancellazione', methods: ['POST'])]
    #[Route('/profilo/cancellazione', methods: ['POST'])]
    public function cancellazione(): JsonResponse
    {
        $io=$this->auth->richiediLogin();
        $pdo=$this->db->getPdo();
        $st=$pdo->prepare('SELECT email, nome, cognome FROM utenti WHERE id=?'); $st->execute([$io]); $u=$st->fetch();
        $email=$u['email']??'';
        $st=$pdo->prepare("SELECT COUNT(*) FROM notifiche WHERE tipo='SISTEMA' AND titolo='Richiesta Cancellazione' AND messaggio LIKE ? AND data_creazione > DATE_SUB(NOW(), INTERVAL 1 DAY)"); $st->execute(['%'.$email.'%']); if((int)$st->fetchColumn()>0) return ApiResponse::ok(['richiesta'=>'già inviata']);
        $stAdm=$pdo->query('SELECT id FROM utenti WHERE ruolo=\'admin\''); $admins=$stAdm->fetchAll(\PDO::FETCH_COLUMN);
        $stN=$pdo->prepare('INSERT INTO notifiche (id_utente, tipo, titolo, messaggio, tipo_riferimento) VALUES (?, \'SISTEMA\', \'Richiesta Cancellazione\', ?, \'sistema\')');
        foreach($admins as $aid) $stN->execute([$aid,"Richiesta cancellazione GDPR da {$u['nome']} {$u['cognome']} ($email)"]);
        return ApiResponse::ok(['richiesta'=>'inviata']);
    }
}
