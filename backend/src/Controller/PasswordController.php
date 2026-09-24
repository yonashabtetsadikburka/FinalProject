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

class PasswordController extends AbstractController
{
    public function __construct(private DatabaseService $db, private AuthService $auth) {}

    #[Route('/api/password/richiesta', methods: ['POST'])]
    #[Route('/password/richiesta', methods: ['POST'])]
    public function richiesta(Request $request): JsonResponse
    {
        $d=ApiResponse::corpo($request); $email=trim((string)($d['email']??''));
        if($email!=='' && filter_var($email,FILTER_VALIDATE_EMAIL)){
            $st=$this->db->getPdo()->prepare('SELECT id FROM utenti WHERE email=?'); $st->execute([$email]);
            if($st->fetch()){
                $stAdm=$this->db->getPdo()->query('SELECT id FROM utenti WHERE ruolo=\'admin\''); $admins=$stAdm->fetchAll(\PDO::FETCH_COLUMN);
                $stN=$this->db->getPdo()->prepare('INSERT INTO notifiche (id_utente, tipo, titolo, messaggio, tipo_riferimento) VALUES (?, \'SISTEMA\', \'Richiesta Reset Password\', ?, \'sistema\')');
                foreach($admins as $aid) $stN->execute([$aid,"Richiesta reset password per $email"]);
            }
        }
        return ApiResponse::ok(['ok'=>true]);
    }

    #[Route('/api/admin/utenti/{id}/reset-link', methods: ['POST'], requirements: ['id'=>'\d+'])]
    #[Route('/admin/utenti/{id}/reset-link', methods: ['POST'], requirements: ['id'=>'\d+'])]
    public function genera(int $id): JsonResponse
    {
        if(!$this->auth->sonoAdmin()) throw new AppException('NON_AUTORIZZATO','Solo gli admin',403);
        $this->db->getPdo()->prepare('UPDATE reset_password SET usato=1 WHERE id_utente=? AND usato=0')->execute([$id]);
        $token=bin2hex(random_bytes(32));
        $this->db->getPdo()->prepare('INSERT INTO reset_password (id_utente, token, scadenza) VALUES (?,?, DATE_ADD(NOW(), INTERVAL 1 DAY))')->execute([$id,$token]);
        $frontend=$this->db->getConfigValue('frontend_url','http://localhost:8000');
        $link=rtrim($frontend,'/').'/index.html#/password/reset?token='.$token;
        return ApiResponse::ok(['token'=>$token,'link'=>$link],201);
    }

    #[Route('/api/password/reset/{token}', methods: ['GET'])]
    #[Route('/password/reset/{token}', methods: ['GET'])]
    public function verifica(string $token): JsonResponse
    {
        $st=$this->db->getPdo()->prepare('SELECT r.scadenza, r.usato, u.email, u.nome FROM reset_password r JOIN utenti u ON u.id=r.id_utente WHERE r.token=?'); $st->execute([$token]); $r=$st->fetch();
        if(!$r) throw new AppException('TOKEN_NON_VALIDO','Token non valido',404);
        if((int)$r['usato']===1 || strtotime($r['scadenza'])<time()) throw new AppException('TOKEN_SCADUTO','Token scaduto o già utilizzato',410);
        return ApiResponse::ok(['email'=>$r['email'],'nome'=>$r['nome']]);
    }

    #[Route('/api/password/reset', methods: ['POST'])]
    #[Route('/password/reset', methods: ['POST'])]
    public function esegui(Request $request): JsonResponse
    {
        $d=ApiResponse::corpo($request); $token=trim((string)($d['token']??'')); $password=(string)($d['password']??'');
        if($token==='') throw new AppException('TOKEN_MANCANTE','Token mancante');
        if(strlen($password)<8) throw new AppException('PASSWORD_DEBOLE','La password deve avere almeno 8 caratteri');
        $pdo=$this->db->getPdo(); $pdo->beginTransaction();
        try{
            $st=$pdo->prepare('SELECT id, id_utente, scadenza, usato FROM reset_password WHERE token=? FOR UPDATE'); $st->execute([$token]); $r=$st->fetch(); if(!$r){ $pdo->rollBack(); throw new AppException('TOKEN_NON_VALIDO','Token non valido',404); }
            if((int)$r['usato']===1 || strtotime($r['scadenza'])<time()){ $pdo->rollBack(); throw new AppException('TOKEN_SCADUTO','Token scaduto o già utilizzato',410); }
            $hash=password_hash($password,PASSWORD_DEFAULT);
            $pdo->prepare('UPDATE utenti SET password_hash=? WHERE id=?')->execute([$hash,$r['id_utente']]);
            $pdo->prepare('UPDATE reset_password SET usato=1 WHERE id=?')->execute([$r['id']]);
            $pdo->prepare('INSERT INTO notifiche (id_utente, tipo, titolo, messaggio) VALUES (?, \'SISTEMA\', \'Password Reimpostata\', \'La tua password è stata reimpostata con successo\')')->execute([$r['id_utente']]);
            $pdo->commit();
            return ApiResponse::ok(['reimpostata'=>true]);
        }catch(\Throwable $e){ if($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }
}
