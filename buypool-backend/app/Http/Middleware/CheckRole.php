<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tymon\JWTAuth\Facades\JWTAuth;

class CheckRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        try {
            $utente = JWTAuth::parseToken()->authenticate();
        } catch (\Exception $e) {
            return response()->json(['successo' => false, 'errore' => 'Sessione non valida.'], 401);
        }

        if (!$utente || !in_array($utente->ruolo, $roles, true)) {
            return response()->json([
                'successo' => false,
                'errore' => 'Accesso non autorizzato per questo ruolo.',
            ], 403);
        }

        return $next($request);
    }
}
