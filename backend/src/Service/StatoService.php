<?php
declare(strict_types=1);

namespace App\Service;

use App\Exception\AppException;

class StatoService
{
    public function __construct(private DatabaseService $db) {}

    public function ricalcolaStato(int $campagna_id): string
    {
        $pdo = $this->db->getPdo();
        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) $pdo->beginTransaction();
        try {
            $st = $pdo->prepare(
                'SELECT id, stato, quantita_attuale, quantita_minima, data_limite
                   FROM collette WHERE id = ? FOR UPDATE'
            );
            $st->execute([$campagna_id]);
            $c = $st->fetch();
            if (!$c) {
                if ($ownTransaction) $pdo->rollBack();
                throw new AppException('CAMPAGNA_INESISTENTE', 'Campagna non trovata', 404);
            }
            $statoAttuale = $c['stato'];
            if (in_array($statoAttuale, ['consegnata', 'annullata'], true)) {
                if ($ownTransaction) $pdo->rollBack();
                return $statoAttuale;
            }
            $totale = (int)$c['quantita_attuale'];
            $soglia = (int)$c['quantita_minima'];
            $scaduta = strtotime($c['data_limite']) < time();
            if (in_array($statoAttuale, ['ordine_pronto', 'ordine_fornitore'], true)) {
                $nuovoStato = $statoAttuale;
            } elseif ($totale >= $soglia) {
                $nuovoStato = 'riuscita';
            } elseif ($scaduta) {
                $nuovoStato = 'fallita';
            } else {
                $nuovoStato = 'in_corso';
            }
            if ($nuovoStato !== $statoAttuale) {
                $stUp = $pdo->prepare('UPDATE collette SET stato = ?, data_agg_stato = NOW() WHERE id = ?');
                $stUp->execute([$nuovoStato, $campagna_id]);
            }
            if ($ownTransaction) $pdo->commit();
            return $nuovoStato;
        } catch (\Throwable $e) {
            if ($ownTransaction) $pdo->rollBack();
            throw $e;
        }
    }
}
