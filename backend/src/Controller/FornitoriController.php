<?php
declare(strict_types=1);

namespace App\Controller;

use App\Exception\AppException;
use App\Service\ApiResponse;
use App\Service\AuthService;
use App\Service\DatabaseService;
use App\Service\FornitoreService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class FornitoriController extends AbstractController
{
    public function __construct(private DatabaseService $db, private AuthService $auth, private FornitoreService $fornitoreService, private \App\Service\StatoService $stato) {}

    #[Route('/api/admin/fornitori', methods: ['GET'])]
    #[Route('/admin/fornitori', methods: ['GET'])]
    public function adminElenco(): JsonResponse
    {
        if(!$this->auth->sonoAdmin()) throw new AppException('NON_AUTORIZZATO','Solo gli admin',403);
        $st=$this->db->getPdo()->query('SELECT f.id, f.nome_azienda, f.email_contatto, f.telefono, f.indirizzo, f.descrizione, f.piva, f.categoria, f.sito_web, f.partner_pubblico, f.num_campagne, f.id_utente, f.data_partnership, u.email AS email_account, (SELECT COUNT(*) FROM inviti_fornitore i WHERE i.id_fornitore = f.id AND i.usato = 0 AND i.scadenza > NOW()) AS inviti_pendenti FROM fornitori f LEFT JOIN utenti u ON u.id = f.id_utente ORDER BY f.nome_azienda ASC');
        $out=array_map(fn($f)=>['id'=>(int)$f['id'],'nome_azienda'=>$f['nome_azienda'],'email_contatto'=>$f['email_contatto'],'telefono'=>$f['telefono'],'indirizzo'=>$f['indirizzo'],'descrizione'=>$f['descrizione'],'piva'=>$f['piva'],'categoria'=>$f['categoria'],'sito_web'=>$f['sito_web'],'partner_pubblico'=>(bool)$f['partner_pubblico'],'num_campagne'=>(int)$f['num_campagne'],'id_utente'=>$f['id_utente']!==null?(int)$f['id_utente']:null,'data_partnership'=>$f['data_partnership'],'email_account'=>$f['email_account'],'inviti_pendenti'=>(int)$f['inviti_pendenti']],$st->fetchAll());
        return ApiResponse::ok($out);
    }

    #[Route('/api/admin/fornitori', methods: ['POST'])]
    #[Route('/admin/fornitori', methods: ['POST'])]
    public function adminCrea(Request $request): JsonResponse
    {
        if(!$this->auth->sonoAdmin()) throw new AppException('NON_AUTORIZZATO','Solo gli admin',403);
        $d=ApiResponse::corpo($request); $nome=trim(ApiResponse::campo($d,'nome_azienda')); if($nome==='') throw new AppException('NOME_MANCANTE','Nome azienda obbligatorio');
        $st=$this->db->getPdo()->prepare('INSERT INTO fornitori (nome_azienda, email_contatto, telefono, indirizzo, descrizione, piva, categoria, sito_web) VALUES (?,?,?,?,?,?,?,?)');
        $st->execute([$nome,$d['email_contatto']??null,$d['telefono']??null,$d['indirizzo']??null,$d['descrizione']??null,$d['piva']??null,$d['categoria']??null,$this->fornitoreService->normalizzaUrl($d['sito_web']??null)]);
        return ApiResponse::ok(['id'=>(int)$this->db->getPdo()->lastInsertId()],201);
    }

    #[Route('/api/admin/fornitori/{id}', methods: ['PUT'], requirements: ['id'=>'\d+'])]
    #[Route('/admin/fornitori/{id}', methods: ['PUT'], requirements: ['id'=>'\d+'])]
    public function adminAggiorna(int $id, Request $request): JsonResponse
    {
        if(!$this->auth->sonoAdmin()) throw new AppException('NON_AUTORIZZATO','Solo gli admin',403);
        $d=ApiResponse::corpo($request);
        $st=$this->db->getPdo()->prepare('SELECT id FROM fornitori WHERE id = ?'); $st->execute([$id]); if(!$st->fetch()) throw new AppException('FORNITORE_INESISTENTE','Fornitore non trovato',404);
        $campi=[];$valori=[];
        foreach(['nome_azienda','email_contatto','telefono','indirizzo','descrizione','piva','categoria','logo_url'] as $k){ if(array_key_exists($k,$d)){ $v=trim((string)$d[$k]); if($k==='nome_azienda' && $v==='') throw new AppException('NOME_MANCANTE','Nome azienda obbligatorio'); $campi[]="$k = ?"; $valori[]=$v!==''?$v:null; } }
        if(array_key_exists('sito_web',$d)){ $campi[]='sito_web = ?'; $valori[]=$this->fornitoreService->normalizzaUrl($d['sito_web']); }
        if(array_key_exists('partner_pubblico',$d)){ $campi[]='partner_pubblico = ?'; $valori[]=$d['partner_pubblico']?1:0; }
        if(empty($campi)) throw new AppException('NESSUNA_MODIFICA','Nessun campo da aggiornare');
        $valori[]=$id; $this->db->getPdo()->prepare('UPDATE fornitori SET '.implode(', ',$campi).' WHERE id = ?')->execute($valori);
        return ApiResponse::ok(['aggiornato'=>true]);
    }

    #[Route('/api/admin/fornitori/{id}/invito', methods: ['POST'], requirements: ['id'=>'\d+'])]
    #[Route('/admin/fornitori/{id}/invito', methods: ['POST'], requirements: ['id'=>'\d+'])]
    public function invitoGenera(int $id): JsonResponse
    {
        if(!$this->auth->sonoAdmin()) throw new AppException('NON_AUTORIZZATO','Solo gli admin',403);
        $st=$this->db->getPdo()->prepare('SELECT id, nome_azienda, email_contatto, id_utente FROM fornitori WHERE id = ?'); $st->execute([$id]); $f=$st->fetch(); if(!$f) throw new AppException('FORNITORE_INESISTENTE','Fornitore non trovato',404);
        if($f['id_utente']!==null) throw new AppException('GIA_COLLEGATO','Questo fornitore ha gia\' un account attivo collegato',409);
        if(empty($f['email_contatto'])) throw new AppException('EMAIL_MANCANTE','Inserire prima una email di contatto per questo fornitore');
        $this->db->getPdo()->prepare('UPDATE inviti_fornitore SET usato = 1 WHERE id_fornitore = ? AND usato = 0')->execute([$id]);
        $token=bin2hex(random_bytes(32));
        $this->db->getPdo()->prepare('INSERT INTO inviti_fornitore (id_fornitore, email, token, scadenza) VALUES (?,?,?, DATE_ADD(NOW(), INTERVAL 7 DAY))')->execute([$id,$f['email_contatto'],$token]);
        $cfg=$this->db->getConfigValue('frontend_url','http://localhost:8000'); $link=rtrim($cfg,'/').'/fornitore-attiva.html?token='.$token;
        return ApiResponse::ok(['token'=>$token,'link'=>$link,'scadenza_giorni'=>7],201);
    }

    #[Route('/api/admin/fornitori/{id}/collega', methods: ['POST'], requirements: ['id'=>'\d+'])]
    #[Route('/admin/fornitori/{id}/collega', methods: ['POST'], requirements: ['id'=>'\d+'])]
    public function collega(int $id, Request $request): JsonResponse
    {
        if(!$this->auth->sonoAdmin()) throw new AppException('NON_AUTORIZZATO','Solo gli admin',403);
        $d=ApiResponse::corpo($request); $utente_id=ApiResponse::campoInt($d,'utente_id');
        $pdo=$this->db->getPdo(); $pdo->beginTransaction();
        try{
            $st=$pdo->prepare('SELECT id, id_utente FROM fornitori WHERE id = ? FOR UPDATE'); $st->execute([$id]); $f=$st->fetch(); if(!$f){ $pdo->rollBack(); throw new AppException('FORNITORE_INESISTENTE','Fornitore non trovato',404); }
            if($f['id_utente']!==null){ $pdo->rollBack(); throw new AppException('GIA_COLLEGATO','Questo fornitore ha gia\' un account collegato',409); }
            $st=$pdo->prepare('SELECT id, ruolo FROM utenti WHERE id = ?'); $st->execute([$utente_id]); $u=$st->fetch(); if(!$u){ $pdo->rollBack(); throw new AppException('UTENTE_INESISTENTE','Utente non trovato',404); }
            $st=$pdo->prepare('SELECT id FROM fornitori WHERE id_utente = ?'); $st->execute([$utente_id]); if($st->fetch()){ $pdo->rollBack(); throw new AppException('UTENTE_GIA_COLLEGATO','Questo utente e\' gia\' collegato a un altro fornitore',409); }
            if($u['ruolo']!=='admin') $pdo->prepare("UPDATE utenti SET ruolo = 'fornitore' WHERE id = ?")->execute([$utente_id]);
            $pdo->prepare('UPDATE fornitori SET id_utente = ? WHERE id = ?')->execute([$utente_id,$id]);
            $pdo->commit(); return ApiResponse::ok(['id_fornitore'=>$id,'utente_id'=>$utente_id]);
        }catch(\Throwable $e){ if($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }

    #[Route('/api/fornitori/invito/{token}', methods: ['GET'])]
    #[Route('/fornitori/invito/{token}', methods: ['GET'])]
    public function invitoVerifica(string $token): JsonResponse
    {
        $st=$this->db->getPdo()->prepare('SELECT i.email, i.scadenza, i.usato, f.nome_azienda FROM inviti_fornitore i JOIN fornitori f ON f.id = i.id_fornitore WHERE i.token = ?'); $st->execute([$token]); $inv=$st->fetch(); if(!$inv) throw new AppException('INVITO_NON_VALIDO','Link di invito non valido',404);
        if((int)$inv['usato']===1 || strtotime($inv['scadenza'])<time()) throw new AppException('INVITO_SCADUTO','Questo link di invito e\' scaduto o gia\' utilizzato',410);
        return ApiResponse::ok(['nome_azienda'=>$inv['nome_azienda'],'email'=>$inv['email']]);
    }

    #[Route('/api/fornitori/attiva', methods: ['POST'])]
    #[Route('/fornitori/attiva', methods: ['POST'])]
    public function attiva(Request $request): JsonResponse
    {
        $d=ApiResponse::corpo($request); $token=trim($d['token']??''); $nome=trim($d['nome']??''); $cognome=trim($d['cognome']??''); $password=(string)($d['password']??'');
        if($token==='') throw new AppException('TOKEN_MANCANTE','Token invito mancante'); if($nome==='') throw new AppException('NOME_MANCANTE','Nome referente obbligatorio'); if(strlen($password)<8) throw new AppException('PASSWORD_DEBOLE','La password deve avere almeno 8 caratteri');
        $pdo=$this->db->getPdo(); $pdo->beginTransaction();
        try{
            $st=$pdo->prepare('SELECT i.id, i.id_fornitore, i.email, i.scadenza, i.usato FROM inviti_fornitore i WHERE i.token = ? FOR UPDATE'); $st->execute([$token]); $inv=$st->fetch(); if(!$inv){ $pdo->rollBack(); throw new AppException('INVITO_NON_VALIDO','Link di invito non valido',404); }
            if((int)$inv['usato']===1 || strtotime($inv['scadenza'])<time()){ $pdo->rollBack(); throw new AppException('INVITO_SCADUTO','Questo link di invito e\' scaduto o gia\' utilizzato',410); }
            $st=$pdo->prepare('SELECT id, ruolo FROM utenti WHERE email = ?'); $st->execute([$inv['email']]); $esistente=$st->fetch(); if($esistente){ $pdo->rollBack(); throw new AppException('EMAIL_ESISTENTE','Esiste gia\' un account con questa email: fai login e chiedi all\'admin di collegarlo',409); }
            $hash=password_hash($password,PASSWORD_BCRYPT);
            $st=$pdo->prepare("INSERT INTO utenti (nome, cognome, email, password_hash, ruolo, stato) VALUES (?,?,?,?, 'fornitore', 'attivo')"); $st->execute([$nome,$cognome,$inv['email'],$hash]); $utente_id=(int)$pdo->lastInsertId();
            $st=$pdo->prepare('UPDATE fornitori SET id_utente = ? WHERE id = ? AND id_utente IS NULL'); $st->execute([$utente_id,(int)$inv['id_fornitore']]); if($st->rowCount()===0){ $pdo->rollBack(); throw new AppException('GIA_COLLEGATO','Questo fornitore ha gia\' un account collegato',409); }
            $pdo->prepare('UPDATE inviti_fornitore SET usato = 1 WHERE id = ?')->execute([(int)$inv['id']]); $pdo->commit(); return ApiResponse::ok(['utente_id'=>$utente_id],201);
        }catch(\Throwable $e){ if($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }

    #[Route('/api/fornitore/io', methods: ['GET'])]
    #[Route('/fornitore/io', methods: ['GET'])]
    public function io(): JsonResponse
    {
        $fid=$this->auth->richiediFornitore();
        $st=$this->db->getPdo()->prepare('SELECT id, nome_azienda, email_contatto, telefono, indirizzo, descrizione, logo_url, sito_web, piva, categoria, num_campagne, data_partnership FROM fornitori WHERE id = ?'); $st->execute([$fid]); $f=$st->fetch(); if(!$f) throw new AppException('FORNITORE_INESISTENTE','Anagrafica non trovata',404);
        $f['id']=(int)$f['id']; $f['num_campagne']=(int)$f['num_campagne']; return ApiResponse::ok($f);
    }

    #[Route('/api/fornitore/io', methods: ['PUT'])]
    #[Route('/fornitore/io', methods: ['PUT'])]
    public function ioAggiorna(Request $request): JsonResponse
    {
        $fid=$this->auth->richiediFornitore(); $d=ApiResponse::corpo($request);
        $campi=[];$valori=[];
        foreach(['email_contatto','telefono','indirizzo','descrizione','logo_url'] as $k){ if(array_key_exists($k,$d)){ $v=trim((string)$d[$k]); $campi[]="$k = ?"; $valori[]=$v!==''?$v:null; } }
        if(array_key_exists('sito_web',$d)){ $campi[]='sito_web = ?'; $valori[]=$this->fornitoreService->normalizzaUrl($d['sito_web']); }
        if(empty($campi)) throw new AppException('NESSUNA_MODIFICA','Nessun campo da aggiornare');
        $valori[]=$fid; $this->db->getPdo()->prepare('UPDATE fornitori SET '.implode(', ',$campi).' WHERE id = ?')->execute($valori);
        return ApiResponse::ok(['aggiornato'=>true]);
    }

    #[Route('/api/fornitore/proposte', methods: ['POST'])]
    #[Route('/fornitore/proposte', methods: ['POST'])]
    public function proposteCrea(Request $request): JsonResponse
    {
        $fid=$this->auth->richiediFornitore(); $this->auth->richiediUtenteAttivo(); $io=$this->auth->utenteCorrenteId();
        $nome=trim($request->request->get('nome_prodotto','')); if($nome==='') throw new AppException('NOME_MANCANTE','Nome prodotto obbligatorio');
        $descrizione=trim($request->request->get('descrizione',''));
        $moq=$request->request->get('moq_richiesto',''); $moq=$moq!==''?(int)$moq:null; if($moq!==null && $moq<1) throw new AppException('MOQ_NON_VALIDO','MOQ deve essere almeno 1');
        $prezzo=$request->request->get('prezzo_base',''); $prezzo=$prezzo!==''?(float)$prezzo:null; if($prezzo!==null && $prezzo<=0) throw new AppException('PREZZO_NON_VALIDO','Prezzo base non valido');
        $prezzo_corrente=$request->request->get('prezzo_corrente',''); $prezzo_corrente=$prezzo_corrente!==''?(float)$prezzo_corrente:null; if($prezzo_corrente!==null && $prezzo_corrente<=0) throw new AppException('PREZZO_NON_VALIDO','Prezzo attuale non valido');
        $tempi=trim($request->request->get('tempi_consegna',''));
        $scaglioni=[];
        if($request->request->has('scaglioni') && $request->request->get('scaglioni')!==''){
            $dec=json_decode($request->request->get('scaglioni'),true); if(!is_array($dec)) throw new AppException('SCAGLIONI_NON_VALIDI','Formato scaglioni non valido');
            foreach($dec as $s){ $soglia=(int)($s['soglia']??0); $prz=(float)($s['prezzo']??0); if($soglia<1||$prz<=0) throw new AppException('SCAGLIONI_NON_VALIDI','Ogni scaglione deve avere soglia >= 1 e prezzo > 0'); $scaglioni[]=['soglia'=>$soglia,'prezzo'=>round($prz,2)]; }
            usort($scaglioni,fn($a,$b)=>$a['soglia']<=>$b['soglia']);
        }
        $foto_path=null;
        $fotoFile=$request->files->get('foto');
        if($fotoFile instanceof \Symfony\Component\HttpFoundation\File\UploadedFile){
            $arr=['name'=>$fotoFile->getClientOriginalName(),'type'=>$fotoFile->getMimeType(),'tmp_name'=>$fotoFile->getPathname(),'error'=>$fotoFile->getError(),'size'=>$fotoFile->getSize()];
            $foto_path=$this->fornitoreService->salvaFoto($arr);
        } elseif(!empty($_FILES['foto']) && (($_FILES['foto']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE)){
            $foto_path=$this->fornitoreService->salvaFoto($_FILES['foto']);
        }
        $pdo=$this->db->getPdo(); $pdo->beginTransaction();
        try{
            $st=$pdo->prepare('INSERT INTO proposte_prodotti (proponente_tipo, proponente_id, nome_prodotto, descrizione, id_fornitore_suggerito, moq_richiesto, prezzo_base, prezzo_corrente, tempi_consegna, foto_path, stato) VALUES (\'fornitore\', ?, ?, ?, ?, ?, ?, ?, ?, ?, \'in_attesa\')');
            $st->execute([$io,$nome,$descrizione,$fid,$moq,$prezzo,$prezzo_corrente,$tempi!==''?$tempi:null,$foto_path]); $id_proposta=(int)$pdo->lastInsertId();
            if(!empty($scaglioni)){ $stS=$pdo->prepare('INSERT INTO proposta_scaglioni (id_proposta, soglia, prezzo) VALUES (?,?,?)'); foreach($scaglioni as $s) $stS->execute([$id_proposta,$s['soglia'],$s['prezzo']]); }
            $pdo->commit(); return ApiResponse::ok(['id_proposta'=>$id_proposta],201);
        }catch(\Throwable $e){ if($pdo->inTransaction()) $pdo->rollBack(); if($foto_path) @unlink(dirname(__DIR__,2).'/public/'.$foto_path); throw $e; }
    }

    #[Route('/api/fornitore/proposte', methods: ['GET'])]
    #[Route('/fornitore/proposte', methods: ['GET'])]
    public function proposteMie(): JsonResponse
    {
        $fid=$this->auth->richiediFornitore(); $io=$this->auth->utenteCorrenteId();
        $st=$this->db->getPdo()->prepare('SELECT pp.*, (SELECT COUNT(*) FROM voti_proposte WHERE id_proposta = pp.id_proposta AND valore_voto = \'favore\') AS voti_favore, (SELECT COUNT(*) FROM voti_proposte WHERE id_proposta = pp.id_proposta AND valore_voto = \'contrario\') AS voti_contrari FROM proposte_prodotti pp WHERE pp.proponente_id = ? AND pp.proponente_tipo = \'fornitore\' OR pp.id_fornitore_suggerito = ? ORDER BY pp.data_proposta DESC');
        $st->execute([$io,$fid]); $out=$st->fetchAll();
        $ids=array_column($out,'id_proposta'); $scaglioni=[];
        if(!empty($ids)){ $ph=implode(',',array_fill(0,count($ids),'?')); $stS=$this->db->getPdo()->prepare("SELECT id_proposta, soglia, prezzo FROM proposta_scaglioni WHERE id_proposta IN ($ph) ORDER BY soglia ASC"); $stS->execute($ids); foreach($stS->fetchAll() as $s) $scaglioni[(int)$s['id_proposta']][]=['soglia'=>(int)$s['soglia'],'prezzo'=>(float)$s['prezzo']]; }
        foreach($out as &$p){ $p['id_proposta']=(int)$p['id_proposta']; $p['voti_favore']=(int)$p['voti_favore']; $p['voti_contrari']=(int)$p['voti_contrari']; $p['moq_richiesto']=$p['moq_richiesto']!==null?(int)$p['moq_richiesto']:null; $p['prezzo_base']=$p['prezzo_base']!==null?(float)$p['prezzo_base']:null; $p['prezzo_corrente']=$p['prezzo_corrente']!==null?(float)$p['prezzo_corrente']:null; $p['scaglioni']=$scaglioni[$p['id_proposta']]??[]; }
        return ApiResponse::ok($out);
    }

    #[Route('/api/fornitore/campagne', methods: ['GET'])]
    #[Route('/fornitore/campagne', methods: ['GET'])]
    public function campagne(): JsonResponse
    {
        $fid=$this->auth->richiediFornitore();
        $st=$this->db->getPdo()->prepare('SELECT c.id, c.stato, c.quantita_minima, c.quantita_attuale, c.data_limite, c.prezzo_corrente, pr.nome AS prodotto, COUNT(DISTINCT p.id_utente) AS partecipanti FROM collette c JOIN prodotti pr ON pr.id = c.id_prodotto LEFT JOIN prenotazioni p ON p.id_colletta = c.id WHERE pr.id_fornitore = ? GROUP BY c.id ORDER BY c.data_limite ASC');
        $st->execute([$fid]); $out=array_map(function($c){ $c['id']=(int)$c['id']; $c['quantita_minima']=(int)$c['quantita_minima']; $c['quantita_attuale']=(int)$c['quantita_attuale']; $c['partecipanti']=(int)$c['partecipanti']; $c['prezzo_corrente']=(float)$c['prezzo_corrente']; $c['percentuale_adesione']=$c['quantita_minima']>0?(int)round($c['quantita_attuale']/$c['quantita_minima']*100):0; return $c; },$st->fetchAll());
        foreach($out as &$c) $c['stato']=$this->stato->ricalcolaStato($c['id']);
        return ApiResponse::ok($out);
    }

    #[Route('/api/fornitore/ordini', methods: ['GET'])]
    #[Route('/fornitore/ordini', methods: ['GET'])]
    public function ordini(): JsonResponse
    {
        $fid=$this->auth->richiediFornitore();
        $st=$this->db->getPdo()->prepare('SELECT o.id, o.id_colletta, o.quantita_ordinata, o.importo_totale, o.stato, o.data_ordine, o.data_consegna, c.stato AS stato_colletta, pr.nome AS prodotto FROM ordini_fornitore o JOIN collette c ON c.id = o.id_colletta JOIN prodotti pr ON pr.id = c.id_prodotto WHERE o.id_fornitore = ? ORDER BY o.data_ordine DESC');
        $st->execute([$fid]); $out=array_map(fn($o)=>['id'=>(int)$o['id'],'id_colletta'=>(int)$o['id_colletta'],'quantita_ordinata'=>(int)$o['quantita_ordinata'],'importo_totale'=>(float)$o['importo_totale'],'stato'=>$o['stato'],'data_ordine'=>$o['data_ordine'],'data_consegna'=>$o['data_consegna'],'stato_colletta'=>$o['stato_colletta'],'prodotto'=>$o['prodotto']],$st->fetchAll());
        return ApiResponse::ok($out);
    }

    #[Route('/api/fornitore/ordini/{id}/avanza', methods: ['POST'], requirements: ['id'=>'\d+'])]
    #[Route('/fornitore/ordini/{id}/avanza', methods: ['POST'], requirements: ['id'=>'\d+'])]
    public function avanza(int $id): JsonResponse
    {
        $fid=$this->auth->richiediFornitore(); $this->auth->richiediUtenteAttivo();
        $pdo=$this->db->getPdo(); $pdo->beginTransaction();
        try{
            $st=$pdo->prepare('SELECT id, id_colletta, id_fornitore, stato FROM ordini_fornitore WHERE id = ? FOR UPDATE'); $st->execute([$id]); $o=$st->fetch(); if(!$o){ $pdo->rollBack(); throw new AppException('ORDINE_INESISTENTE','Ordine non trovato',404); }
            if((int)$o['id_fornitore']!==$fid){ $pdo->rollBack(); throw new AppException('NON_AUTORIZZATO','Questo ordine non appartiene alla tua azienda',403); }
            $transizioni=['inviato'=>'ricevuto','ricevuto'=>'in_preparazione','in_preparazione'=>'evaso']; if(!isset($transizioni[$o['stato']])){ $pdo->rollBack(); throw new AppException('STATO_NON_AVANZABILE','Ordine in stato "'.$o['stato'].'": nessuna azione disponibile'); }
            $nuovo=$transizioni[$o['stato']];
            if($nuovo==='evaso'){
                $pdo->prepare('UPDATE ordini_fornitore SET stato = \'evaso\', data_consegna = NOW() WHERE id = ?')->execute([$id]);
                $pdo->prepare('UPDATE collette SET stato = \'consegnata\', data_agg_stato = NOW() WHERE id = ?')->execute([(int)$o['id_colletta']]);
                $stProd=$pdo->prepare('SELECT pr.nome FROM collette c JOIN prodotti pr ON pr.id = c.id_prodotto WHERE c.id = ?'); $stProd->execute([(int)$o['id_colletta']]); $nomeProdotto=$stProd->fetchColumn()?:'il tuo ordine';
                $stUt=$pdo->prepare('SELECT DISTINCT p.id_utente FROM prenotazioni p LEFT JOIN consegne co ON co.id_prenotazione = p.id WHERE p.id_colletta = ? AND (co.modalita IS NULL OR co.modalita = \'ritiro_sede\')'); $stUt->execute([(int)$o['id_colletta']]); $stNot=$pdo->prepare('INSERT INTO notifiche (id_utente, tipo, titolo, messaggio, tipo_riferimento, id_riferimento) VALUES (?, \'MERCE_PRONTA\', \'Merce Consegnata\', ?, \'colletta\', ?)'); foreach($stUt->fetchAll(\PDO::FETCH_COLUMN) as $uid) $stNot->execute([$uid,"Il fornitore ha consegnato \"$nomeProdotto\". Controlla il punto di ritiro.",(int)$o['id_colletta']]);
            } else $pdo->prepare('UPDATE ordini_fornitore SET stato = ? WHERE id = ?')->execute([$nuovo,$id]);
            $stForn=$pdo->prepare('SELECT nome_azienda FROM fornitori WHERE id = ?'); $stForn->execute([$fid]); $nomeFornitore=$stForn->fetchColumn()?:'Un fornitore';
            $stProd2=$pdo->prepare('SELECT pr.nome FROM collette c JOIN prodotti pr ON pr.id = c.id_prodotto WHERE c.id = ?'); $stProd2->execute([(int)$o['id_colletta']]); $nomeProdotto2=$stProd2->fetchColumn()?:'un prodotto';
            $stQuant=$pdo->prepare('SELECT quantita_ordinata FROM ordini_fornitore WHERE id = ?'); $stQuant->execute([$id]); $pezzi=(int)$stQuant->fetchColumn();
            $labelStep=match($nuovo){'ricevuto'=>'ha preso in carico','in_preparazione'=>'ha messo in preparazione','evaso'=>'ha evaso',default=>'ha aggiornato'};
            $stAdmin=$pdo->prepare("SELECT id FROM utenti WHERE ruolo = 'admin'"); $stAdmin->execute(); $stNotAdmin=$pdo->prepare('INSERT INTO notifiche (id_utente, tipo, titolo, messaggio, tipo_riferimento, id_riferimento) VALUES (?, \'SISTEMA\', \'Aggiornamento Ordine\', ?, \'colletta\', ?)'); foreach($stAdmin->fetchAll(\PDO::FETCH_COLUMN) as $adminId) $stNotAdmin->execute([$adminId,"$nomeFornitore $labelStep l'ordine per \"$nomeProdotto2\" ($pezzi pezzi).",(int)$o['id_colletta']]);
            $pdo->commit(); return ApiResponse::ok(['stato'=>$nuovo]);
        }catch(\Throwable $e){ if($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }
}
