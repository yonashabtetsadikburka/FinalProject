<?php
declare(strict_types=1);

namespace App\Controller;

use App\Exception\AppException;
use App\Service\ApiResponse;
use App\Service\AuthService;
use App\Service\DatabaseService;
use App\Service\FornitoreService;
use App\Service\StatoService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class CampagneController extends AbstractController
{
    public function __construct(
        private DatabaseService $db,
        private AuthService $auth,
        private StatoService $stato,
        private FornitoreService $fornitoreService
    ) {}

    private function sqlCollette(): string
    {
        return 'SELECT c.id, c.stato, c.data_limite, c.data_inizio, c.quantita_minima, c.quantita_attuale,
                c.regola_arrotondamento, c.prezzo_base, c.prezzo_corrente,
                c.percentuale_commissione, c.data_conferma,
                c.id_prodotto, c.id_aperta_da, c.id_referente, c.id_sede,
                pr.nome AS prodotto, pr.prezzo_unitario, pr.quantita_minima AS lotto_prodotto,
                pr.id_categoria, cat.nome AS categoria,
                (SELECT url FROM immagini_prodotto WHERE id_prodotto = pr.id ORDER BY principale DESC, ordine ASC LIMIT 1) AS immagine,
                (SELECT CONCAT("[", GROUP_CONCAT(JSON_OBJECT("id", id, "url", url, "principale", principale, "ordine", ordine) ORDER BY principale DESC, ordine ASC SEPARATOR ","), "]") FROM immagini_prodotto WHERE id_prodotto = pr.id) AS immagini,
                f.nome_azienda AS fornitore, f.id AS fornitore_id,
                s.nome AS punto_ritiro, s.citta,
                COALESCE(SUM(p.quantita), 0) AS quantita_totale,
                COUNT(DISTINCT p.id_utente) AS partecipanti
           FROM collette c
           JOIN prodotti  pr ON pr.id = c.id_prodotto
           JOIN fornitori f  ON f.id  = pr.id_fornitore
      LEFT JOIN categorie cat ON cat.id = pr.id_categoria
      LEFT JOIN sedi         s ON s.id_sede = c.id_sede
      LEFT JOIN prenotazioni p ON p.id_colletta = c.id';
    }

    private function normalizza(array $r): array
    {
        foreach (['id','quantita_minima','quantita_attuale','quantita_totale','partecipanti','id_prodotto','id_aperta_da','id_referente','id_sede','fornitore_id','lotto_prodotto','id_categoria'] as $k) {
            if (isset($r[$k])) $r[$k] = (int)$r[$k];
        }
        foreach (['prezzo_base','prezzo_corrente','percentuale_commissione','prezzo_unitario'] as $k) {
            if (isset($r[$k])) $r[$k] = (float)$r[$k];
        }
        $r['soglia_raggiunta'] = $r['quantita_attuale'] >= $r['quantita_minima'];
        $imgs = [];
        if (!empty($r['immagini']) && is_string($r['immagini'])) {
            $dec = json_decode($r['immagini'], true);
            if (is_array($dec)) foreach ($dec as $im) if (is_array($im) && !empty($im['url'])) $imgs[] = ['id'=>(int)($im['id']??0),'url'=>(string)$im['url'],'principale'=>(int)($im['principale']??0),'ordine'=>(int)($im['ordine']??0)];
        }
        if (empty($imgs) && !empty($r['immagine'])) $imgs[] = ['url'=>(string)$r['immagine'],'principale'=>1,'ordine'=>0];
        unset($r['immagini']); $r['immagini']=$imgs;
        return $r;
    }

    #[Route('/api/campagne', methods: ['GET'])]
    #[Route('/campagne', methods: ['GET'])]
    public function elenco(): JsonResponse
    {
        $this->auth->richiediLogin();
        $this->db->getPdo()->exec('SET SESSION group_concat_max_len = 100000');
        $st = $this->db->getPdo()->query($this->sqlCollette() . ' GROUP BY c.id ORDER BY c.data_limite ASC');
        $out = array_map([$this,'normalizza'], $st->fetchAll());
        foreach ($out as &$c) $c['stato'] = $this->stato->ricalcolaStato($c['id']);
        return ApiResponse::ok($out);
    }

    #[Route('/api/campagne/{id}', methods: ['GET'], requirements: ['id'=>'\d+'])]
    #[Route('/campagne/{id}', methods: ['GET'], requirements: ['id'=>'\d+'])]
    public function dettaglio(int $id): JsonResponse
    {
        $this->auth->richiediLogin();
        $this->db->getPdo()->exec('SET SESSION group_concat_max_len = 100000');
        $st = $this->db->getPdo()->prepare($this->sqlCollette() . ' WHERE c.id = ? GROUP BY c.id');
        $st->execute([$id]);
        $c = $st->fetch();
        if (!$c) throw new AppException('CAMPAGNA_INESISTENTE', 'Campagna non trovata', 404);
        $c = $this->normalizza($c);
        $c['stato'] = $this->stato->ricalcolaStato($id);
        $stR = $this->db->getPdo()->prepare('SELECT COUNT(*) AS tot, AVG(voto) AS media FROM recensioni_fornitore WHERE id_fornitore = ?');
        $stR->execute([(int)($c['fornitore_id']??0)]);
        $rr = $stR->fetch();
        $c['fornitore_rating'] = ['media'=>$rr && $rr['tot']>0?round((float)$rr['media'],1):null,'totale'=>(int)($rr['tot']??0)];
        $stRc = $this->db->getPdo()->prepare('SELECT COUNT(*) AS tot, AVG(voto) AS media FROM recensioni_campagna WHERE id_colletta = ?');
        $stRc->execute([$id]);
        $rc=$stRc->fetch();
        $c['campagna_rating'] = ['media'=>$rc && $rc['tot']>0?round((float)$rc['media'],1):null,'totale'=>(int)($rc['tot']??0)];
        $st = $this->db->getPdo()->prepare('SELECT p.id, p.id_utente, u.nome, u.cognome, p.quantita, p.stato, p.data_prenotazione, qr.stato AS stato_qr, co.id AS id_consegna, co.modalita AS consegna_modalita, co.importo_consegna, co.stato AS consegna_stato FROM prenotazioni p JOIN utenti u ON u.id = p.id_utente LEFT JOIN qr_codes qr ON qr.id_prenotazione = p.id LEFT JOIN consegne co ON co.id_prenotazione = p.id WHERE p.id_colletta = ? ORDER BY p.data_prenotazione ASC');
        $st->execute([$id]);
        $c['partecipazioni'] = array_map(fn($p)=>['id'=>(int)$p['id'],'id_utente'=>(int)$p['id_utente'],'nome'=>$p['nome'],'cognome'=>$p['cognome'],'quantita'=>(int)$p['quantita'],'stato'=>$p['stato'],'data_prenotazione'=>$p['data_prenotazione'],'stato_qr'=>$p['stato_qr']??null,'id_consegna'=>isset($p['id_consegna'])?(int)$p['id_consegna']:null,'consegna_modalita'=>$p['consegna_modalita']??null,'importo_consegna'=>isset($p['importo_consegna'])?(float)$p['importo_consegna']:0.0,'consegna_stato'=>$p['consegna_stato']??null], $st->fetchAll());
        $mio = $this->auth->utenteCorrenteId();
        $c['mia_partecipazione']=null;
        foreach ($c['partecipazioni'] as $p) if ($p['id_utente']===$mio) { $c['mia_partecipazione']=['id'=>$p['id'],'quantita'=>$p['quantita'],'stato'=>$p['stato'],'stato_qr'=>$p['stato_qr']??null,'id_consegna'=>$p['id_consegna'],'consegna_modalita'=>$p['consegna_modalita'],'importo_consegna'=>$p['importo_consegna'],'consegna_stato'=>$p['consegna_stato']]; break; }
        return ApiResponse::ok($c);
    }

    #[Route('/api/campagne/{id}', methods: ['DELETE'], requirements: ['id'=>'\d+'])]
    #[Route('/campagne/{id}', methods: ['DELETE'], requirements: ['id'=>'\d+'])]
    public function elimina(int $id): JsonResponse
    {
        $this->auth->richiediLogin();
        if (!$this->auth->sonoAdmin()) throw new AppException('NON_ADMIN', 'Accesso riservato agli amministratori', 403);
        $st=$this->db->getPdo()->prepare('SELECT id FROM collette WHERE id = ?'); $st->execute([$id]); if(!$st->fetch()) throw new AppException('CAMPAGNA_INESISTENTE','Campagna non trovata',404);
        $st=$this->db->getPdo()->prepare('SELECT COUNT(*) FROM pagamenti pg JOIN prenotazioni p ON p.id = pg.id_prenotazione WHERE p.id_colletta = ?'); $st->execute([$id]); if((int)$st->fetchColumn()>0) throw new AppException('HA_PAGAMENTI','Impossibile eliminare: esistono pagamenti registrati',409);
        $this->db->getPdo()->prepare('DELETE FROM collette WHERE id = ?')->execute([$id]);
        return ApiResponse::ok(['eliminata'=>true]);
    }

    #[Route('/api/campagne', methods: ['POST'])]
    #[Route('/campagne', methods: ['POST'])]
    public function crea(Request $request): JsonResponse
    {
        $io=$this->auth->richiediLogin();
        if(!$this->auth->sonoAdmin()) throw new AppException('NON_AUTORIZZATO','Solo gli admin',403);
        $ct=$request->headers->get('Content-Type','');
        $isMultipart = str_contains($ct, 'multipart/form-data') || $request->request->count()>0 && $request->files->count()>=0 && $request->getContent()==='';
        // Symfony handles multipart via $request->request; fallback to JSON
        if ($request->request->count()>0 || $request->files->count()>0) {
            $d = $request->request->all();
            // Symfony may have nested? keep flat
        } else {
            $d = ApiResponse::corpo($request);
        }
        // Also try POST superglobal fallback for compatibility
        if (empty($d) && !empty($_POST)) $d = $_POST;
        $scadenza=trim((string)($d['scadenza']??$d['data_limite']??''));
        $ts=strtotime($scadenza);
        if($ts===false) throw new AppException('DATA_NON_VALIDA','Formato scadenza non valido');
        if($ts<=time()) throw new AppException('DATA_NEL_PASSATO','La scadenza deve essere futura');
        $pdo=$this->db->getPdo(); $pdo->beginTransaction(); $fotoSalvate=[];
        try{
            $prodotto_id = isset($d['prodotto_id']) && $d['prodotto_id']!=='' ? (int)$d['prodotto_id'] : 0;
            if($prodotto_id<1){
                $nome=trim((string)($d['nome']??'')); if($nome==='') throw new AppException('NOME_MANCANTE','Nome prodotto obbligatorio'); if(mb_strlen($nome)>80) throw new AppException('NOME_TROPPO_LUNGO','Massimo 80 caratteri');
                $fid=(int)($d['id_fornitore']??0); if($fid<1) throw new AppException('FORNITORE_MANCANTE','Fornitore obbligatorio');
                $st=$pdo->prepare('SELECT id FROM fornitori WHERE id = ?'); $st->execute([$fid]); if(!$st->fetch()) throw new AppException('FORNITORE_INESISTENTE','Fornitore non trovato',404);
                $prezzoCorr=(float)($d['prezzo_corrente']??0); if($prezzoCorr<=0) throw new AppException('PREZZO_NON_VALIDO','Prezzo scontato non valido');
                $prezzoBase=isset($d['prezzo_base']) && $d['prezzo_base']!=='' ? (float)$d['prezzo_base'] : $prezzoCorr; if($prezzoBase<=0) throw new AppException('PREZZO_NON_VALIDO','Prezzo di listino non valido');
                $moq=isset($d['quantita_minima']) && $d['quantita_minima']!=='' ? (int)$d['quantita_minima'] : 1; if($moq<1) throw new AppException('MOQ_NON_VALIDO','Quantita minima deve essere almeno 1');
                $cat=isset($d['id_categoria']) && $d['id_categoria']!=='' ? (int)$d['id_categoria'] : null;
                $pdo->prepare('INSERT INTO prodotti (id_fornitore, id_categoria, nome, descrizione, prezzo_unitario, prezzo_base, quantita_minima, stato) VALUES (?,?,?,?,?,?,?, \'attivo\')')->execute([$fid,$cat,mb_substr($nome,0,80),trim((string)($d['descrizione']??'')),$prezzoCorr,$prezzoBase,$moq]);
                $prodotto_id=(int)$pdo->lastInsertId(); $p=['quantita_minima'=>$moq,'prezzo_unitario'=>$prezzoCorr,'prezzo_base'=>$prezzoBase];
            } else {
                $st=$pdo->prepare('SELECT quantita_minima, prezzo_unitario, prezzo_base FROM prodotti WHERE id = ?'); $st->execute([$prodotto_id]); $p=$st->fetch(); if(!$p) throw new AppException('PRODOTTO_INESISTENTE','Prodotto non trovato',404);
            }
            $prezzoCorrC=isset($d['prezzo_corrente']) && $d['prezzo_corrente']!=='' ? (float)$d['prezzo_corrente'] : (float)$p['prezzo_unitario'];
            $prezzoBaseC=isset($d['prezzo_base']) && $d['prezzo_base']!=='' ? (float)$d['prezzo_base'] : (float)($p['prezzo_base']??$prezzoCorrC);
            $moqC=isset($d['quantita_minima']) && $d['quantita_minima']!=='' ? (int)$d['quantita_minima'] : (int)$p['quantita_minima'];
            if($prezzoCorrC<=0||$prezzoBaseC<=0) throw new AppException('PREZZO_NON_VALIDO','Prezzi non validi'); if($moqC<1) throw new AppException('MOQ_NON_VALIDO','Quantita minima deve essere almeno 1');
            $comm=isset($d['percentuale_commissione']) && $d['percentuale_commissione']!=='' ? (float)$d['percentuale_commissione'] : 10.0; if($comm<0||$comm>100) throw new AppException('COMMISSIONE_NON_VALIDA','Commissione tra 0 e 100');
            $st=$pdo->prepare('INSERT INTO collette (id_prodotto, id_aperta_da, id_referente, id_sede, quantita_minima, data_limite, prezzo_base, prezzo_corrente, percentuale_commissione) VALUES (?,?,?,?,?,?,?,?,?)');
            $st->execute([$prodotto_id,$io,$io,isset($d['sede_id']) && $d['sede_id']!==''?(int)$d['sede_id']:null,$moqC,date('Y-m-d H:i:s',$ts),round($prezzoBaseC,2),round($prezzoCorrC,2),round($comm,2)]);
            $id=(int)$pdo->lastInsertId();
            // photos: handle Symfony UploadedFile and legacy $_FILES
            $files=[];
            foreach ($request->files->all() as $key=>$val) {
                if($key==='foto'){
                    if(is_array($val)) foreach($val as $f) $files[]=$f;
                    else $files[]=$val;
                }
            }
            // legacy fallback $_FILES['foto']
            if(empty($files) && !empty($_FILES['foto'])){
                $f=$_FILES['foto'];
                if(is_array($f['error'])) foreach($f['error'] as $i=>$err) if($err!==UPLOAD_ERR_NO_FILE) $files[]=['name'=>$f['name'][$i],'type'=>$f['type'][$i],'tmp_name'=>$f['tmp_name'][$i],'error'=>$err,'size'=>$f['size'][$i]];
                elseif(($f['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE) $files[]=$f;
            }
            // Convert Symfony UploadedFile to array format
            $normFiles=[];
            foreach($files as $f){
                if($f instanceof \Symfony\Component\HttpFoundation\File\UploadedFile){
                    $normFiles[]=['name'=>$f->getClientOriginalName(),'type'=>$f->getMimeType(),'tmp_name'=>$f->getPathname(),'error'=>$f->getError(),'size'=>$f->getSize()];
                } else $normFiles[]=$f;
            }
            $ord=0;
            foreach($normFiles as $i=>$f){
                $url=$this->fornitoreService->salvaFoto($f); $fotoSalvate[]=$url;
                $pdo->prepare('INSERT INTO immagini_prodotto (id_prodotto, url, ordine, principale) VALUES (?,?,?,?)')->execute([$prodotto_id,$url,$ord++,($i===0?1:0)]);
            }
            $pdo->commit();
            return ApiResponse::ok(['id'=>$id,'id_prodotto'=>$prodotto_id],201);
        }catch(\Throwable $e){ if($pdo->inTransaction()) $pdo->rollBack(); foreach($fotoSalvate as $u) @unlink(dirname(__DIR__,2).'/public/'.$u); @unlink(dirname(__DIR__,2).'/'.$u); throw $e; }
    }

    #[Route('/api/admin/ordini', methods: ['GET'])]
    #[Route('/admin/ordini', methods: ['GET'])]
    public function adminOrdini(): JsonResponse
    {
        $this->auth->richiediLogin(); if(!$this->auth->sonoAdmin()) throw new AppException('NON_ADMIN','Accesso riservato agli amministratori',403);
        $st=$this->db->getPdo()->query("SELECT c.id, c.stato, c.quantita_minima, c.quantita_attuale, c.data_limite, pr.nome AS prodotto, f.nome_azienda AS fornitore FROM collette c JOIN prodotti pr ON pr.id = c.id_prodotto JOIN fornitori f ON f.id = pr.id_fornitore WHERE (SELECT COUNT(*) FROM prenotazioni WHERE id_colletta = c.id) > 0 ORDER BY c.data_limite ASC");
        $campagne=$st->fetchAll();
        foreach($campagne as &$c){ $c=$this->normalizza($c); $c['stato']=$this->stato->ricalcolaStato($c['id']); $st2=$this->db->getPdo()->prepare('SELECT p.id, p.id_utente, u.nome, u.cognome, p.quantita, p.stato FROM prenotazioni p JOIN utenti u ON u.id = p.id_utente WHERE p.id_colletta = ? ORDER BY p.data_prenotazione ASC'); $st2->execute([$c['id']]); $c['partecipazioni']=$st2->fetchAll(); }
        return ApiResponse::ok($campagne);
    }

    #[Route('/api/campagne/{id}/invia-fornitore', methods: ['POST'], requirements: ['id'=>'\d+'])]
    #[Route('/campagne/{id}/invia-fornitore', methods: ['POST'], requirements: ['id'=>'\d+'])]
    public function inviaFornitore(int $id): JsonResponse
    {
        $io=$this->auth->richiediLogin(); if(!$this->auth->sonoAdmin()) throw new AppException('NON_ADMIN','Accesso riservato agli amministratori',403);
        $ris=$this->inviaOrdineAl($id,$io);
        return ApiResponse::ok(['messaggio'=>'Ordine inviato al fornitore con successo.','totale_pezzi'=>$ris['totale_pezzi'],'importo_ordine'=>$ris['importo_ordine']]);
    }

    public function inviaOrdineAl(int $colletta_id, ?int $id_admin): array
    {
        $pdo=$this->db->getPdo(); $own=!$pdo->inTransaction(); if($own) $pdo->beginTransaction();
        try{
            $st=$pdo->prepare('SELECT id, stato, id_prodotto, quantita_attuale, prezzo_corrente FROM collette WHERE id = ? FOR UPDATE'); $st->execute([$colletta_id]); $c=$st->fetch(); if(!$c) throw new AppException('CAMPAGNA_INESISTENTE','Campagna non trovata',404);
            if($c['stato']!=='ordine_pronto') throw new AppException('STATO_NON_VALIDO','La campagna deve essere in stato "ordine_pronto". Stato attuale: '.$c['stato']);
            $st=$pdo->prepare('SELECT COUNT(*) FROM prenotazioni WHERE id_colletta = ? AND stato != \'pagata\''); $st->execute([$colletta_id]); $nonPagati=(int)$st->fetchColumn(); if($nonPagati>0) throw new AppException('PAGAMENTI_INCOMPLETI',"Ci sono ancora $nonPagati partecipanti che non hanno pagato.");
            $pezzi=(int)$c['quantita_attuale']; $importo_totale=round($pezzi*(float)$c['prezzo_corrente'],2);
            $st=$pdo->prepare('SELECT id_fornitore FROM prodotti WHERE id = ?'); $st->execute([(int)$c['id_prodotto']]); $fornitore_id=(int)$st->fetchColumn();
            $st=$pdo->prepare('SELECT id FROM ordini_fornitore WHERE id_colletta = ?'); $st->execute([$colletta_id]); $ordineEsistente=$st->fetch();
            if($ordineEsistente){ $st=$pdo->prepare('UPDATE ordini_fornitore SET id_fornitore = ?, id_admin = ?, quantita_ordinata = ?, importo_totale = ?, stato = \'inviato\', data_ordine = NOW() WHERE id = ?'); $st->execute([$fornitore_id,$id_admin,$pezzi,$importo_totale,(int)$ordineEsistente['id']]); }
            else { $st=$pdo->prepare('INSERT INTO ordini_fornitore (id_colletta, id_fornitore, id_admin, quantita_ordinata, importo_totale, stato, data_ordine) VALUES (?,?,?,?,?,\'inviato\',NOW())'); $st->execute([$colletta_id,$fornitore_id,$id_admin,$pezzi,$importo_totale]); }
            $st=$pdo->prepare('UPDATE collette SET stato = \'ordine_fornitore\', data_agg_stato = NOW() WHERE id = ?'); $st->execute([$colletta_id]);
            $stProdotto=$pdo->prepare('SELECT pr.nome FROM prodotti pr WHERE pr.id = ?'); $stProdotto->execute([(int)$c['id_prodotto']]); $nomeProdotto=$stProdotto->fetchColumn()?:'un prodotto';
            $stFornitore=$pdo->prepare('SELECT nome_azienda FROM fornitori WHERE id = ?'); $stFornitore->execute([$fornitore_id]); $nomeFornitore=$stFornitore->fetchColumn()?:'il fornitore';
            $stUtenti=$pdo->prepare('SELECT DISTINCT id_utente FROM prenotazioni WHERE id_colletta = ?'); $stUtenti->execute([$colletta_id]); $utenti=$stUtenti->fetchAll(\PDO::FETCH_COLUMN);
            $stNotifica=$pdo->prepare('INSERT INTO notifiche (id_utente, tipo, titolo, messaggio, tipo_riferimento, id_riferimento) VALUES (?, \'ORDINE_INVIATO\', \'Ordine Inviato\', ?, \'colletta\', ?)');
            foreach($utenti as $uid){ $messaggio="L'ordine per \"$nomeProdotto\" è stato inviato al fornitore $nomeFornitore. Riceverai una notifica quando sarà consegnato."; $stNotifica->execute([$uid,$messaggio,$colletta_id]); }
            if($own) $pdo->commit();
            return ['totale_pezzi'=>$pezzi,'importo_ordine'=>$importo_totale];
        }catch(\Throwable $e){ if($own && $pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }

    #[Route('/api/campagne/{id}/modifica', methods: ['POST'], requirements: ['id'=>'\d+'])]
    #[Route('/campagne/{id}/modifica', methods: ['POST'], requirements: ['id'=>'\d+'])]
    public function modifica(int $id, Request $request): JsonResponse
    {
        if(!$this->auth->sonoAdmin()) throw new AppException('NON_AUTORIZZATO','Solo gli admin',403);
        $raw=$request->getContent(); $isMultipart=$request->request->count()>0 || $request->files->count()>0;
        if($isMultipart){ $d=array_map(fn($v)=>is_string($v)?trim($v):$v,$request->request->all()); }
        elseif($raw!=='' && $raw!==false){ $d=json_decode($raw,true); if(!is_array($d)) throw new AppException('JSON_NON_VALIDO','JSON non valido'); }
        else $d=[];
        if(!empty($_POST) && empty($d)) $d=array_map(fn($v)=>trim((string)$v),$_POST);
        $hasFoto=$request->files->has('foto') || (!empty($_FILES['foto']) && (($_FILES['foto']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE));
        $pdo=$this->db->getPdo(); $pdo->beginTransaction();
        try{
            $st=$pdo->prepare('SELECT id, stato, id_prodotto, quantita_attuale FROM collette WHERE id = ? FOR UPDATE'); $st->execute([$id]); $c=$st->fetch(); if(!$c){ $pdo->rollBack(); throw new AppException('CAMPAGNA_NON_TROVATA','Campagna non trovata',404); }
            if(in_array($c['stato'],['ordine_fornitore','consegnata'],true)){ $pdo->rollBack(); throw new AppException('CAMPAGNA_NON_MODIFICABILE','Campagna non modificabile: ordine gia\' partito',409); }
            $campi=[];$par=[];
            if(isset($d['data_limite']) && $d['data_limite']!==''){ $dt=trim((string)$d['data_limite']); $ts=strtotime($dt); if($ts===false||$ts<time()){ $pdo->rollBack(); throw new AppException('SCADENZA_NON_VALIDA','La scadenza deve essere una data futura'); } $campi[]='data_limite = ?'; $par[]=date('Y-m-d H:i:s',$ts); }
            if(isset($d['quantita_minima']) && $d['quantita_minima']!==''){ $moq=(int)$d['quantita_minima']; if($moq<1){ $pdo->rollBack(); throw new AppException('MOQ_NON_VALIDO','MOQ deve essere almeno 1'); } $campi[]='quantita_minima = ?'; $par[]=$moq; }
            if(isset($d['prezzo_corrente']) && $d['prezzo_corrente']!==''){ $pc=(float)$d['prezzo_corrente']; if($pc<=0){ $pdo->rollBack(); throw new AppException('PREZZO_NON_VALIDO','Prezzo corrente deve essere maggiore di 0'); } $campi[]='prezzo_corrente = ?'; $par[]=round($pc,2); }
            if(isset($d['prezzo_base']) && $d['prezzo_base']!==''){ $pb=(float)$d['prezzo_base']; if($pb<=0){ $pdo->rollBack(); throw new AppException('PREZZO_NON_VALIDO','Prezzo base deve essere maggiore di 0'); } $campi[]='prezzo_base = ?'; $par[]=round($pb,2); }
            if(isset($d['percentuale_commissione']) && $d['percentuale_commissione']!==''){ $comm=(float)$d['percentuale_commissione']; if($comm<0||$comm>100){ $pdo->rollBack(); throw new AppException('COMMISSIONE_NON_VALIDA','Commissione deve essere tra 0 e 100'); } $campi[]='percentuale_commissione = ?'; $par[]=round($comm,2); }
            if(!empty($campi)){ $par[]=$id; $pdo->prepare('UPDATE collette SET '.implode(', ',$campi).' WHERE id = ?')->execute($par); }
            // foto principale
            $fotoFile = $request->files->get('foto');
            if(!$fotoFile && !empty($_FILES['foto'])) $fotoFile = $_FILES['foto'];
            $nuovaFoto = null;
            if($fotoFile){
                $arr = $fotoFile instanceof \Symfony\Component\HttpFoundation\File\UploadedFile ? ['name'=>$fotoFile->getClientOriginalName(),'type'=>$fotoFile->getMimeType(),'tmp_name'=>$fotoFile->getPathname(),'error'=>$fotoFile->getError(),'size'=>$fotoFile->getSize()] : $fotoFile;
                if(($arr['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){
                    $nuova = $this->fornitoreService->salvaFoto($arr);
                    try{
                        $idProdotto=(int)$c['id_prodotto'];
                        $stUrl=$pdo->prepare('SELECT url FROM immagini_prodotto WHERE id_prodotto = ? AND principale = 1'); $stUrl->execute([$idProdotto]); $vecchie=$stUrl->fetchAll(\PDO::FETCH_COLUMN);
                        $pdo->prepare('UPDATE immagini_prodotto SET principale = 0 WHERE id_prodotto = ?')->execute([$idProdotto]);
                        $pdo->prepare('INSERT INTO immagini_prodotto (id_prodotto, url, ordine, principale) VALUES (?,?,0,1)')->execute([$idProdotto,$nuova]);
                        foreach($vecchie as $v){ @unlink(dirname(__DIR__,2).'/public/'.$v); @unlink(dirname(__DIR__,2).'/'.$v); $pdo->prepare('DELETE FROM immagini_prodotto WHERE id_prodotto = ? AND principale = 0 AND url = ?')->execute([$idProdotto,$v]); }
                        $nuovaFoto=$nuova;
                    }catch(\Throwable $e){ @unlink(dirname(__DIR__,2).'/public/'.$nuova); throw $e; }
                }
            }
            // foto_extra
            $extraFoto=[];
            if($request->files->has('foto_extra')){
                $fe=$request->files->get('foto_extra'); if(!is_array($fe)) $fe=[$fe]; foreach($fe as $f) $extraFoto[]=$f;
            }
            if(!empty($_FILES['foto_extra'])){
                $fe=$_FILES['foto_extra']; if(is_array($fe['error'])) foreach($fe['error'] as $i=>$err) if($err!==UPLOAD_ERR_NO_FILE) $extraFoto[]=['name'=>$fe['name'][$i],'type'=>$fe['type'][$i],'tmp_name'=>$fe['tmp_name'][$i],'error'=>$err,'size'=>$fe['size'][$i]];
            }
            // Normalize UploadedFile
            $normExtra=[];
            foreach($extraFoto as $f){ if($f instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) $normExtra[]=['name'=>$f->getClientOriginalName(),'type'=>$f->getMimeType(),'tmp_name'=>$f->getPathname(),'error'=>$f->getError(),'size'=>$f->getSize()]; else $normExtra[]=$f; }
            $extraFoto=$normExtra;
            if(!empty($extraFoto)){
                $idProdotto=(int)$c['id_prodotto']; $stMax=$pdo->prepare('SELECT COALESCE(MAX(ordine), -1) FROM immagini_prodotto WHERE id_prodotto = ?'); $stMax->execute([$idProdotto]); $nextOrd=(int)$stMax->fetchColumn()+1; $salvate=[];
                try{ foreach($extraFoto as $f){ $url=$this->fornitoreService->salvaFoto($f); $salvate[]=$url; $pdo->prepare('INSERT INTO immagini_prodotto (id_prodotto, url, ordine, principale) VALUES (?,?,?,0)')->execute([$idProdotto,$url,$nextOrd++]); } }catch(\Throwable $e){ foreach($salvate as $u) @unlink(dirname(__DIR__,2).'/public/'.$u); throw $e; }
            }
            if(empty($campi) && !$nuovaFoto && empty($extraFoto)){ $pdo->rollBack(); throw new AppException('NESSUN_CAMPO','Nessun campo da aggiornare'); }
            $nuovoStato=$this->stato->ricalcolaStato($id);
            $pdo->commit();
            return ApiResponse::ok(['id'=>$id,'stato'=>$nuovoStato]);
        }catch(\Throwable $e){ if($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }
}
