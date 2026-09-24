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

class CatalogoController extends AbstractController
{
    public function __construct(private DatabaseService $db, private AuthService $auth) {}

    #[Route('/api/fornitori', methods: ['GET'])]
    #[Route('/fornitori', methods: ['GET'])]
    public function fornitori(): JsonResponse
    {
        $this->auth->richiediLogin();
        $st = $this->db->getPdo()->query(
            'SELECT f.id, f.nome_azienda AS nome, f.indirizzo, f.descrizione, f.logo_url, f.sito_web,
                    f.categoria, f.email_contatto, f.telefono,
                    COUNT(DISTINCT c.id) AS num_campagne
               FROM fornitori f
          LEFT JOIN prodotti p ON p.id_fornitore = f.id
          LEFT JOIN collette c ON c.id_prodotto = p.id
              WHERE f.partner_pubblico = 1
              GROUP BY f.id
              ORDER BY f.nome_azienda'
        );
        $out = array_map(fn($f) => ['id' => (int)$f['id'], 'nome' => $f['nome'], 'indirizzo' => $f['indirizzo'], 'descrizione' => $f['descrizione'], 'logo_url' => $f['logo_url'], 'sito_web' => $f['sito_web'], 'categoria' => $f['categoria'], 'email_contatto' => $f['email_contatto'], 'telefono' => $f['telefono'], 'num_campagne' => (int)$f['num_campagne']], $st->fetchAll());
        return ApiResponse::ok($out);
    }

    #[Route('/api/prodotti', methods: ['GET'])]
    #[Route('/prodotti', methods: ['GET'])]
    public function prodotti(Request $request): JsonResponse
    {
        $this->auth->richiediLogin();
        $sql = 'SELECT p.id, p.nome, p.descrizione, p.prezzo_unitario, p.prezzo_base, p.quantita_minima,
                       p.id_fornitore, f.nome_azienda AS fornitore,
                       c.id AS categoria_id, c.nome AS categoria,
                       (SELECT url FROM immagini_prodotto WHERE id_prodotto = p.id ORDER BY principale DESC, ordine ASC LIMIT 1) AS immagine
                  FROM prodotti p
                  JOIN fornitori f ON f.id = p.id_fornitore
             LEFT JOIN categorie  c ON c.id = p.id_categoria
                  WHERE p.stato = :stato';
        $par = ['stato' => 'attivo'];
        if ($request->query->has('fornitore_id')) {
            $fid = filter_var($request->query->get('fornitore_id'), FILTER_VALIDATE_INT);
            if ($fid === false) throw new AppException('CAMPO_NON_VALIDO', 'fornitore_id non valido');
            $sql .= ' AND p.id_fornitore = :fid';
            $par['fid'] = $fid;
        }
        $sql .= ' ORDER BY f.nome_azienda, p.nome';
        $st = $this->db->getPdo()->prepare($sql);
        $st->execute($par);
        $out = array_map(function ($p) {
            $p['id'] = (int)$p['id'];
            $p['id_fornitore'] = (int)$p['id_fornitore'];
            $p['prezzo_unitario'] = (float)$p['prezzo_unitario'];
            $p['prezzo_base'] = $p['prezzo_base'] !== null ? (float)$p['prezzo_base'] : (float)$p['prezzo_unitario'];
            $p['quantita_minima'] = (int)$p['quantita_minima'];
            return $p;
        }, $st->fetchAll());
        return ApiResponse::ok(['prodotti' => $out]);
    }

    #[Route('/api/sedi', methods: ['GET'])]
    #[Route('/sedi', methods: ['GET'])]
    public function sedi(): JsonResponse
    {
        $this->auth->richiediLogin();
        $rows = $this->db->getPdo()->query('SELECT * FROM sedi ORDER BY citta, nome')->fetchAll();
        return ApiResponse::ok($rows);
    }
}
