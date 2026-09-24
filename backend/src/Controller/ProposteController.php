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

class ProposteController extends AbstractController
{
    public function __construct(private DatabaseService $db, private AuthService $auth) {}

    #[Route('/api/proposte', methods: ['GET'])]
    #[Route('/proposte', methods: ['GET'])]
    public function elenco(): JsonResponse
    {
        $io=$this->auth->richiediLogin();
        $isAdmin=$this->auth->sonoAdmin();
        if($isAdmin) $st=$this->db->getPdo()->query('SELECT pp.*, u.nome AS proponente_nome, f.nome_azienda AS fornitore_suggerito, (SELECT COUNT(*) FROM voti_proposte WHERE id_proposta=pp.id_proposta AND valore_voto=\'favore\') AS voti_favore FROM proposte_prodotti pp LEFT JOIN utenti u ON u.id=pp.proponente_id LEFT JOIN fornitori f ON f.id=pp.id_fornitore_suggerito ORDER BY pp.data_proposta DESC');
        else { $st=$this->db->getPdo()->prepare('SELECT pp.*, u.nome AS proponente_nome, f.nome_azienda AS fornitore_suggerito, (SELECT COUNT(*) FROM voti_proposte WHERE id_proposta=pp.id_proposta AND valore_voto=\'favore\') AS voti_favore FROM proposte_prodotti pp LEFT JOIN utenti u ON u.id=pp.proponente_id LEFT JOIN fornitori f ON f.id=pp.id_fornitore_suggerito WHERE pp.stato NOT IN (\'rifiutata\',\'respinta_votazione\') OR pp.proponente_id=? ORDER BY pp.data_proposta DESC'); $st->execute([$io]); }
        $out=$st->fetchAll();
        foreach($out as &$p){ $p['id_proposta']=(int)$p['id_proposta']; $p['voti_favore']=(int)($p['voti_favore']??0); }
        return ApiResponse::ok($out);
    }

    #[Route('/api/proposte', methods: ['POST'])]
    #[Route('/proposte', methods: ['POST'])]
    public function crea(Request $request): JsonResponse
    {
        $io=$this->auth->richiediUtenteAttivo();
        $d=ApiResponse::corpo($request);
        // support multipart via $request->request? but body is JSON
        if(empty($d) && $request->request->count()>0) $d=$request->request->all();
        $nome=trim((string)($d['nome_prodotto']??$d['nome']??'')); if($nome==='') throw new AppException('NOME_MANCANTE','Nome prodotto obbligatorio');
        $descrizione=trim((string)($d['descrizione']??''));
        $fid = isset($d['id_fornitore_suggerito']) && $d['id_fornitore_suggerito']!=='' ? (int)$d['id_fornitore_suggerito'] : null;
        $ruolo=$this->auth->sonoFornitore()?'fornitore':'cliente';
        // if fornitore, ensure his id
        if($ruolo==='fornitore'){ $fidForn=$this->auth->fornitoreIdCorrente(); if($fid===null) $fid=$fidForn; }
        $st=$this->db->getPdo()->prepare('INSERT INTO proposte_prodotti (proponente_tipo, proponente_id, nome_prodotto, descrizione, id_fornitore_suggerito, stato) VALUES (?,?,?,?,?, \'in_attesa\')');
        $st->execute([$ruolo,$io,$nome,$descrizione,$fid]);
        return ApiResponse::ok(['id_proposta'=>(int)$this->db->getPdo()->lastInsertId()],201);
    }

    #[Route('/api/proposte/{id}/vota', methods: ['POST'], requirements: ['id'=>'\d+'])]
    #[Route('/proposte/{id}/vota', methods: ['POST'], requirements: ['id'=>'\d+'])]
    public function vota(int $id, Request $request): JsonResponse
    {
        $io=$this->auth->richiediUtenteAttivo();
        $d=ApiResponse::corpo($request); $valore=trim((string)($d['valore']??$d['valore_voto']??'favore')); if(!in_array($valore,['favore','contrario'],true)) $valore='favore';
        $st=$this->db->getPdo()->prepare('SELECT stato FROM proposte_prodotti WHERE id_proposta=?'); $st->execute([$id]); $p=$st->fetch(); if(!$p) throw new AppException('PROPOSTA_INESISTENTE','Proposta non trovata',404);
        if($p['stato']!=='in_votazione') throw new AppException('VOTAZIONE_NON_APERTA','Proposta non in votazione');
        try{ $st=$this->db->getPdo()->prepare('INSERT INTO voti_proposte (id_proposta, id_utente, valore_voto) VALUES (?,?,?)'); $st->execute([$id,$io,$valore]); }catch(\PDOException $e){ if($e->getCode()==='23000'){ $st=$this->db->getPdo()->prepare('UPDATE voti_proposte SET valore_voto=? WHERE id_proposta=? AND id_utente=?'); $st->execute([$valore,$id,$io]); } else throw $e; }
        $st=$this->db->getPdo()->prepare('UPDATE proposte_prodotti SET tot_voti=(SELECT COUNT(*) FROM voti_proposte WHERE id_proposta=? AND valore_voto=\'favore\') WHERE id_proposta=?'); $st->execute([$id,$id]);
        return ApiResponse::ok(['votato'=>true]);
    }

    #[Route('/api/proposte/{id}/vota', methods: ['DELETE'], requirements: ['id'=>'\d+'])]
    #[Route('/proposte/{id}/vota', methods: ['DELETE'], requirements: ['id'=>'\d+'])]
    public function ritiraVoto(int $id): JsonResponse
    {
        $io=$this->auth->richiediLogin();
        $st=$this->db->getPdo()->prepare('DELETE FROM voti_proposte WHERE id_proposta=? AND id_utente=?'); $st->execute([$id,$io]);
        $st=$this->db->getPdo()->prepare('UPDATE proposte_prodotti SET tot_voti=(SELECT COUNT(*) FROM voti_proposte WHERE id_proposta=? AND valore_voto=\'favore\') WHERE id_proposta=?'); $st->execute([$id,$id]);
        return ApiResponse::ok(['ritirato'=>true]);
    }

    #[Route('/api/proposte/{id}/stato', methods: ['PUT'], requirements: ['id'=>'\d+'])]
    #[Route('/proposte/{id}/stato', methods: ['PUT'], requirements: ['id'=>'\d+'])]
    public function cambiaStato(int $id, Request $request): JsonResponse
    {
        if(!$this->auth->sonoAdmin()) throw new AppException('NON_AUTORIZZATO','Solo gli admin',403);
        $d=ApiResponse::corpo($request); $nuovo=trim((string)($d['stato']??'')); $motivo=$d['motivo']??null;
        if(!in_array($nuovo,['in_votazione','approvata_admin','rifiutata','pubblicata','respinta_votazione'],true)) throw new AppException('STATO_NON_VALIDO','Stato non valido');
        $pdo=$this->db->getPdo(); $pdo->beginTransaction();
        try{
            $st=$pdo->prepare('SELECT * FROM proposte_prodotti WHERE id_proposta=? FOR UPDATE'); $st->execute([$id]); $p=$st->fetch(); if(!$p) { $pdo->rollBack(); throw new AppException('PROPOSTA_INESISTENTE','Proposta non trovata',404); }
            if($nuovo==='pubblicata' && !in_array($p['stato'],['in_votazione','approvata_admin'],true)){ $pdo->rollBack(); throw new AppException('STATO_NON_VALIDO','Solo proposte in votazione o approvate possono essere pubblicate'); }
            $st=$pdo->prepare('SELECT id FROM prodotti WHERE id_proposta_origine=?'); $st->execute([$id]); if($st->fetch() && $nuovo==='pubblicata'){ $pdo->rollBack(); throw new AppException('GIA_PUBBLICATA','Proposta già pubblicata',409); }
            $io=$this->auth->utenteCorrenteId();
            $pdo->prepare('UPDATE proposte_prodotti SET stato=?, id_admin_gestione=?, data_gestione=NOW(), motivo_rifiuto=? WHERE id_proposta=?')->execute([$nuovo,$io,$motivo,$id]);
            // notify proponente
            $titolo = match($nuovo){ 'approvata_admin'=>'Proposta Approvata','rifiutata'=>'Proposta Rifiutata','pubblicata'=>'Proposta Pubblicata', default=>'Aggiornamento Proposta' };
            $tipo = $nuovo==='approvata_admin'?'PROPOSTA_APPROVATA':'SISTEMA';
            $pdo->prepare('INSERT INTO notifiche (id_utente, tipo, titolo, messaggio, tipo_riferimento, id_riferimento) VALUES (?, ?, ?, ?, \'proposta\', ?)')->execute([(int)$p['proponente_id'],$tipo,$titolo,"La tua proposta \"{$p['nome_prodotto']}\" è stata $nuovo",$id]);
            if($nuovo==='pubblicata'){
                $this->autoCrea($p,$io,$d,$pdo);
                // broadcast to all clienti attivi
                $stU=$pdo->query('SELECT id FROM utenti WHERE stato=\'attivo\' AND ruolo=\'cliente\''); $utenti=$stU->fetchAll(\PDO::FETCH_COLUMN);
                $stN=$pdo->prepare('INSERT INTO notifiche (id_utente, tipo, titolo, messaggio, tipo_riferimento, id_riferimento) VALUES (?, \'ORDINE_DISPONIBILE\', \'Nuovo Prodotto Disponibile\', ?, \'proposta\', ?)');
                foreach($utenti as $uid){ $stN->execute([$uid,"Nuovo prodotto \"{$p['nome_prodotto']}\" disponibile per la votazione!",$id]); }
            }
            $pdo->commit();
            return ApiResponse::ok(['stato'=>$nuovo]);
        }catch(\Throwable $e){ if($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }

    private function autoCrea(array $p,int $admin, array $pub, \PDO $pdo): void
    {
        $fornitoreId = isset($pub['id_fornitore']) && $pub['id_fornitore']!=='' ? (int)$pub['id_fornitore'] : ($p['id_fornitore_suggerito'] ?? null);
        if(!$fornitoreId){
            $st=$pdo->prepare('SELECT id FROM fornitori WHERE id_utente=?'); $st->execute([$p['proponente_id']]); $fornitoreId=$st->fetchColumn()?:null;
            if(!$fornitoreId) $fornitoreId=(int)($pdo->query('SELECT id FROM fornitori LIMIT 1')->fetchColumn());
        }
        $st=$pdo->prepare('SELECT soglia, prezzo FROM proposta_scaglioni WHERE id_proposta=? ORDER BY soglia ASC'); $st->execute([$p['id_proposta']]); $scaglioni=$st->fetchAll();
        $prezzoBase = isset($pub['prezzo_base']) && $pub['prezzo_base']!=='' ? (float)$pub['prezzo_base'] : ($p['prezzo_base'] ?? (!empty($scaglioni)?(float)$scaglioni[0]['prezzo']:10.0));
        $prezzoCorr = isset($pub['prezzo_corrente']) && $pub['prezzo_corrente']!=='' ? (float)$pub['prezzo_corrente'] : ($p['prezzo_corrente'] ?? $prezzoBase);
        $moq = isset($pub['moq']) && $pub['moq']!=='' ? (int)$pub['moq'] : ((int)($p['moq_richiesto']??1) ?:1);
        $scadenza = isset($pub['scadenza']) && $pub['scadenza']!=='' ? date('Y-m-d H:i:s',strtotime($pub['scadenza'])) : date('Y-m-d H:i:s', strtotime('+30 days'));
        $comm = isset($pub['commissione']) && $pub['commissione']!=='' ? (float)$pub['commissione'] : 10.0;
        $pdo->prepare('INSERT INTO prodotti (id_fornitore, id_proposta_origine, nome, descrizione, prezzo_unitario, prezzo_base, quantita_minima, stato) VALUES (?,?,?,?,?,?,?, \'attivo\')')->execute([$fornitoreId,$p['id_proposta'],$p['nome_prodotto'],$p['descrizione'],$prezzoCorr,$prezzoBase,$moq]);
        $prodId=(int)$pdo->lastInsertId();
        if(!empty($p['foto_path'])) $pdo->prepare('INSERT INTO immagini_prodotto (id_prodotto, url, ordine, principale) VALUES (?,?,0,1)')->execute([$prodId,$p['foto_path']]);
        $pdo->prepare('INSERT INTO collette (id_prodotto, id_aperta_da, id_referente, quantita_minima, data_limite, prezzo_base, prezzo_corrente, percentuale_commissione, stato) VALUES (?,?,?,?,?,?,?,?, \'in_corso\')')->execute([$prodId,$admin,$admin,$moq,$scadenza,$prezzoBase,$prezzoCorr,$comm]);
        $collettaId=(int)$pdo->lastInsertId();
        foreach($scaglioni as $s){ $pdo->prepare('INSERT INTO scaglioni_prezzo (id_colletta, soglia, prezzo) VALUES (?,?,?)')->execute([$collettaId,$s['soglia'],$s['prezzo']]); }
    }
}
