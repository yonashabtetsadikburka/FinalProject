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

class NotificheController extends AbstractController
{
    public function __construct(private DatabaseService $db, private AuthService $auth) {}

    #[Route('/api/notifiche', methods: ['GET'])]
    #[Route('/notifiche', methods: ['GET'])]
    public function elenco(): JsonResponse
    {
        $io=$this->auth->richiediLogin();
        $st=$this->db->getPdo()->prepare('SELECT id, tipo, titolo, messaggio, tipo_riferimento, id_riferimento, letta, data_creazione FROM notifiche WHERE id_utente = ? ORDER BY data_creazione DESC LIMIT 50');
        $st->execute([$io]);
        $out=array_map(fn($n)=>['id'=>(int)$n['id'],'tipo'=>$n['tipo'],'titolo'=>$n['titolo'],'messaggio'=>$n['messaggio'],'tipo_riferimento'=>$n['tipo_riferimento'],'id_riferimento'=>$n['id_riferimento']!==null?(int)$n['id_riferimento']:null,'letta'=>(bool)$n['letta'],'data_creazione'=>$n['data_creazione']],$st->fetchAll());
        return ApiResponse::ok($out);
    }

    #[Route('/api/notifiche/conta', methods: ['GET'])]
    #[Route('/notifiche/conta', methods: ['GET'])]
    public function conta(): JsonResponse
    {
        $io=$this->auth->richiediLogin();
        $st=$this->db->getPdo()->prepare('SELECT COUNT(*) FROM notifiche WHERE id_utente = ? AND letta = 0'); $st->execute([$io]);
        return ApiResponse::ok(['non_lette'=>(int)$st->fetchColumn()]);
    }

    #[Route('/api/notifiche/{id}/letta', methods: ['PUT'], requirements: ['id'=>'\d+'])]
    #[Route('/notifiche/{id}/letta', methods: ['PUT'], requirements: ['id'=>'\d+'])]
    public function segnaLetta(int $id): JsonResponse
    {
        $io=$this->auth->richiediLogin();
        $st=$this->db->getPdo()->prepare('UPDATE notifiche SET letta = 1, data_lettura = NOW() WHERE id = ? AND id_utente = ?'); $st->execute([$id,$io]);
        if($st->rowCount()===0) throw new AppException('NOTIFICA_INESISTENTE','Notifica non trovata',404);
        return ApiResponse::ok(['letta'=>true]);
    }

    #[Route('/api/notifiche/marca-tutte-lette', methods: ['POST'])]
    #[Route('/notifiche/marca-tutte-lette', methods: ['POST'])]
    public function segnaTutte(): JsonResponse
    {
        $io=$this->auth->richiediLogin();
        $st=$this->db->getPdo()->prepare('UPDATE notifiche SET letta = 1, data_lettura = NOW() WHERE id_utente = ? AND letta = 0'); $st->execute([$io]);
        return ApiResponse::ok(['aggiornate'=>$st->rowCount()]);
    }

    #[Route('/api/notifiche', methods: ['POST'])]
    #[Route('/notifiche', methods: ['POST'])]
    public function invia(Request $request): JsonResponse
    {
        if(!$this->auth->sonoAdmin()) throw new AppException('NON_AUTORIZZATO','Solo gli admin possono inviare notifiche',403);
        $d=ApiResponse::corpo($request);
        $id_utente=ApiResponse::campoInt($d,'id_utente'); $tipo=ApiResponse::campo($d,'tipo'); $titolo=ApiResponse::campo($d,'titolo'); $messaggio=ApiResponse::campo($d,'messaggio');
        $tipo_rif=$d['tipo_riferimento']??null; $id_rif=$d['id_riferimento']??null;
        $tipi_validi=['SCADENZA','ORDINE_DISPONIBILE','ORDINE_INVIATO','RITIRATO','PROPOSTA_APPROVATA','PAGAMENTO_RIUSCITO','PAGAMENTO_RICEVUTO','PAGAMENTO_FALLITO','RIMBORSO','MOQ_RAGGIUNTO','ORDINE_CONFERMATO','MERCE_PRONTA','SPEDITO','NUOVO_SCAGLIONE','TRACCIAMENTO','SISTEMA','ORDINE_RICEVUTO'];
        if(!in_array($tipo,$tipi_validi,true)) throw new AppException('TIPO_NON_VALIDO','Tipo notifica non valido');
        $st=$this->db->getPdo()->prepare('INSERT INTO notifiche (id_utente, tipo, titolo, messaggio, tipo_riferimento, id_riferimento) VALUES (?, ?, ?, ?, ?, ?)'); $st->execute([$id_utente,$tipo,$titolo,$messaggio,$tipo_rif,$id_rif]);
        return ApiResponse::ok(['id'=>(int)$this->db->getPdo()->lastInsertId()],201);
    }
}
