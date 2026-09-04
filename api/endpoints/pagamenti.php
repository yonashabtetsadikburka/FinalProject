<?php
declare(strict_types=1);

/**
 * ============================================================
 *  PAGAMENTI (Stripe) e WALLET -- DA DECIDERE COL TEAM
 * ============================================================
 * Il DB ha le tabelle pagamenti / eventi_stripe, ma manca l'integrazione
 * Stripe vera (SDK + chiavi segrete + webhook). Non la si stubba con dati
 * finti: si restituisce 501 finche' non e' in scope, cosi' il front-end
 * riceve un errore pulito.
 *
 * NOTA WALLET: nel DB di Abdu NON esiste alcuna tabella wallet, ma il
 * front-end (wallet.js) chiama /wallet e /wallet/movimenti. Va deciso col
 * team se aggiungere la tabella o togliere la funzione dal front-end.
 */
function pagamento_checkout(): void
{
    richiedi_login();
    throw new AppError('NON_IMPLEMENTATO',
        'Pagamento Stripe non ancora attivo (manca l\'integrazione e le chiavi).', 501);
}

function pagamento_stato(): void
{
    richiedi_login();
    throw new AppError('NON_IMPLEMENTATO',
        'Verifica pagamento Stripe non ancora attiva.', 501);
}

function wallet_saldo(): void
{
    richiedi_login();
    throw new AppError('FUNZIONE_NON_DISPONIBILE',
        'Il wallet non e\' previsto dal database attuale (nessuna tabella wallet).', 501);
}

function wallet_movimenti(): void
{
    richiedi_login();
    throw new AppError('FUNZIONE_NON_DISPONIBILE',
        'Il wallet non e\' previsto dal database attuale (nessuna tabella wallet).', 501);
}
