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

class PagamentoController extends AbstractController
{
    public function __construct(private DatabaseService $db, private AuthService $auth, private StatoService $stato) {}

    #[Route('/api/pagamento/checkout', methods: ['POST'])]
    #[Route('/pagamento/checkout', methods: ['POST'])]
    public function checkout(Request $request): JsonResponse
    {
        $io=$this->auth->richiediUtenteAttivo();
        $d=ApiResponse::corpo($request);
        $prenotazione_id=ApiResponse::campoInt($d,'prenotazione_id');
        $st=$this->db->getPdo()->prepare('SELECT p.id, p.id_colletta, p.quantita, p.stato, c.prezzo_corrente, c.percentuale_commissione, pr.nome AS prodotto_nome FROM prenotazioni p JOIN collette c ON c.id = p.id_colletta JOIN prodotti pr ON pr.id = c.id_prodotto WHERE p.id = ? AND p.id_utente = ?');
        $st->execute([$prenotazione_id,$io]); $pren=$st->fetch(); if(!$pren) throw new AppException('PRENOTAZIONE_NON_TROVATA','Prenotazione non trovata',404);
        if($pren['stato']!=='confermata') throw new AppException('STATO_NON_VALIDO','Pagamento disponibile solo dopo la conferma dell\'ordine. Stato attuale: '.$pren['stato']);
        $prezzo_unitario=(float)$pren['prezzo_corrente']; $commesso_pct=(float)$pren['percentuale_commissione']; $quantita=(int)$pren['quantita'];
        $importo_pezzi=round($prezzo_unitario*$quantita,2); $commissione=round($importo_pezzi*$commesso_pct/100,2); $totale=round($importo_pezzi+$commissione,2);
        $st=$this->db->getPdo()->prepare('SELECT modalita, importo_consegna FROM consegne WHERE id_prenotazione = ?'); $st->execute([$prenotazione_id]); $consegna=$st->fetch();
        $spedizione=($consegna && $consegna['modalita']==='consegna_domicilio')?round((float)$consegna['importo_consegna'],2):0.0; $totale=round($totale+$spedizione,2);
        $cfgStripe=$this->db->getConfigValue('stripe_secret_key'); $cfgFrontend=$this->db->getConfigValue('frontend_url','http://localhost:8000');
        if(!$cfgStripe) throw new AppException('CONFIG_MANCANTE','Stripe non configurato',500);
        $line_items=[['price_data'=>['currency'=>'eur','product_data'=>['name'=>$pren['prodotto_nome'].' — '.$quantita.' pezzi','description'=>'Acquisto collettivo campagna #'.$pren['id_colletta']],'unit_amount'=>(int)round(($importo_pezzi+$commissione)*100)],'quantity'=>1]];
        if($spedizione>0) $line_items[]=['price_data'=>['currency'=>'eur','product_data'=>['name'=>'Spedizione a domicilio','description'=>'Consegna all\'indirizzo del profilo'],'unit_amount'=>(int)round($spedizione*100)],'quantity'=>1];
        $stripe=new \Stripe\StripeClient($cfgStripe);
        try{
            $session=$stripe->checkout->sessions->create(['payment_method_types'=>['card'],'customer_email'=>$this->auth->utenteEmail(),'line_items'=>$line_items,'mode'=>'payment','success_url'=>$cfgFrontend.'/app.html#/pagamento/successo?session_id={CHECKOUT_SESSION_ID}','cancel_url'=>$cfgFrontend.'/app.html#/pagamento/annullato','metadata'=>['prenotazione_id'=>$prenotazione_id,'colletta_id'=>$pren['id_colletta'],'utente_id'=>$io]]);
            $this->db->getPdo()->prepare('UPDATE prenotazioni SET importo_saldo = ? WHERE id = ?')->execute([$totale,$prenotazione_id]);
            return ApiResponse::ok(['checkout_url'=>$session->url,'session_id'=>$session->id,'importo'=>$totale]);
        }catch(\Stripe\Exception\ApiErrorException $e){ throw new AppException('STRIPE_ERRORE','Errore Stripe: '.$e->getMessage(),500); }
    }

    #[Route('/api/pagamento/webhook', methods: ['POST'])]
    #[Route('/pagamento/webhook', methods: ['POST'])]
    public function webhook(Request $request): JsonResponse
    {
        $cfgSecret=$this->db->getConfigValue('stripe_webhook_secret');
        $payload=$request->getContent(); $sig=$request->headers->get('Stripe-Signature') ?? $request->headers->get('HTTP_STRIPE_SIGNATURE') ?? $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
        if(empty($sig)) return new JsonResponse(['error'=>'Missing signature'],400);
        try{ $event=\Stripe\Webhook::constructEvent($payload,$sig,$cfgSecret); }catch(\UnexpectedValueException $e){ return new JsonResponse(['error'=>'Invalid payload'],400); }catch(\Stripe\Exception\SignatureVerificationException $e){ return new JsonResponse(['error'=>'Invalid signature'],400); }
        $pdo=$this->db->getPdo();
        $st=$pdo->prepare('SELECT id, elaborato FROM eventi_stripe WHERE stripe_event_id = ?'); $st->execute([$event->id]); $esistente=$st->fetch();
        if($esistente && $esistente['elaborato']) return new JsonResponse(['received'=>true,'duplicate'=>true],200);
        if($esistente){ $pdo->prepare('UPDATE eventi_stripe SET elaborato = FALSE WHERE id = ?')->execute([$esistente['id']]); $evento_id=(int)$esistente['id']; } else { $pdo->prepare('INSERT INTO eventi_stripe (stripe_event_id, tipo_evento, payload_json) VALUES (?, ?, ?)')->execute([$event->id,$event->type,$payload]); $evento_id=(int)$pdo->lastInsertId(); }
        if($event->type==='checkout.session.completed'){
            $session=$event->data->object; $prenotazione_id=(int)($session->metadata->prenotazione_id ?? 0); $colletta_id=(int)($session->metadata->colletta_id ?? 0); $utente_id=(int)($session->metadata->utente_id ?? 0);
            if($prenotazione_id<=0){ $pdo->prepare('UPDATE eventi_stripe SET elaborato = TRUE WHERE id = ?')->execute([$evento_id]); return new JsonResponse(['received'=>true],200); }
            $pdo->beginTransaction();
            try{
                $st=$pdo->prepare('SELECT p.id, p.stato, p.importo_saldo, p.quantita, c.percentuale_commissione FROM prenotazioni p JOIN collette c ON c.id = p.id_colletta WHERE p.id = ? FOR UPDATE'); $st->execute([$prenotazione_id]); $pren=$st->fetch();
                if(!$pren || $pren['stato']==='rimborsata'){ $pdo->rollBack(); $pdo->prepare('UPDATE eventi_stripe SET elaborato = TRUE WHERE id = ?')->execute([$evento_id]); return new JsonResponse(['received'=>true],200); }
                if($pren['stato']==='pagata'){ $pdo->rollBack(); $pdo->prepare('UPDATE eventi_stripe SET elaborato = TRUE WHERE id = ?')->execute([$evento_id]); return new JsonResponse(['received'=>true],200); }
                $importo=(float)($pren['importo_saldo']??0); $commesso_pct=(float)$pren['percentuale_commissione']; $commissione=round($importo*$commesso_pct/100,2);
                $stripe_payment_intent_id=$session->payment_intent ?? null;
                $pdo->prepare('INSERT INTO pagamenti (id_prenotazione, tipo_pagamento, importo, commissione_agenzia, stripe_payment_intent_id, stripe_charge_id, stripe_payment_method, stato_stripe, stato, data_pagamento, data_conferma) VALUES (?, \'saldo\', ?, ?, ?, ?, ?, ?, \'confermato\', NOW(), NOW())')->execute([$prenotazione_id,$importo,$commissione,$stripe_payment_intent_id,null,null,$session->payment_status ?? 'paid']);
                $stCons=$pdo->prepare('SELECT modalita FROM consegne WHERE id_prenotazione = ?'); $stCons->execute([$prenotazione_id]); $modalita=$stCons->fetchColumn()?:'ritiro_sede';
                if($modalita!=='consegna_domicilio'){ $token=bin2hex(random_bytes(24)); $pdo->prepare('INSERT INTO qr_codes (id_prenotazione, token, quantita_assegnata, stato) VALUES (?, ?, ?, \'generato\')')->execute([$prenotazione_id,$token,$pren['quantita']]); }
                $pdo->prepare('UPDATE prenotazioni SET stato = \'pagata\', data_pagamento = NOW(), importo_commissione = ? WHERE id = ?')->execute([$commissione,$prenotazione_id]);
                $stProg=$pdo->prepare("SELECT COUNT(*) AS totale, SUM(CASE WHEN stato = 'pagata' THEN 1 ELSE 0 END) AS pagati FROM prenotazioni WHERE id_colletta = ?"); $stProg->execute([$colletta_id]); $prog=$stProg->fetch(); $pagati=(int)($prog['pagati']??0); $totale=(int)($prog['totale']??0); $progresso="$pagati/$totale pagati";
                $payment_id=(int)$pdo->lastInsertId(); $pdo->prepare('UPDATE eventi_stripe SET id_pagamento = ? WHERE id = ?')->execute([$payment_id,$evento_id]);
                $messaggioUtente=$modalita==='consegna_domicilio'?"Pagamento di EUR ".number_format($importo,2,',','.')." ricevuto con successo. Ti avviseremo quando il tuo ordine verrà spedito. ($progresso)":"Pagamento di EUR ".number_format($importo,2,',','.')." ricevuto con successo. ($progresso)";
                $pdo->prepare('INSERT INTO notifiche (id_utente, tipo, titolo, messaggio, tipo_riferimento, id_riferimento) VALUES (?, \'PAGAMENTO_RIUSCITO\', \'Pagamento Ricevuto\', ?, \'prenotazione\', ?)')->execute([$utente_id,$messaggioUtente,$prenotazione_id]);
                try{ $stAdmin=$pdo->prepare('SELECT id FROM utenti WHERE ruolo = \'admin\''); $stAdmin->execute(); $adminIds=$stAdmin->fetchAll(\PDO::FETCH_COLUMN); $stNomeUtente=$pdo->prepare('SELECT nome, cognome FROM utenti WHERE id = ?'); $stNomeUtente->execute([$utente_id]); $utente=$stNomeUtente->fetch(); $nomeUtente=$utente?trim($utente['nome'].' '.$utente['cognome']):"Utente #$utente_id"; $stNomeProdotto=$pdo->prepare('SELECT pr.nome FROM prenotazioni p JOIN collette c ON c.id = p.id_colletta JOIN prodotti pr ON pr.id = c.id_prodotto WHERE p.id = ?'); $stNomeProdotto->execute([$prenotazione_id]); $nomeProdotto=$stNomeProdotto->fetchColumn()?:'un prodotto'; $stNotificaAdmin=$pdo->prepare('INSERT INTO notifiche (id_utente, tipo, titolo, messaggio, tipo_riferimento, id_riferimento) VALUES (?, \'PAGAMENTO_RICEVUTO\', \'Pagamento Ricevuto\', ?, \'colletta\', ?)'); foreach($adminIds as $adminId){ $messaggioAdmin="Pagamento di EUR ".number_format($importo,2,',','.')." ricevuto da $nomeUtente per $nomeProdotto. Commissione: EUR ".number_format($commissione,2,',','.').". ($progresso)"; $stNotificaAdmin->execute([$adminId,$messaggioAdmin,$colletta_id]); } }catch(\Throwable $eNotify){ error_log("Stripe webhook: notifica admin fallita per prenotazione #$prenotazione_id: ".$eNotify->getMessage()); }
                $nuovoStato=$this->stato->ricalcolaStato($colletta_id);
                if($nuovoStato==='ordine_pronto' && $pagati>0 && $pagati===$totale){ try{ $this->inviaOrdineFornitoreAutomatico($colletta_id); }catch(\Throwable $eAuto){ error_log("Stripe webhook: invio automatico colletta #$colletta_id fallito: ".$eAuto->getMessage()); } }
                $pdo->prepare('UPDATE eventi_stripe SET elaborato = TRUE WHERE id = ?')->execute([$evento_id]); $pdo->commit(); error_log("Stripe webhook: pagamento confermato per prenotazione #$prenotazione_id");
            }catch(\Throwable $e){ $pdo->rollBack(); $pdo->prepare('UPDATE eventi_stripe SET elaborato = FALSE WHERE id = ?')->execute([$evento_id]); error_log("Stripe webhook errore: ".$e->getMessage()); throw $e; }
        }
        return new JsonResponse(['received'=>true],200);
    }

    private function inviaOrdineFornitoreAutomatico(int $colletta_id): void
    {
        $pdo=$this->db->getPdo();
        $own=!$pdo->inTransaction(); if($own) $pdo->beginTransaction();
        try{
            $st=$pdo->prepare('SELECT id, stato, id_prodotto, quantita_attuale, prezzo_corrente FROM collette WHERE id = ? FOR UPDATE'); $st->execute([$colletta_id]); $c=$st->fetch(); if(!$c) throw new AppException('CAMPAGNA_INESISTENTE','Campagna non trovata',404);
            if($c['stato']!=='ordine_pronto') throw new AppException('STATO_NON_VALIDO','Stato non ordine_pronto');
            $st=$pdo->prepare('SELECT COUNT(*) FROM prenotazioni WHERE id_colletta = ? AND stato != \'pagata\''); $st->execute([$colletta_id]); if((int)$st->fetchColumn()>0) throw new AppException('PAGAMENTI_INCOMPLETI','Pagamenti incompleti');
            $pezzi=(int)$c['quantita_attuale']; $importo=round($pezzi*(float)$c['prezzo_corrente'],2);
            $st=$pdo->prepare('SELECT id_fornitore FROM prodotti WHERE id = ?'); $st->execute([(int)$c['id_prodotto']]); $fid=(int)$st->fetchColumn();
            $st=$pdo->prepare('SELECT id FROM ordini_fornitore WHERE id_colletta = ?'); $st->execute([$colletta_id]); $ex=$st->fetch();
            if($ex) $pdo->prepare('UPDATE ordini_fornitore SET id_fornitore=?, quantita_ordinata=?, importo_totale=?, stato=\'inviato\', data_ordine=NOW() WHERE id=?')->execute([$fid,$pezzi,$importo,(int)$ex['id']]);
            else $pdo->prepare('INSERT INTO ordini_fornitore (id_colletta, id_fornitore, quantita_ordinata, importo_totale, stato, data_ordine) VALUES (?,?,?,?,\'inviato\',NOW())')->execute([$colletta_id,$fid,$pezzi,$importo]);
            $pdo->prepare('UPDATE collette SET stato=\'ordine_fornitore\', data_agg_stato=NOW() WHERE id=?')->execute([$colletta_id]);
            if($own) $pdo->commit();
        }catch(\Throwable $e){ if($own && $pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }

    #[Route('/api/pagamento/stato', methods: ['GET'])]
    #[Route('/pagamento/stato', methods: ['GET'])]
    public function stato(Request $request): JsonResponse
    {
        $this->auth->richiediLogin();
        $session_id=$request->query->get('session_id',''); if(empty($session_id)) throw new AppException('SESSION_ID_MANCANTE','Session ID mancante',400);
        $cfg=$this->db->getConfigValue('stripe_secret_key'); $stripe=new \Stripe\StripeClient($cfg);
        try{
            $session=$stripe->checkout->sessions->retrieve($session_id); $prenotazione_id=(int)($session->metadata->prenotazione_id ?? 0); $isPaid=($session->payment_status==='paid');
            if($isPaid && $prenotazione_id>0){
                $pdo=$this->db->getPdo(); $pdo->beginTransaction();
                try{
                    $st=$pdo->prepare('SELECT stato FROM prenotazioni WHERE id = ? FOR UPDATE'); $st->execute([$prenotazione_id]); $pren=$st->fetch();
                    if($pren && $pren['stato']==='confermata'){
                        $stPren=$pdo->prepare('SELECT p.id, p.id_colletta, p.id_utente, p.quantita, p.importo_saldo, c.percentuale_commissione, pr.nome AS nome_prodotto FROM prenotazioni p JOIN collette c ON c.id = p.id_colletta JOIN prodotti pr ON pr.id = c.id_prodotto WHERE p.id = ?'); $stPren->execute([$prenotazione_id]); $dettagli=$stPren->fetch();
                        if($dettagli){
                            $importo=(float)$dettagli['importo_saldo']; $commissione=round($importo*(float)$dettagli['percentuale_commissione']/100,2); $colletta_id=(int)$dettagli['id_colletta']; $utente_id=(int)$dettagli['id_utente'];
                            $stripe_payment_intent_id=$session->payment_intent ?? null;
                            $pdo->prepare('INSERT INTO pagamenti (id_prenotazione, tipo_pagamento, importo, commissione_agenzia, stripe_payment_intent_id, stato_stripe, stato, data_pagamento, data_conferma) VALUES (?, \'saldo\', ?, ?, ?, ?, \'confermato\', NOW(), NOW())')->execute([$prenotazione_id,$importo,$commissione,$stripe_payment_intent_id,$session->payment_status ?? 'paid']);
                            $stCons=$pdo->prepare('SELECT modalita FROM consegne WHERE id_prenotazione = ?'); $stCons->execute([$prenotazione_id]); $modalita=$stCons->fetchColumn()?:'ritiro_sede';
                            if($modalita!=='consegna_domicilio'){ $token=bin2hex(random_bytes(24)); $pdo->prepare('INSERT INTO qr_codes (id_prenotazione, token, quantita_assegnata, stato) VALUES (?, ?, ?, \'generato\')')->execute([$prenotazione_id,$token,$dettagli['quantita']]); }
                            $pdo->prepare('UPDATE prenotazioni SET stato = \'pagata\', data_pagamento = NOW(), importo_commissione = ? WHERE id = ?')->execute([$commissione,$prenotazione_id]);
                            $stProg=$pdo->prepare("SELECT COUNT(*) AS totale, SUM(CASE WHEN stato = 'pagata' THEN 1 ELSE 0 END) AS pagati FROM prenotazioni WHERE id_colletta = ?"); $stProg->execute([$colletta_id]); $prog=$stProg->fetch(); $pagati=(int)($prog['pagati']??0); $totale=(int)($prog['totale']??0); $progresso="$pagati/$totale pagati";
                            $messaggioUtente=$modalita==='consegna_domicilio'?"Pagamento di EUR ".number_format($importo,2,',','.')." ricevuto con successo. Ti avviseremo quando il tuo ordine verrà spedito. ($progresso)":"Pagamento di EUR ".number_format($importo,2,',','.')." ricevuto con successo. ($progresso)";
                            $pdo->prepare('INSERT INTO notifiche (id_utente, tipo, titolo, messaggio, tipo_riferimento, id_riferimento) VALUES (?, \'PAGAMENTO_RIUSCITO\', \'Pagamento Ricevuto\', ?, \'prenotazione\', ?)')->execute([$utente_id,$messaggioUtente,$prenotazione_id]);
                            try{ $stAdmin=$pdo->prepare('SELECT id FROM utenti WHERE ruolo = \'admin\''); $stAdmin->execute(); $adminIds=$stAdmin->fetchAll(\PDO::FETCH_COLUMN); $stNomeUtente=$pdo->prepare('SELECT nome, cognome FROM utenti WHERE id = ?'); $stNomeUtente->execute([$utente_id]); $utente=$stNomeUtente->fetch(); $nomeUtente=$utente?trim($utente['nome'].' '.$utente['cognome']):"Utente #$utente_id"; $stNotificaAdmin=$pdo->prepare('INSERT INTO notifiche (id_utente, tipo, titolo, messaggio, tipo_riferimento, id_riferimento) VALUES (?, \'PAGAMENTO_RICEVUTO\', \'Pagamento Ricevuto\', ?, \'colletta\', ?)'); foreach($adminIds as $adminId){ $messaggioAdmin="Pagamento di EUR ".number_format($importo,2,',','.')." ricevuto da $nomeUtente per {$dettagli['nome_prodotto']}. Commissione: EUR ".number_format($commissione,2,',','.').". ($progresso)"; $stNotificaAdmin->execute([$adminId,$messaggioAdmin,$colletta_id]); } }catch(\Throwable $eNotify){ error_log("pagamento_stato: notifica admin fallita per prenotazione #$prenotazione_id: ".$eNotify->getMessage()); }
                            $nuovoStatoFb=$this->stato->ricalcolaStato($colletta_id);
                            if($nuovoStatoFb==='ordine_pronto' && $pagati>0 && $pagati===$totale){ try{ $this->inviaOrdineFornitoreAutomatico((int)$colletta_id); }catch(\Throwable $eAuto){ error_log("pagamento_stato: invio automatico colletta #$colletta_id fallito: ".$eAuto->getMessage()); } }
                        }
                    }
                    $pdo->commit();
                }catch(\Throwable $e){ if($pdo->inTransaction()) $pdo->rollBack(); error_log("pagamento_stato fallback errore: ".$e->getMessage()); }
            }
            return ApiResponse::ok(['status'=>$session->payment_status,'prenotazione'=>$prenotazione_id,'importo'=>($session->amount_total ?? 0)/100]);
        }catch(\Stripe\Exception\ApiErrorException $e){ throw new AppException('STRIPE_ERRORE','Errore nel recupero stato: '.$e->getMessage()); }
    }
}
