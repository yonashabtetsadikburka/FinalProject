<?php
declare(strict_types=1);

/** Il token da mettere nel QR. Solo la PROPRIA assegnazione. */
function ritiro_qr(int $assegnazione_id): void
{
    $io = richiedi_login();
    $st = db()->prepare(
        'SELECT token_ritiro, latte_assegnate, quantita_totale, ritirato_il
           FROM assegnazioni WHERE id = ? AND utente_id = ?');
    $st->execute([$assegnazione_id, $io]);
    $a = $st->fetch();
    if (!$a) throw new AppError('NON_TROVATA', 'Assegnazione non trovata', 404);

    json_ok([
        'token'    => $a['token_ritiro'],
        'latte'    => (int)$a['latte_assegnate'],
        'quantita' => (float)$a['quantita_totale'],
        'ritirato' => $a['ritirato_il'] !== null,
    ]);
}

/**
 * ============================================================
 *  CONFERMA RITIRO  --  da completare (ruolo B)
 * ============================================================
 * Vedi piano sezione 8. Il controllo che conta e' il DOPPIO RITIRO.
 *   1. beginTransaction, SELECT ... WHERE token_ritiro = ? FOR UPDATE
 *      (join su utenti e campagne per nome, referente_id, aperta_da).
 *   2. !$a                -> TOKEN_NON_VALIDO
 *   3. $a['ritirato_il']  -> GIA_RITIRATO      <- il controllo importante
 *   4. chi conferma deve essere referente_id, aperta_da o admin
 *   5. UPDATE ritirato_il = NOW(), confermato_da = $io ; commit
 *   6. restituire nome e latte, cosi' il referente vede a schermo
 *      quanto deve consegnare.
 */
function ritiro_conferma(string $token): void
{
    richiedi_login();
    if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
        throw new AppError('TOKEN_NON_VALIDO', 'Token non valido', 400);
    }
    // TODO(B): implementare i passi 1-6 descritti sopra.
    throw new AppError('NON_IMPLEMENTATO', 'Conferma ritiro non ancora implementata', 501);
}
