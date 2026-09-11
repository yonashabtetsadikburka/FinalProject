<?php

namespace App\Http\Controllers;

use App\Models\Notifica;
use App\Models\Utente;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Facades\JWTAuth;

class NotificaController extends Controller
{
    public function index()
    {
        $utente = JWTAuth::parseToken()->authenticate();

        $notifiche = Notifica::where('id_utente', $utente->id)
            ->orderByDesc('data_creazione')
            ->get();

        return response()->json(['successo' => true, 'dati' => $notifiche]);
    }

    public function markRead($id)
    {
        $utente = JWTAuth::parseToken()->authenticate();

        $notifica = Notifica::where('id', $id)
            ->where('id_utente', $utente->id)
            ->first();

        if (!$notifica) {
            return response()->json(['successo' => false, 'errore' => 'Notifica non trovata.'], 404);
        }

        $notifica->update(['letta' => true, 'data_lettura' => now()]);

        return response()->json(['successo' => true, 'dati' => $notifica]);
    }

    public function markAllRead()
    {
        $utente = JWTAuth::parseToken()->authenticate();

        Notifica::where('id_utente', $utente->id)
            ->where('letta', false)
            ->update(['letta' => true, 'data_lettura' => now()]);

        return response()->json(['successo' => true, 'dati' => ['messaggio' => 'Tutte le notifiche segnate come lette.']]);
    }

    public function conteggioNonLette()
    {
        $utente = JWTAuth::parseToken()->authenticate();

        $count = Notifica::where('id_utente', $utente->id)
            ->where('letta', false)
            ->count();

        return response()->json(['successo' => true, 'dati' => ['non_lette' => $count]]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'destinatari' => 'required|in:tutti,acquirenti,fornitori,specifico',
            'utente_id' => 'required_if:destinatari,specifico|exists:utenti,id',
            'tipo' => 'required|string',
            'titolo' => 'required|string|max:150',
            'messaggio' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['successo' => false, 'errori' => $validator->errors()], 422);
        }

        $destinatari = $request->destinatari;

        $query = Utente::query();

        if ($destinatari === 'acquirenti') {
            $query->where('ruolo', 'utente');
        } elseif ($destinatari === 'fornitori') {
            $query->where('ruolo', 'fornitore');
        } elseif ($destinatari === 'specifico') {
            $query->where('id', $request->utente_id);
        }

        $utenti = $query->where('stato', 'attivo')->get();

        foreach ($utenti as $utente) {
            Notifica::create([
                'id_utente' => $utente->id,
                'tipo' => $request->tipo,
                'titolo' => $request->titolo,
                'messaggio' => $request->messaggio,
            ]);
        }

        return response()->json([
            'successo' => true,
            'dati' => ['messaggio' => 'Notifica inviata a ' . $utenti->count() . ' utenti.'],
        ], 201);
    }
}
