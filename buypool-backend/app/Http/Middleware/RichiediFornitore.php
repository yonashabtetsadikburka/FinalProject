<?php

namespace App\Http\Middleware;

use App\Models\Fornitore;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Equivalent de richiedi_fornitore() : vérifie que l'utilisateur authentifié a
 * le rôle 'fornitore' ET qu'une fiche fornitori lui est bien liée, sinon 403.
 */
class RichiediFornitore
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $utente = JWTAuth::parseToken()->authenticate();
        } catch (\Exception $e) {
            return response()->json(['successo' => false, 'errore' => 'Sessione non valida.'], 401);
        }

        if (!$utente || $utente->ruolo !== 'fornitore') {
            return response()->json([
                'successo' => false,
                'errore' => 'Accesso non autorizzato per questo ruolo.',
            ], 403);
        }

        if (!Fornitore::where('id_utente', $utente->id)->exists()) {
            return response()->json([
                'successo' => false,
                'errore' => 'Nessuna scheda fornitore collegata al tuo account.',
            ], 403);
        }

        return $next($request);
    }
}