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

class RitiroController extends AbstractController
{
    public function __construct(private DatabaseService $db, private AuthService $auth) {}

    #[Route('/api/mie/assegnazioni/{id}/qr', methods: ['GET'], requirements: ['id'=>'\d+'])]
    #[Route('/mie/assegnazioni/{id}/qr', methods: ['GET'], requirements: ['id'=>'\d+'])]
    public function qr(int $id): JsonResponse
    {
        $io=$this->auth->richiediLogin();
        $st=$this->db->getPdo()->prepare('SELECT qr.token, qr.quantita_assegnata, qr.stato FROM qr_codes qr JOIN prenotazioni p ON p.id = qr.id_prenotazione JOIN collette c ON c.id = p.id_colletta WHERE qr.id_prenotazione = ? AND p.id_utente = ?');
        // original: mie/assegnazioni/{id}/qr where id is prenotazione id
        $st->execute([$id, $io]);
        $row=$st->fetch(); if(!$row) throw new AppException('QR_INESISTENTE','QR non trovato',404);
        return ApiResponse::ok($row);
    }

    #[Route('/api/admin/ritiri', methods: ['GET'])]
    #[Route('/admin/ritiri', methods: ['GET'])]
    public function elenco(): JsonResponse
    {
        if(!$this->auth->sonoAdmin()) throw new AppException('NON_AUTORIZZATO','Solo gli admin',403);
        $st=$this->db->getPdo()->query('SELECT qr.id, qr.quantita_assegnata, qr.stato, qr.data_scansione, p.id AS id_prenotazione, u.nome, u.cognome, u.email, c.id AS id_colletta, pr.nome AS prodotto FROM qr_codes qr JOIN prenotazioni p ON p.id = qr.id_prenotazione JOIN utenti u ON u.id = p.id_utente JOIN collette c ON c.id = p.id_colletta JOIN prodotti pr ON pr.id = c.id_prodotto ORDER BY qr.data_scansione IS NULL DESC, qr.id DESC');
        $out=$st->fetchAll();
        // hide token as original
        foreach($out as &$r){ unset($r['token']); $r['id']=(int)$r['id']; $r['quantita_assegnata']=(int)$r['quantita_assegnata']; }
        return ApiResponse::ok($out);
    }

    #[Route('/api/ritiro/{token}', methods: ['POST'])]
    #[Route('/ritiro/{token}', methods: ['POST'])]
    public function conferma(string $token): JsonResponse
    {
        if(!$this->auth->sonoAdmin()) throw new AppException('NON_AUTORIZZATO','Solo gli admin',403);
        $pdo=$this->db->getPdo(); $pdo->beginTransaction();
        try{
            $st=$pdo->prepare('SELECT qr.id, qr.stato, qr.id_prenotazione, p.id_utente, p.quantita, c.prezzo_corrente FROM qr_codes qr JOIN prenotazioni p ON p.id = qr.id_prenotazione JOIN collette c ON c.id = p.id_colletta WHERE qr.token = ? FOR UPDATE');
            $st->execute([$token]); $qr=$st->fetch(); if(!$qr) { $pdo->rollBack(); throw new AppException('TOKEN_NON_VALIDO','QR token non valido',404); }
            if($qr['stato']==='scansionato'){ $pdo->rollBack(); throw new AppException('GIA_RITIRATO','QR già scansionato',409); }
            if($qr['stato']==='annullato'){ $pdo->rollBack(); throw new AppException('QR_ANNULLATO','QR annullato',409); }
            $pdo->prepare('UPDATE qr_codes SET stato = \'scansionato\', data_scansione = NOW() WHERE id = ?')->execute([$qr['id']]);
            $importo=(float)$qr['prezzo_corrente']*(int)$qr['quantita'];
            $pdo->prepare('INSERT INTO notifiche (id_utente, tipo, titolo, messaggio, tipo_riferimento, id_riferimento) VALUES (?, \'RITIRATO\', \'Ritiro Confermato\', ?, \'prenotazione\', ?)')->execute([$qr['id_utente'],"Ritiro confermato per EUR ".number_format($importo,2,',','.'),$qr['id_prenotazione']]);
            $pdo->commit();
            return ApiResponse::ok(['scansionato'=>true]);
        }catch(\Throwable $e){ if($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }
}
