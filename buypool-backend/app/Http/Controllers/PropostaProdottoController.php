<?php

namespace App\Http\Controllers;

use App\Models\PropostaProdotto;
use App\Models\VotoProposta;
use App\Models\Prodotto;
use App\Models\ImmagineProdotto;
use App\Models\Notifica;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Facades\JWTAuth;

class PropostaProdottoController extends Controller
{
    private function formatProposte($proposte)
    {
        return $proposte->map(function ($proposta) {
            $proposta->setAttribute('utente', $proposta->proponente);
            $proposta->setAttribute('votatoDa', $proposta->voti->pluck('id_utente'));
            return $proposta;
        });
    }

    public function index(Request $request)
    {
        $query = PropostaProdotto::with(['proponente', 'voti']);

        if ($request->has('stato')) {
            $query->where('stato', $request->input('stato'));
        }

        $proposte = $query->orderByDesc('data_proposta')->get();

        return response()->json([
            'successo' => true,
            'dati' => $this->formatProposte($proposte),
        ]);
    }

    public function show($id)
    {
        $proposta = PropostaProdotto::with(['proponente', 'voti'])->find($id);

        if (!$proposta) {
            return response()->json(['successo' => false, 'errore' => 'Proposta non trovata.'], 404);
        }

        return response()->json(['successo' => true, 'dati' => $this->formatProposte(collect([$proposta]))->first()]);
    }

    public function store(Request $request)
    {
        $utente = JWTAuth::parseToken()->authenticate();

        $validator = Validator::make($request->all(), [
            'nome_prodotto' => 'required|string|max:200',
            'descrizione' => 'nullable|string',
            'id_fornitore_suggerito' => 'nullable|exists:utenti,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['successo' => false, 'errori' => $validator->errors()], 422);
        }

        $proposta = PropostaProdotto::create([
            'proponente_tipo' => $utente->ruolo === 'admin' ? 'admin' : 'utente',
            'proponente_id' => $utente->id,
            'nome_prodotto' => $request->nome_prodotto,
            'descrizione' => $request->descrizione,
            'id_fornitore_suggerito' => $request->input('id_fornitore_suggerito'),
            'stato' => 'in_attesa',
            'tot_voti' => 0,
        ]);

        $proposta->load(['proponente', 'voti']);

        return response()->json(['successo' => true, 'dati' => $this->formatProposte(collect([$proposta]))->first()], 201);
    }

    public function approva(Request $request, $id)
    {
        $utente = JWTAuth::parseToken()->authenticate();

        $proposta = PropostaProdotto::find($id);

        if (!$proposta) {
            return response()->json(['successo' => false, 'errore' => 'Proposta non trovata.'], 404);
        }

        // Passage en publiée + création du produit si besoin
        DB::transaction(function () use ($proposta, $utente, $request) {
            $proposta->update([
                'stato' => 'pubblicata',
                'id_admin_gestione' => $utente->id,
                'data_gestione' => now(),
            ]);

            $idFornitore = $request->input('id_fornitore') ?? $proposta->id_fornitore_suggerito;

            if ($idFornitore) {
                $prodotto = Prodotto::firstOrCreate(
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

            // Notification au proponente
            Notifica::create([
                'id_utente' => $proposta->proponente_id,
                'tipo' => 'PROPOSTA_APPROVATA',
                'titolo' => 'Proposta Approvata',
                'messaggio' => 'La tua proposta "' . $proposta->nome_prodotto . '" è stata pubblicata!',
                'tipo_riferimento' => 'proposta',
                'id_riferimento' => $proposta->id_proposta,
            ]);
        });

        $proposta->load(['proponente', 'voti']);

        return response()->json(['successo' => true, 'dati' => $this->formatProposte(collect([$proposta]))->first()]);
    }

    public function respingi(Request $request, $id)
    {
        $utente = JWTAuth::parseToken()->authenticate();

        $proposta = PropostaProdotto::find($id);

        if (!$proposta) {
            return response()->json(['successo' => false, 'errore' => 'Proposta non trovata.'], 404);
        }

        $proposta->update([
            'stato' => 'respinta_votazione',
            'id_admin_gestione' => $utente->id,
            'data_gestione' => now(),
        ]);

        $proposta->load(['proponente', 'voti']);

        return response()->json(['successo' => true, 'dati' => $this->formatProposte(collect([$proposta]))->first()]);
    }
}
