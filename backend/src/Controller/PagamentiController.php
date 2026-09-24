<?php
declare(strict_types=1);

namespace App\Controller;

use App\Exception\AppException;
use App\Service\ApiResponse;
use App\Service\AuthService;
use App\Service\DatabaseService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class PagamentiController extends AbstractController
{
    public function __construct(private DatabaseService $db, private AuthService $auth) {}

    #[Route('/api/wallet', methods: ['GET'])]
    #[Route('/wallet', methods: ['GET'])]
    public function wallet(): JsonResponse
    {
        $io=$this->auth->richiediLogin();
        $st=$this->db->getPdo()->prepare('SELECT pg.* FROM pagamenti pg JOIN prenotazioni p ON p.id = pg.id_prenotazione WHERE p.id_utente = ? ORDER BY pg.data_pagamento DESC LIMIT 50');
        $st->execute([$io]);
        return ApiResponse::ok($st->fetchAll());
    }

    #[Route('/api/wallet/movimenti', methods: ['GET'])]
    #[Route('/wallet/movimenti', methods: ['GET'])]
    public function movimenti(): JsonResponse { return $this->wallet(); }

    #[Route('/api/wallet/{id}', methods: ['GET'], requirements: ['id'=>'\d+'])]
    #[Route('/wallet/{id}', methods: ['GET'], requirements: ['id'=>'\d+'])]
    public function dettaglioUtente(int $id): JsonResponse
    {
        if(!$this->auth->sonoAdmin()) throw new AppException('NON_AUTORIZZATO','Solo gli admin',403);
        $st=$this->db->getPdo()->prepare('SELECT pg.* FROM pagamenti pg JOIN prenotazioni p ON p.id = pg.id_prenotazione WHERE p.id_utente = ? ORDER BY pg.data_pagamento DESC LIMIT 50');
        $st->execute([$id]);
        return ApiResponse::ok($st->fetchAll());
    }

    #[Route('/api/wallet/statistiche', methods: ['GET'])]
    #[Route('/wallet/statistiche', methods: ['GET'])]
    public function statistiche(): JsonResponse
    {
        if(!$this->auth->sonoAdmin()) throw new AppException('NON_AUTORIZZATO','Solo gli admin',403);
        $pdo=$this->db->getPdo();
        $st=$pdo->query('SELECT SUM(commissione_agenzia) FROM pagamenti WHERE stato=\'confermato\''); $totComm=(float)($st->fetchColumn()??0);
        $st=$pdo->query("SELECT SUM(commissione_agenzia) FROM pagamenti WHERE stato='confermato' AND MONTH(data_pagamento)=MONTH(NOW()) AND YEAR(data_pagamento)=YEAR(NOW())"); $meseComm=(float)($st->fetchColumn()??0);
        $st=$pdo->query('SELECT SUM(importo) FROM pagamenti WHERE stato=\'confermato\''); $totImp=(float)($st->fetchColumn()??0);
        $st=$pdo->query("SELECT COUNT(*) FROM collette WHERE stato='ordine_fornitore'"); $ordForn=(int)$st->fetchColumn();
        $st=$pdo->query("SELECT COUNT(*) FROM collette WHERE stato='consegnata'"); $cons=(int)$st->fetchColumn();
        return ApiResponse::ok(['commissione_totale'=>round($totComm,2),'commissione_mese'=>round($meseComm,2),'incasso_totale'=>round($totImp,2),'ordini_fornitore'=>$ordForn,'consegnate'=>$cons]);
    }
}
