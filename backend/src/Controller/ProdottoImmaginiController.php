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

class ProdottoImmaginiController extends AbstractController
{
    public function __construct(private DatabaseService $db, private AuthService $auth, private FornitoreService $fornitoreService) {}

    #[Route('/api/admin/prodotti/{id}/immagini', methods: ['GET'], requirements: ['id'=>'\d+'])]
    #[Route('/admin/prodotti/{id}/immagini', methods: ['GET'], requirements: ['id'=>'\d+'])]
    public function elenco(int $id): JsonResponse
    {
        if(!$this->auth->sonoAdmin()) throw new AppException('NON_AUTORIZZATO','Solo gli admin',403);
        $st=$this->db->getPdo()->prepare('SELECT id, url, ordine, principale FROM immagini_prodotto WHERE id_prodotto = ? ORDER BY principale DESC, ordine ASC'); $st->execute([$id]);
        $out=array_map(fn($r)=>['id'=>(int)$r['id'],'url'=>$r['url'],'ordine'=>(int)$r['ordine'],'principale'=>(int)$r['principale']],$st->fetchAll());
        return ApiResponse::ok($out);
    }

    #[Route('/api/admin/prodotti/{id}/immagini', methods: ['POST'], requirements: ['id'=>'\d+'])]
    #[Route('/admin/prodotti/{id}/immagini', methods: ['POST'], requirements: ['id'=>'\d+'])]
    public function aggiungi(int $id, Request $request): JsonResponse
    {
        if(!$this->auth->sonoAdmin()) throw new AppException('NON_AUTORIZZATO','Solo gli admin',403);
        $pdo=$this->db->getPdo(); $st=$pdo->prepare('SELECT id FROM prodotti WHERE id = ?'); $st->execute([$id]); if(!$st->fetch()) throw new AppException('PRODOTTO_INESISTENTE','Prodotto non trovato',404);
        $files=[];
        foreach($request->files->all() as $key=>$val){ if($key==='foto'){ if(is_array($val)) foreach($val as $f) $files[]=$f; else $files[]=$val; } }
        if(empty($files) && !empty($_FILES['foto'])){ $f=$_FILES['foto']; if(is_array($f['error'])) foreach($f['error'] as $i=>$err) if($err!==UPLOAD_ERR_NO_FILE) $files[]=['name'=>$f['name'][$i],'type'=>$f['type'][$i],'tmp_name'=>$f['tmp_name'][$i],'error'=>$err,'size'=>$f['size'][$i]]; elseif(($f['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE) $files[]=$f; }
        if(empty($files)) throw new AppException('FOTO_MANCANTE','Nessuna immagine da caricare');
        $norm=[]; foreach($files as $f){ if($f instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) $norm[]=['name'=>$f->getClientOriginalName(),'type'=>$f->getMimeType(),'tmp_name'=>$f->getPathname(),'error'=>$f->getError(),'size'=>$f->getSize()]; else $norm[]=$f; } $files=$norm;
        $stMax=$pdo->prepare('SELECT COALESCE(MAX(ordine), -1) FROM immagini_prodotto WHERE id_prodotto = ?'); $stMax->execute([$id]); $nextOrd=(int)$stMax->fetchColumn()+1;
        $stPrinc=$pdo->prepare('SELECT COUNT(*) FROM immagini_prodotto WHERE id_prodotto = ? AND principale = 1'); $stPrinc->execute([$id]); $haPrincipale=((int)$stPrinc->fetchColumn())>0;
        $salvate=[]; $inserite=[];
        try{ foreach($files as $f){ $url=$this->fornitoreService->salvaFoto($f); $salvate[]=$url; $princ=(!$haPrincipale && empty($inserite))?1:0; $pdo->prepare('INSERT INTO immagini_prodotto (id_prodotto, url, ordine, principale) VALUES (?,?,?,?)')->execute([$id,$url,$nextOrd++,$princ]); $inserite[]=['id'=>(int)$pdo->lastInsertId(),'url'=>$url]; } }catch(\Throwable $e){ foreach($salvate as $u) @unlink(dirname(__DIR__,2).'/public/'.$u); throw $e; }
        return ApiResponse::ok($inserite);
    }

    #[Route('/api/admin/immagini/{id}', methods: ['DELETE'], requirements: ['id'=>'\d+'])]
    #[Route('/admin/immagini/{id}', methods: ['DELETE'], requirements: ['id'=>'\d+'])]
    public function elimina(int $id): JsonResponse
    {
        if(!$this->auth->sonoAdmin()) throw new AppException('NON_AUTORIZZATO','Solo gli admin',403);
        $pdo=$this->db->getPdo(); $st=$pdo->prepare('SELECT id_prodotto, url, principale FROM immagini_prodotto WHERE id = ?'); $st->execute([$id]); $img=$st->fetch(); if(!$img) throw new AppException('IMMAGINE_INESISTENTE','Immagine non trovata',404);
        $st=$pdo->prepare('SELECT COUNT(*) FROM immagini_prodotto WHERE id_prodotto = ?'); $st->execute([$img['id_prodotto']]); if((int)$st->fetchColumn()<=1) throw new AppException('ULTIMA_IMMAGINE','Impossibile eliminare l\'unica immagine del prodotto',409);
        $pdo->prepare('DELETE FROM immagini_prodotto WHERE id = ?')->execute([$id]);
        if((int)$img['principale']===1) $pdo->prepare('UPDATE immagini_prodotto SET principale = 1 WHERE id_prodotto = ? ORDER BY ordine ASC LIMIT 1')->execute([$img['id_prodotto']]);
        @unlink(dirname(__DIR__,2).'/public/'.$img['url']); @unlink(dirname(__DIR__,2).'/'.$img['url']);
        return ApiResponse::ok(['eliminata'=>true]);
    }
}
