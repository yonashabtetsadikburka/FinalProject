<?php
declare(strict_types=1);

/**
 * Sede di ritiro: in pratica ne esiste una sola, quindi "Ritiro in sede" e' sempre disponibile.
 * Un gruppo senza sede propria (collette.id_sede NULL, es. quelli nati da una proposta) usa la sede predefinita.
 */

/** Sede predefinita = la prima della tabella sedi; null se non esiste nessuna sede. */
function sede_predefinita_id(PDO $pdo): ?int
{
    $v = $pdo->query('SELECT MIN(id_sede) FROM sedi')->fetchColumn();
    return $v !== false && $v !== null ? (int)$v : null;
}

/** Sede effettiva di un gruppo: la sua se impostata, altrimenti la predefinita (funzione pura). */
function sede_effettiva(?int $idSedeGruppo, ?int $predefinita): ?int
{
    return ($idSedeGruppo !== null && $idSedeGruppo > 0) ? $idSedeGruppo : $predefinita;
}
