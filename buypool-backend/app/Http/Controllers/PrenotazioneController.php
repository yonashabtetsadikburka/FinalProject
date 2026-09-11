<?php

namespace App\Http\Controllers;

use App\Models\Prenotazione;
use App\Models\Colletta;
use App\Models\Notifica;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;

class PrenotazioneController extends Controller
{
    public function mie()
    {
        $utente = JWTAuth::parseToken()->authenticate();

        $prenotazioni = Prenotazione::with(['colletta.prodotto.fornitore', 'colletta.scaglioni'])
            ->where('id_utente', $utente->id)
            ->orderByDesc('data_prenotazione')
            ->get()
            ->map(function ($prenotazione) {
                $colletta = $prenotazione->colletta;
                if ($colletta) {
                    $colletta->setAttribute('fornitore', $colletta->prodotto->fornitore ?? null);
                }
                $prenotazione->setAttribute('qr', $prenotazione->qrCode);
                return $prenotazione;
            });

        return response()->json(['successo' => true, 'dati' => $prenotazioni]);
    }

    public function show($id)
    {
        $utente = JWTAuth::parseToken()->authenticate();

        $prenotazione = Prenotazione::with(['colletta.prodotto.fornitore', 'qrCode'])
            ->where('id', $id)
            ->where('id_utente', $utente->id)
            ->first();

        if (!$prenotazione) {
            return response()->json(['successo' => false, 'errore' => 'Prenotazione non trovata.'], 404);
        }

        return response()->json(['successo' => true, 'dati' => $prenotazione]);
    }

    public function tutte()
    {
        $prenotazioni = Prenotazione::with(['colletta.prodotto.fornitore', 'colletta.scaglioni', 'qrCode', 'utente'])
            ->orderByDesc('data_prenotazione')
            ->get()
            ->map(function ($prenotazione) {
                $colletta = $prenotazione->colletta;
                if ($colletta) {
                    $colletta->setAttribute('fornitore', $colletta->prodotto->fornitore ?? null);
                }
                $prenotazione->setAttribute('qr', $prenotazione->qrCode);
                return $prenotazione;
            });

        return response()->json(['successo' => true, 'dati' => $prenotazioni]);
    }

    public function annulla($id)
    {
        $utente = JWTAuth::parseToken()->authenticate();

        $prenotazione = Prenotazione::where('id', $id)
            ->where('id_utente', $utente->id)
            ->first();

        if (!$prenotazione) {
            return response()->json(['successo' => false, 'errore' => 'Prenotazione non trovata.'], 404);
        }

        if ($prenotazione->stato !== 'prenotata') {
            return response()->json(['successo' => false, 'errore' => 'Impossibile annullare questa prenotazione.'], 422);
        }

        $colletta = Colletta::find($prenotazione->id_colletta);

        \DB::transaction(function () use ($prenotazione, $colletta) {
            $prenotazione->update(['stato' => 'annullata']);

            if ($colletta) {
                $colletta->decrement('quantita_attuale', $prenotazione->quantita);
                $colletta->refresh();
                if ($colletta->stato === 'riuscita' && $colletta->quantita_attuale < $colletta->quantita_minima) {
                    $colletta->update(['stato' => 'in_corso', 'data_agg_stato' => now()]);
                }
            }
        });

        return response()->json(['successo' => true, 'dati' => ['messaggio' => 'Prenotazione annullata.']]);
    }

    public function ritiro(Request $request)
    {
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'token' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['successo' => false, 'errori' => $validator->errors()], 422);
        }

        $qr = \App\Models\QrCode::where('token', $request->token)->first();

        if (!$qr) {
            return response()->json(['successo' => false, 'errore' => 'Token QR non valido.'], 404);
        }

        if ($qr->stato === 'scansionato') {
            return response()->json(['successo' => false, 'errore' => 'Questo articolo è già stato ritirato.'], 422);
        }

        if ($qr->stato === 'annullato') {
            return response()->json(['successo' => false, 'errore' => 'Questo QR code è stato annullato.'], 422);
        }

        $prenotazione = Prenotazione::with('colletta.prodotto.fornitore')->find($qr->id_prenotazione);

        if (!$prenotazione) {
            return response()->json(['successo' => false, 'errore' => 'Prenotazione non trovata.'], 404);
        }

        $qr->update([
            'stato' => 'scansionato',
            'data_scansione' => now(),
        ]);

        return response()->json([
            'successo' => true,
            'dati' => [
                'qr' => $qr,
                'prodotto' => $prenotazione->colletta->prodotto->nome ?? 'Prodotto',
                'quantita' => $qr->quantita_assegnata,
                'partecipante' => [
                    'nome' => $prenotazione->utente->nome ?? '',
                    'cognome' => $prenotazione->utente->cognome ?? '',
                ],
            ],
        ]);
    }
}
