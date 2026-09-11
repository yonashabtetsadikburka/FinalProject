<?php

namespace App\Http\Controllers;

use App\Models\Utente;
use App\Models\Fornitore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Facades\JWTAuth;

class UtenteController extends Controller
{
    public function profilo()
    {
        $utente = JWTAuth::parseToken()->authenticate();

        $dati = $utente->toArray();
        $dati['fornitore'] = $utente->ruolo === 'fornitore' ? $utente->fornitore : null;

        return response()->json(['successo' => true, 'dati' => $dati]);
    }

    public function aggiornaProfilo(Request $request)
    {
        $utente = JWTAuth::parseToken()->authenticate();

        $validator = Validator::make($request->all(), [
            'nome' => 'sometimes|string|max:100',
            'cognome' => 'sometimes|string|max:100',
            'email' => 'sometimes|email|unique:utenti,email,' . $utente->id,
            'password' => 'sometimes|string|min:8',
            'telefono' => 'nullable|string|max:30',
            'indirizzo' => 'nullable|string|max:255',
            'nome_azienda' => 'sometimes|string|max:200',
        ]);

        if ($validator->fails()) {
            return response()->json(['successo' => false, 'errori' => $validator->errors()], 422);
        }

        $dati = $request->only(['nome', 'cognome', 'telefono', 'indirizzo']);

        if ($request->has('email')) {
            $dati['email'] = $request->email;
        }
        if ($request->has('password')) {
            $dati['password_hash'] = Hash::make($request->password);
        }

        $utente->update($dati);

        if ($request->has('nome_azienda') && $utente->ruolo === 'fornitore') {
            $utente->fornitore()->updateOrCreate(
                ['id_utente' => $utente->id],
                ['nome_azienda' => $request->nome_azienda]
            );
        }

        $datiFinali = $utente->fresh()->toArray();
        $datiFinali['fornitore'] = $utente->ruolo === 'fornitore' ? $utente->fresh()->fornitore : null;

        return response()->json(['successo' => true, 'dati' => $datiFinali]);
    }

    /* ------- Admin : gestion des utilisateurs ------- */

    public function index()
    {
        $utenti = Utente::with('fornitore')->orderByDesc('data_iscrizione')->get();

        return response()->json(['successo' => true, 'dati' => $utenti]);
    }

    public function updateStato(Request $request, $id)
    {
        $utente = Utente::find($id);

        if (!$utente) {
            return response()->json(['successo' => false, 'errore' => 'Utente non trovato.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'stato' => 'required|in:attivo,sospeso',
        ]);

        if ($validator->fails()) {
            return response()->json(['successo' => false, 'errori' => $validator->errors()], 422);
        }

        $utente->update(['stato' => $request->stato]);

        return response()->json(['successo' => true, 'dati' => $utente]);
    }
}
