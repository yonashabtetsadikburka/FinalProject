<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CategoriaController;
use App\Http\Controllers\ColletteController;
use App\Http\Controllers\FornitoreAreaController;
use App\Http\Controllers\FornitoreController;
use App\Http\Controllers\FornitoreInvitoController;
use App\Http\Controllers\NotificaController;
use App\Http\Controllers\OrdineFornitoreController;
use App\Http\Controllers\PagamentoController;
use App\Http\Controllers\PrenotazioneController;
use App\Http\Controllers\ProdottoController;
use App\Http\Controllers\PropostaProdottoController;
use App\Http\Controllers\SedeController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\UtenteController;
use App\Http\Controllers\VotoPropostaController;
use App\Http\Controllers\WalletController;
use Illuminate\Support\Facades\Route;

// ------------------------------------------------------------
// Routes publiques
// ------------------------------------------------------------
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);

Route::get('/categorie', [CategoriaController::class, 'index']);
Route::get('/categorie/{id}', [CategoriaController::class, 'show']);

Route::get('/fornitori', [FornitoreController::class, 'index']);
Route::get('/fornitori/{id}', [FornitoreController::class, 'show']);

Route::get('/fornitori/invito/{token}', [FornitoreInvitoController::class, 'verifica']);
Route::post('/fornitori/attiva', [FornitoreInvitoController::class, 'attiva']);

Route::get('/prodotti', [ProdottoController::class, 'index']);
Route::get('/prodotti/{id}', [ProdottoController::class, 'show']);

Route::get('/sedi', [SedeController::class, 'index']);
Route::get('/sedi/{id}', [SedeController::class, 'show']);

Route::get('/collette', [ColletteController::class, 'index']);
Route::get('/collette/{id}', [ColletteController::class, 'show']);

Route::get('/proposte', [PropostaProdottoController::class, 'index']);
Route::get('/proposte/{id}', [PropostaProdottoController::class, 'show']);

// ------------------------------------------------------------
// Routes protégées (JWT)
// ------------------------------------------------------------
Route::middleware('auth:api')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    Route::get('/profilo', [UtenteController::class, 'profilo']);
    Route::put('/profilo', [UtenteController::class, 'aggiornaProfilo']);

    Route::post('/collette/{id}/partecipa', [ColletteController::class, 'partecipa']);
    Route::post('/prenotazioni/{id}/annulla', [PrenotazioneController::class, 'annulla']);

    Route::get('/prenotazioni/mie', [PrenotazioneController::class, 'mie']);
    Route::get('/prenotazioni/{id}', [PrenotazioneController::class, 'show']);

    Route::post('/proposte', [PropostaProdottoController::class, 'store']);
    Route::post('/proposte/{id}/vota', [VotoPropostaController::class, 'vota']);
    Route::delete('/proposte/{id}/voto', [VotoPropostaController::class, 'annullaVoto']);

    Route::get('/notifiche', [NotificaController::class, 'index']);
    Route::get('/notifiche/non-lette', [NotificaController::class, 'conteggioNonLette']);
    Route::patch('/notifiche/{id}/letta', [NotificaController::class, 'markRead']);
    Route::patch('/notifiche/tutte-lette', [NotificaController::class, 'markAllRead']);

    Route::get('/wallet', [WalletController::class, 'saldo']);
    Route::get('/wallet/movimenti', [WalletController::class, 'movimenti']);

    Route::post('/pagamento/checkout', [PagamentoController::class, 'checkout']);
    Route::get('/pagamento/stato', [PagamentoController::class, 'stato']);

    Route::get('/session/io', [SessionController::class, 'io']);

    // Espace fournisseur : réservé aux comptes fornitore avec fiche collegata
    Route::middleware('richiedi_fornitore')->group(function () {
        Route::get('/fornitore/area', [FornitoreAreaController::class, 'me']);
        Route::get('/fornitore/prodotti', [FornitoreAreaController::class, 'prodotti']);
        Route::post('/fornitore/prodotti', [FornitoreAreaController::class, 'salvaProdotto']);
        Route::put('/fornitore/prodotti/{id}', [FornitoreAreaController::class, 'aggiornaProdotto']);
        Route::delete('/fornitore/prodotti/{id}', [FornitoreAreaController::class, 'eliminaProdotto']);
        Route::get('/fornitore/ordini', [FornitoreAreaController::class, 'ordini']);
        Route::get('/fornitore/collette', [FornitoreAreaController::class, 'collette']);
    });

    // Gestion des collette par admin (store)
    Route::middleware('role:admin')->group(function () {
        Route::post('/collette', [ColletteController::class, 'store']);
        Route::post('/collette/{id}/conferma-ordine', [ColletteController::class, 'confermaOrdine']);
        Route::post('/collette/{id}/consegna', [ColletteController::class, 'confermaConsegna']);

        Route::post('/proposte/{id}/approva', [PropostaProdottoController::class, 'approva']);
        Route::post('/proposte/{id}/respingi', [PropostaProdottoController::class, 'respingi']);

        Route::post('/proposte/{id}/votazione', [VotoPropostaController::class, 'apriVotazione']);
        Route::post('/proposte/{id}/chiusura-votazione', [VotoPropostaController::class, 'chiudiVotazione']);

        Route::get('/utenti', [UtenteController::class, 'index']);
        Route::patch('/utenti/{id}/stato', [UtenteController::class, 'updateStato']);

        Route::get('/ordini', [OrdineFornitoreController::class, 'index']);
        Route::get('/ordini/{id}', [OrdineFornitoreController::class, 'show']);
        Route::post('/ordini', [OrdineFornitoreController::class, 'store']);
        Route::put('/ordini/{id}', [OrdineFornitoreController::class, 'update']);

        Route::post('/prenotazioni/ritiro', [PrenotazioneController::class, 'ritiro']);
        Route::get('/prenotazioni', [PrenotazioneController::class, 'tutte']);

        Route::post('/sedi', [SedeController::class, 'store']);
        Route::put('/sedi/{id}', [SedeController::class, 'update']);
        Route::delete('/sedi/{id}', [SedeController::class, 'destroy']);

        Route::post('/categorie', [CategoriaController::class, 'store']);
        Route::put('/categorie/{id}', [CategoriaController::class, 'update']);
        Route::delete('/categorie/{id}', [CategoriaController::class, 'destroy']);

        Route::post('/prodotti', [ProdottoController::class, 'store']);
        Route::put('/prodotti/{id}', [ProdottoController::class, 'update']);
        Route::delete('/prodotti/{id}', [ProdottoController::class, 'destroy']);

        Route::post('/notifiche', [NotificaController::class, 'store']);

        // Gestion des fiches fournisseur : création, invitations et collegamento manuel
        Route::get('/admin/fornitori', [FornitoreInvitoController::class, 'tutte']);
        Route::post('/admin/fornitori', [FornitoreInvitoController::class, 'crea']);
        Route::post('/admin/fornitori/{id}/invito', [FornitoreInvitoController::class, 'genera']);
        Route::post('/admin/fornitori/{id}/collega', [FornitoreInvitoController::class, 'collega']);
    });
});

// ------------------------------------------------------------
// Webhook Stripe (public, sans JWT — validé par la signature)
// ------------------------------------------------------------
Route::post('/pagamento/webhook', [PagamentoController::class, 'webhook']);
