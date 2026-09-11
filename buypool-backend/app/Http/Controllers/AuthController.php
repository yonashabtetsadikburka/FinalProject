<?php

namespace App\Http\Controllers;

use App\Models\Utente;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Facades\JWTAuth;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'nome' => 'required|string|max:100',
            'cognome' => 'required|string|max:100',
            'email' => 'required|email|unique:utenti,email',
            'password' => 'required|string|min:8',
            'tipo' => 'nullable|in:privato,b2b',
            'ruolo' => 'sometimes|string', // vérifié explicitement ci-dessous
        ]);

        if ($validator->fails()) {
            return response()->json(['successo' => false, 'errori' => $validator->errors()], 422);
        }

        // Plus d'auto-inscription fournisseur : un compte fornitore ne peut être
        // créé que via le flux invito → attiva (FornitoreInvitoController).
        $ruolo = $request->input('ruolo', 'utente');

        if ($ruolo !== 'utente') {
            return response()->json([
                'successo' => false,
                'errore' => 'I profili fornitore vengono creati solo tramite invito.',
            ], 422);
        }

        $utente = Utente::create([
            'nome' => $request->nome,
            'cognome' => $request->cognome,
            'email' => $request->email,
            'password_hash' => Hash::make($request->password),
            'tipo' => $request->input('tipo', 'privato'),
            'ruolo' => 'utente',
        ]);

        $token = JWTAuth::fromUser($utente);

        return response()->json([
            'successo' => true,
            'dati' => [
                'token' => $token,
                'utente' => [
                    'id' => $utente->id,
                    'nome' => $utente->nome,
                    'cognome' => $utente->cognome,
                    'ruolo' => $utente->ruolo,
                ],
            ],
        ], 201);
    }

    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['successo' => false, 'errori' => $validator->errors()], 422);
        }

        $utente = Utente::where('email', $request->email)->first();

        if (!$utente || !Hash::check($request->password, $utente->password_hash)) {
            return response()->json(['successo' => false, 'errore' => 'Email o password errati.'], 401);
        }

        if ($utente->stato === 'sospeso') {
            return response()->json(['successo' => false, 'errore' => "Account sospeso."], 403);
        }

        $token = JWTAuth::fromUser($utente);

        return response()->json([
            'successo' => true,
            'dati' => [
                'token' => $token,
                'utente' => [
                    'id' => $utente->id,
                    'nome' => $utente->nome,
                    'cognome' => $utente->cognome,
                    'ruolo' => $utente->ruolo, // <-- le frontend l'utilise pour rediriger
                ],
            ],
        ]);
    }

    public function logout()
    {
        JWTAuth::invalidate(JWTAuth::getToken());

        return response()->json(['successo' => true, 'dati' => ['messaggio' => 'Disconnesso.']]);
    }

    public function me()
    {
        return response()->json([
            'successo' => true,
            'dati' => JWTAuth::user(),
        ]);
    }
}
