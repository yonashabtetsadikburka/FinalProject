<?php

namespace App\Http\Controllers;

use App\Models\Notifica;
use App\Models\Prodotto;
use App\Models\PropostaProdotto;
use App\Models\VotoProposta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Facades\JWTAuth;

class VotoPropostaController extends Controller
{
    private function formatProposta($proposta)
    {
        $proposta->setAttribute('utente', $proposta->proponente);
        $proposta->setAttribute('votatoDa', $proposta->voti->pluck('id_utente'));
        return $proposta;
    }

    public function vota(Request $request, $id)
    {
        $utente = JWTAuth::parseToken()->authenticate();

        $proposta = PropostaProdotto::find($id);

        if (!$proposta) {
            return response()->json(['successo' => false, 'errore' => 'Proposta non trovata.'], 404);
        }

        if ($proposta->stato !== 'in_votazione') {
            return response()->json(['successo' => false, 'errore' => 'Questa proposta non è in votazione.'], 422);
        }

        $validator = Validator::make($request->all(), [
            'valore_voto' => 'required|in:favore,contrario',
        ]);

        if ($validator->fails()) {
            return response()->json(['successo' => false, 'errori' => $validator->errors()], 422);
        }

        $esistente = VotoProposta::where('id_proposta', $proposta->id_proposta)
            ->where('id_utente', $utente->id)
            ->first();

        if ($esistente) {
            return response()->json(['successo' => false, 'errore' => 'Hai già votato questa proposta.'], 422);
        }

        DB::transaction(function () use ($proposta, $utente, $request) {
            VotoProposta::create([
                'id_proposta' => $proposta->id_proposta,
                'id_utente' => $utente->id,
                'valore_voto' => $request->valore_voto,
            ]);

            if ($request->valore_voto === 'favore') {
                $proposta->increment('tot_voti');
            } else {
                $proposta->decrement('tot_voti');
            }
            $proposta->refresh();
        });

        $proposta->load(['proponente', 'voti']);

        return response()->json(['successo' => true, 'dati' => $this->formatProposta($proposta)]);
    }

    public function annullaVoto(Request $request, $id)
    {
        $utente = JWTAuth::parseToken()->authenticate();

        $voto = VotoProposta::where('id_proposta', $id)
            ->where('id_utente', $utente->id)
            ->first();

        if (!$voto) {
            return response()->json(['successo' => false, 'errore' => 'Voto non trovato.'], 404);
        }

        $proposta = PropostaProdotto::find($id);

        DB::transaction(function () use ($voto, $proposta) {
            $voto->delete();
            if ($proposta && $voto->valore_voto === 'favore') {
                $proposta->decrement('tot_voti');
            } elseif ($proposta && $voto->valore_voto === 'contrario') {
                $proposta->increment('tot_voti');
            }
        });

        if ($proposta) {
            $proposta->load(['proponente', 'voti']);
            return response()->json(['successo' => true, 'dati' => $this->formatProposta($proposta)]);
        }

        return response()->json(['successo' => true, 'dati' => ['messaggio' => 'Voto annullato.']]);
    }

    public function apriVotazione($id)
    {
        $utente = JWTAuth::parseToken()->authenticate();

        $proposta = PropostaProdotto::find($id);

        if (!$proposta) {
            return response()->json(['successo' => false, 'errore' => 'Proposta non trovata.'], 404);
        }

        if ($proposta->stato !== 'in_attesa') {
            return response()->json(['successo' => false, 'errore' => 'Solo le proposte in attesa possono essere messe in votazione.'], 422);
        }

        DB::transaction(function () use ($proposta, $utente) {
            $proposta->update([
                'stato' => 'in_votazione',
                'id_admin_gestione' => $utente->id,
                'data_gestione' => now(),
            ]);

            Notifica::create([
                'id_utente' => $proposta->proponente_id,
                'tipo' => 'SISTEMA',
                'titolo' => 'Proposta in votazione',
                'messaggio' => 'La tua proposta "' . $proposta->nome_prodotto . '" è ora in votazione: la community può votarla.',
                'tipo_riferimento' => 'proposta',
                'id_riferimento' => $proposta->id_proposta,
            ]);
        });

        $proposta->load(['proponente', 'voti']);

        return response()->json(['successo' => true, 'dati' => $this->formatProposta($proposta)]);
    }

    public function chiudiVotazione(Request $request, $id)
    {
        $utente = JWTAuth::parseToken()->authenticate();

        $proposta = PropostaProdotto::find($id);

        if (!$proposta) {
            return response()->json(['successo' => false, 'errore' => 'Proposta non trovata.'], 404);
        }

        if ($proposta->stato !== 'in_votazione') {
            return response()->json(['successo' => false, 'errore' => 'Questa proposta non è in votazione.'], 422);
        }

        $decisione = $request->input('decisione');

        if (!$decisione) {
            $decisione = $proposta->tot_voti > 0 ? 'pubblicata' : 'respinta_votazione';
        }

        if (!in_array($decisione, ['pubblicata', 'respinta_votazione'], true)) {
            return response()->json(['successo' => false, 'errori' => ['decisione' => ['La decisione deve essere pubblicata o respinta_votazione.']]], 422);
        }

        DB::transaction(function () use ($proposta, $utente, $request, $decisione) {
            $proposta->update([
                'stato' => $decisione,
                'id_admin_gestione' => $utente->id,
                'data_gestione' => now(),
            ]);

            if ($decisione === 'pubblicata') {
                $idFornitore = $request->input('id_fornitore') ?? $proposta->id_fornitore_suggerito;

                if ($idFornitore) {
                    Prodotto::firstOrCreate(
                        ['id_proposta_origine' => $proposta->id_proposta],
                        [
                            'id_fornitore' => $idFornitore,
                            'nome' => $proposta->nome_prodotto,
                            'descrizione' => $proposta->descrizione,
                            'prezzo_unitario' => $request->input('prezzo_unitario', 0.01),
                            'quantita_minima' => $request->input('quantita_minima', 1),
                            'stato' => 'attivo',
                        ]
                    );
                }

                Notifica::create([
                    'id_utente' => $proposta->proponente_id,
                    'tipo' => 'PROPOSTA_APPROVATA',
                    'titolo' => 'Proposta Approvata',
                    'messaggio' => 'La tua proposta "' . $proposta->nome_prodotto . '" è stata pubblicata grazie ai voti della community!',
                    'tipo_riferimento' => 'proposta',
                    'id_riferimento' => $proposta->id_proposta,
                ]);
            } else {
                Notifica::create([
                    'id_utente' => $proposta->proponente_id,
                    'tipo' => 'SISTEMA',
                    'titolo' => 'Proposta non pubblicata',
                    'messaggio' => 'La tua proposta "' . $proposta->nome_prodotto . '" non ha ricevuto voti sufficienti.',
                    'tipo_riferimento' => 'proposta',
                    'id_riferimento' => $proposta->id_proposta,
                ]);
            }
        });

        $proposta->load(['proponente', 'voti']);

        return response()->json(['successo' => true, 'dati' => $this->formatProposta($proposta)]);
    }
}