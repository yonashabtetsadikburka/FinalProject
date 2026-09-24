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

class UtentiController extends AbstractController
{
    public function __construct(private DatabaseService $db, private AuthService $auth) {}

    #[Route('/api/utenti', methods: ['GET'])]
    #[Route('/utenti', methods: ['GET'])]
    public function elenco(): JsonResponse
    {
        if(!$this->auth->sonoAdmin()) throw new AppException('NON_AUTORIZZATO','Solo gli admin',403);
        $st=$this->db->getPdo()->query('SELECT id, nome, cognome, email, ruolo, stato, tipo, data_iscrizione FROM utenti ORDER BY data_iscrizione DESC');
        $out=array_map(fn($u)=>['id'=>(int)$u['id'],'nome'=>$u['nome'],'cognome'=>$u['cognome'],'email'=>$u['email'],'ruolo'=>$u['ruolo'],'stato'=>$u['stato'],'tipo'=>$u['tipo'],'data_iscrizione'=>$u['data_iscrizione']],$st->fetchAll());
        return ApiResponse::ok($out);
    }

    #[Route('/api/utenti/{id}', methods: ['GET'], requirements: ['id'=>'\d+'])]
    #[Route('/utenti/{id}', methods: ['GET'], requirements: ['id'=>'\d+'])]
    public function dettaglio(int $id): JsonResponse
    {
        if(!$this->auth->sonoAdmin()) throw new AppException('NON_AUTORIZZATO','Solo gli admin',403);
        $st=$this->db->getPdo()->prepare('SELECT id, nome, cognome, email, ruolo, stato, tipo, telefono, indirizzo, cap, citta, provincia, partita_iva, codice_fiscale, data_iscrizione FROM utenti WHERE id = ?'); $st->execute([$id]); $u=$st->fetch(); if(!$u) throw new AppException('UTENTE_INESISTENTE','Utente non trovato',404);
        $u['id']=(int)$u['id']; return ApiResponse::ok($u);
    }

    #[Route('/api/utenti/{id}/stato', methods: ['PUT'], requirements: ['id'=>'\d+'])]
    #[Route('/utenti/{id}/stato', methods: ['PUT'], requirements: ['id'=>'\d+'])]
    public function aggiornaStato(int $id, Request $request): JsonResponse
    {
        if(!$this->auth->sonoAdmin()) throw new AppException('NON_AUTORIZZATO','Solo gli admin',403);
        if($id===$this->auth->utenteCorrenteId()) throw new AppException('OPERAZIONE_NON_CONSENTITA','Non puoi modificare il tuo stesso stato',403);
        $d=ApiResponse::corpo($request); $nuovo=ApiResponse::campo($d,'stato'); if(!in_array($nuovo,['attivo','sospeso'],true)) throw new AppException('STATO_NON_VALIDO','Stato non valido');
        $st=$this->db->getPdo()->prepare('UPDATE utenti SET stato = ? WHERE id = ?'); $st->execute([$nuovo,$id]); if($st->rowCount()===0) throw new AppException('UTENTE_INESISTENTE','Utente non trovato',404);
        return ApiResponse::ok(['stato'=>$nuovo]);
    }

    #[Route('/api/utenti/{id}/ruolo', methods: ['PUT'], requirements: ['id'=>'\d+'])]
    #[Route('/utenti/{id}/ruolo', methods: ['PUT'], requirements: ['id'=>'\d+'])]
    public function aggiornaRuolo(int $id, Request $request): JsonResponse
    {
        if(!$this->auth->sonoAdmin()) throw new AppException('NON_AUTORIZZATO','Solo gli admin',403);
        if($id===$this->auth->utenteCorrenteId()) throw new AppException('OPERAZIONE_NON_CONSENTITA','Non puoi modificare il tuo stesso ruolo',403);
        $d=ApiResponse::corpo($request); $nuovo=ApiResponse::campo($d,'ruolo'); if(!in_array($nuovo,['cliente','fornitore','admin'],true)) throw new AppException('RUOLO_NON_VALIDO','Ruolo non valido');
        $st=$this->db->getPdo()->prepare('UPDATE utenti SET ruolo = ? WHERE id = ?'); $st->execute([$nuovo,$id]); if($st->rowCount()===0) throw new AppException('UTENTE_INESISTENTE','Utente non trovato',404);
        return ApiResponse::ok(['ruolo'=>$nuovo]);
    }

    #[Route('/api/utenti/{id}', methods: ['DELETE'], requirements: ['id'=>'\d+'])]
    #[Route('/utenti/{id}', methods: ['DELETE'], requirements: ['id'=>'\d+'])]
    public function elimina(int $id): JsonResponse
    {
        if(!$this->auth->sonoAdmin()) throw new AppException('NON_AUTORIZZATO','Solo gli admin',403);
        if($id===$this->auth->utenteCorrenteId()) throw new AppException('OPERAZIONE_NON_CONSENTITA','Non puoi eliminare il tuo stesso account',403);
        $st=$this->db->getPdo()->prepare('SELECT COUNT(*) FROM prenotazioni WHERE id_utente = ?'); $st->execute([$id]); $nPren=(int)$st->fetchColumn();
        $st=$this->db->getPdo()->prepare('SELECT COUNT(*) FROM pagamenti pg JOIN prenotazioni p ON p.id = pg.id_prenotazione WHERE p.id_utente = ?'); $st->execute([$id]); $nPag=(int)$st->fetchColumn();
        if($nPren>0||$nPag>0) throw new AppException('HA_STORICO',"Impossibile eliminare: l'utente ha $nPren partecipazioni e $nPag pagamenti registrati",409);
        $st=$this->db->getPdo()->prepare('DELETE FROM utenti WHERE id = ?'); $st->execute([$id]); if($st->rowCount()===0) throw new AppException('UTENTE_INESISTENTE','Utente non trovato',404);
        return ApiResponse::ok(['eliminato'=>true]);
    }

    #[Route('/api/utenti/{id}/storico', methods: ['GET'], requirements: ['id'=>'\d+'])]
    #[Route('/utenti/{id}/storico', methods: ['GET'], requirements: ['id'=>'\d+'])]
    public function storico(int $id): JsonResponse
    {
        if(!$this->auth->sonoAdmin()) throw new AppException('NON_AUTORIZZATO','Solo gli admin',403);
        $st=$this->db->getPdo()->prepare('SELECT id FROM utenti WHERE id = ?'); $st->execute([$id]); if(!$st->fetch()) throw new AppException('UTENTE_INESISTENTE','Utente non trovato',404);
        $st=$this->db->getPdo()->prepare('SELECT p.id, p.quantita, p.stato, p.data_prenotazione, p.data_pagamento, c.id AS id_colletta, c.stato AS stato_colletta, pr.nome AS prodotto FROM prenotazioni p JOIN collette c ON c.id = p.id_colletta JOIN prodotti pr ON pr.id = c.id_prodotto WHERE p.id_utente = ? ORDER BY p.data_prenotazione DESC'); $st->execute([$id]); $partecipazioni=array_map(fn($p)=>['id'=>(int)$p['id'],'quantita'=>(int)$p['quantita'],'stato'=>$p['stato'],'data_prenotazione'=>$p['data_prenotazione'],'data_pagamento'=>$p['data_pagamento'],'id_colletta'=>(int)$p['id_colletta'],'stato_colletta'=>$p['stato_colletta'],'prodotto'=>$p['prodotto']],$st->fetchAll());
        $st=$this->db->getPdo()->prepare('SELECT pg.id, pg.tipo_pagamento, pg.importo, pg.stato, pg.data_pagamento, pr.nome AS prodotto, p.id_colletta FROM pagamenti pg JOIN prenotazioni p ON p.id = pg.id_prenotazione JOIN collette c ON c.id = p.id_colletta JOIN prodotti pr ON pr.id = c.id_prodotto WHERE p.id_utente = ? ORDER BY pg.data_pagamento DESC'); $st->execute([$id]); $pagamenti=array_map(fn($pg)=>['id'=>(int)$pg['id'],'tipo_pagamento'=>$pg['tipo_pagamento'],'importo'=>(float)$pg['importo'],'stato'=>$pg['stato'],'data_pagamento'=>$pg['data_pagamento'],'prodotto'=>$pg['prodotto'],'id_colletta'=>(int)$pg['id_colletta']],$st->fetchAll());
        $st=$this->db->getPdo()->prepare('SELECT id, tipo, titolo, messaggio, data_creazione FROM notifiche WHERE id_utente = ? ORDER BY data_creazione DESC LIMIT 20'); $st->execute([$id]); $notifiche=array_map(fn($n)=>['id'=>(int)$n['id'],'tipo'=>$n['tipo'],'titolo'=>$n['titolo'],'messaggio'=>$n['messaggio'],'data_creazione'=>$n['data_creazione']],$st->fetchAll());
        $st=$this->db->getPdo()->prepare('SELECT id_proposta, nome_prodotto, stato, tot_voti, data_proposta FROM proposte_prodotti WHERE proponente_id = ? ORDER BY data_proposta DESC'); $st->execute([$id]); $proposte=array_map(fn($pp)=>['id_proposta'=>(int)$pp['id_proposta'],'nome_prodotto'=>$pp['nome_prodotto'],'stato'=>$pp['stato'],'tot_voti'=>(int)$pp['tot_voti'],'data_proposta'=>$pp['data_proposta']],$st->fetchAll());
        return ApiResponse::ok(['partecipazioni'=>$partecipazioni,'pagamenti'=>$pagamenti,'notifiche'=>$notifiche,'proposte'=>$proposte]);
    }
}
