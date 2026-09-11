<?php

namespace App\Http\Controllers;

use App\Models\Colletta;
use App\Models\Prenotazione;
use App\Models\OrdineFornitore;
use App\Models\QrCode;
use App\Models\Notifica;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Tymon\JWTAuth\Facades\JWTAuth;

class ColletteController extends Controller
{
    private function addRelations($colletta)
    {
        $colletta->load(['prodotto.fornitore', 'scaglioni']);
        return $colletta;
    }

    private function formatCollette($collette)
    {
        return $collette->map(function ($colletta) {
            $fornitore = $colletta->prodotto->fornitore ?? null;
            $colletta->setAttribute('fornitore', $fornitore);
            return $colletta;
        });
    }

    private function checkScadenze()
    {
        // Marque comme "fallita" les collette in_corso dont la data_limite est passée
        Colletta::where('stato', 'in_corso')
            ->where('data_limite', '<', now())
            ->update([
                'stato' => 'fallita',
                'data_agg_stato' => now(),
            ]);
    }

    public function index(Request $request)
    {
        $this->checkScadenze();

        $query = Colletta::with(['prodotto.fornitore', 'scaglioni']);

        if ($request->has('stato')) {
            $query->where('stato', $request->input('stato'));
        } else {
            $query->whereIn('stato', ['in_corso', 'riuscita']);
        }

        if ($request->has('categoria')) {
            $query->whereHas('prodotto', function ($q) use ($request) {
                $q->where('id_categoria', $request->input('categoria'));
            });
        }

        $collette = $query->orderByDesc('data_inizio')->get();

        return response()->json([
            'successo' => true,
            'dati' => $this->formatCollette($collette),
        ]);
    }

    public function show($id)
    {
        $this->checkScadenze();

        $colletta = Colletta::with(['prodotto.fornitore', 'scaglioni'])->find($id);

        if (!$colletta) {
            return response()->json(['successo' => false, 'errore' => 'Colletta non trovata.'], 404);
        }

        $fornitore = $colletta->prodotto->fornitore ?? null;
        $colletta->setAttribute('fornitore', $fornitore);

        return response()->json(['successo' => true, 'dati' => $colletta]);
    }

    public function store(Request $request)
    {
        $utente = JWTAuth::parseToken()->authenticate();

        $validator = Validator::make($request->all(), [
            'id_prodotto' => 'required|exists:prodotti,id',
            'id_sede' => 'nullable|exists:sedi,id_sede',
            'quantita_minima' => 'required|integer|min:1',
            'data_limite' => 'required|date|after:now',
            'regola_arrotondamento' => 'nullable|in:difetto,eccesso',
            'prezzo_base' => 'required|numeric|min:0.01',
            'prezzo_corrente' => 'required|numeric|min:0.01',
            'percentuale_commissione' => 'nullable|numeric|min:0|max:100',
            'scaglioni' => 'nullable|array',
            'scaglioni.*.soglia' => 'required|integer|min:1',
            'scaglioni.*.prezzo' => 'required|numeric|min:0.01',
        ]);

        if ($validator->fails()) {
            return response()->json(['successo' => false, 'errori' => $validator->errors()], 422);
        }

        $colletta = DB::transaction(function () use ($request, $utente) {
            $c = Colletta::create([
                'id_prodotto' => $request->id_prodotto,
                'id_aperta_da' => $utente->id,
                'id_referente' => $request->input('id_referente', $utente->id),
                'id_sede' => $request->input('id_sede'),
                'quantita_minima' => $request->quantita_minima,
                'quantita_attuale' => 0,
                'data_limite' => $request->data_limite,
                'stato' => 'in_corso',
                'regola_arrotondamento' => $request->input('regola_arrotondamento', 'difetto'),
                'prezzo_base' => $request->prezzo_base,
                'prezzo_corrente' => $request->prezzo_corrente,
                'percentuale_commissione' => $request->input('percentuale_commissione', 10.00),
            ]);

            if ($request->has('scaglioni')) {
                foreach ($request->input('scaglioni') as $s) {
                    $c->scaglioni()->create([
                        'soglia_partecipanti' => $s['soglia'],
                        'prezzo_unitario' => $s['prezzo'],
                    ]);
                }
            }

            return $c;
        });

        $colletta->load(['prodotto.fornitore', 'scaglioni']);
        $fornitore = $colletta->prodotto->fornitore ?? null;
        $colletta->setAttribute('fornitore', $fornitore);

        return response()->json(['successo' => true, 'dati' => $colletta], 201);
    }

    public function partecipa(Request $request, $id)
    {
        $this->checkScadenze();

        $utente = JWTAuth::parseToken()->authenticate();

        $colletta = Colletta::find($id);

        if (!$colletta) {
            return response()->json(['successo' => false, 'errore' => 'Colletta non trovata.'], 404);
        }

        if ($colletta->stato !== 'in_corso') {
            return response()->json(['successo' => false, 'errore' => 'Questa campagna non è più attiva.'], 422);
        }

        if (Carbon::parse($colletta->data_limite)->isPast()) {
            return response()->json(['successo' => false, 'errore' => 'La campagna è scaduta.'], 422);
        }

        $validator = Validator::make($request->all(), [
            'quantita' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['successo' => false, 'errori' => $validator->errors()], 422);
        }

        $esistente = Prenotazione::where('id_colletta', $colletta->id)
            ->where('id_utente', $utente->id)
            ->first();

        if ($esistente) {
            return response()->json(['successo' => false, 'errore' => 'Hai già partecipato a questa campagna.'], 422);
        }

        $quantita = $request->quantita;

        $prenotazione = DB::transaction(function () use ($colletta, $utente, $quantita) {
            $prenotazione = Prenotazione::create([
                'id_colletta' => $colletta->id,
                'id_utente' => $utente->id,
                'quantita' => $quantita,
                'importo_acconto' => 0,
                'importo_commissione' => 0,
                'stato' => 'prenotata',
            ]);

            $colletta->increment('quantita_attuale', $quantita);
            $colletta->refresh();

            // Mise à jour du prezzo en fonction des scaglioni atteints
            $scaglione = $colletta->scaglioni()
                ->where('soglia_partecipanti', '<=', $colletta->quantita_attuale)
                ->orderByDesc('soglia_partecipanti')
                ->first();

            if ($scaglione) {
                $colletta->prezzo_corrente = $scaglione->prezzo_unitario;
            }

            // Passage à riuscita si le MOQ est atteint
            if ($colletta->quantita_attuale >= $colletta->quantita_minima && $colletta->stato === 'in_corso') {
                $colletta->stato = 'riuscita';
                $colletta->data_agg_stato = now();

                // Notification MOQ RAGGIUNTO aux participants
                $pagante = $prenotazione->id_utente;
                // Envoie une notification à l'ouvreur de la colletta
                if ($colletta->id_aperta_da) {
                    Notifica::create([
                        'id_utente' => $colletta->id_aperta_da,
                        'tipo' => 'MOQ_RAGGIUNTO',
                        'titolo' => 'Soglia Raggiunta',
                        'messaggio' => 'La campagna ha raggiunto la soglia minima! QR code disponibile.',
                        'tipo_riferimento' => 'colletta',
                        'id_riferimento' => $colletta->id,
                    ]);
                }
            }

            $colletta->save();

            return $prenotazione->load('colletta.prodotto.fornitore');
        });

        return response()->json(['successo' => true, 'dati' => $prenotazione], 201);
    }

    public function confermaOrdine(Request $request, $id)
    {
        $utente = JWTAuth::parseToken()->authenticate();

        $colletta = Colletta::with(['prenotazioni', 'prodotto.fornitore'])->find($id);

        if (!$colletta) {
            return response()->json(['successo' => false, 'errore' => 'Colletta non trovata.'], 404);
        }

        if ($colletta->stato !== 'riuscita') {
            return response()->json(['successo' => false, 'errore' => 'La campagna deve essere riuscita per confermare l\'ordine.'], 422);
        }

        $esistente = OrdineFornitore::where('id_colletta', $colletta->id)->exists();

        if ($esistente) {
            return response()->json(['successo' => false, 'errore' => 'Ordine già creato per questa campagna.'], 422);
        }

        DB::transaction(function () use ($colletta, $utente) {
            $prenotazioniAttive = $colletta->prenotazioni()
                ->whereIn('stato', ['prenotata', 'confermata'])
                ->get();

            $quantitaTotale = $prenotazioniAttive->sum('quantita');

            // Création de l'ordre fournisseur
            OrdineFornitore::create([
                'id_colletta' => $colletta->id,
                'id_fornitore' => $colletta->prodotto->id_fornitore,
                'id_admin' => $utente->id,
                'quantita_ordinata' => $quantitaTotale,
                'prezzo_negoziato' => $colletta->prezzo_corrente,
                'importo_totale' => round($quantitaTotale * $colletta->prezzo_corrente, 2),
                'stato' => 'inviato',
                'data_ordine' => now(),
            ]);

            // Confirme les prenotazioni, met à jour leur montant, et génère les QR codes
            foreach ($prenotazioniAttive as $p) {
                $importoSaldo = round($p->quantita * $colletta->prezzo_corrente, 2);
                $importoCommissione = round($importoSaldo * ($colletta->percentuale_commissione / 100), 2);

                $p->update([
                    'stato' => 'confermata',
                    'importo_saldo' => $importoSaldo,
                    'importo_commissione' => $importoCommissione,
                    'data_pagamento' => now(),
                ]);

                // QR code
                QrCode::firstOrCreate(
                    ['id_prenotazione' => $p->id],
                    [
                        'token' => 'qr_' . Str::random(60),
                        'quantita_assegnata' => $p->quantita,
                        'stato' => 'generato',
                    ]
                );

                // Notification
                Notifica::create([
                    'id_utente' => $p->id_utente,
                    'tipo' => 'ORDINE_CONFERMATO',
                    'titolo' => 'Ordine Confermato',
                    'messaggio' => 'Ordine confermato dall\'admin. QR code disponibile.',
                    'tipo_riferimento' => 'prenotazione',
                    'id_riferimento' => $p->id,
                ]);
            }

            // Met à jour le statut de la colletta
            $colletta->update([
                'stato' => 'ordine_fornitore',
                'id_admin_conferma' => $utente->id,
                'data_conferma' => now(),
                'data_agg_stato' => now(),
            ]);
        });

        $colletta->refresh();
        $colletta->load(['prodotto.fornitore', 'ordineFornitore']);

        return response()->json(['successo' => true, 'dati' => $colletta]);
    }

    public function confermaConsegna(Request $request, $id)
    {
        $colletta = Colletta::find($id);

        if (!$colletta) {
            return response()->json(['successo' => false, 'errore' => 'Colletta non trovata.'], 404);
        }

        if ($colletta->stato !== 'ordine_fornitore') {
            return response()->json(['successo' => false, 'errore' => 'La campagna deve essere in stato ordine_fornitore.'], 422);
        }

        DB::transaction(function () use ($colletta) {
            $colletta->update([
                'stato' => 'consegnata',
                'data_agg_stato' => now(),
            ]);

            // Notification aux participants
            $colletta->prenotazioni()->where('stato', 'confermata')->get()->each(function ($p) {
                Notifica::create([
                    'id_utente' => $p->id_utente,
                    'tipo' => 'MERCE_PRONTA',
                    'titolo' => 'Merce Pronta',
                    'messaggio' => 'La merce è pronta per il ritiro!',
                    'tipo_riferimento' => 'prenotazione',
                    'id_riferimento' => $p->id,
                ]);
            });
        });

        $colletta->load('prodotto.fornitore');

        return response()->json(['successo' => true, 'dati' => $colletta]);
    }
}
