<?php

namespace App\Http\Controllers;

use App\Models\OrdineFornitore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class OrdineFornitoreController extends Controller
{
    public function index()
    {
        $ordini = OrdineFornitore::with('colletta.prodotto.fornitore')
            ->orderByDesc('data_ordine')
            ->get()
            ->map(function ($ordine) {
                if ($ordine->colletta && $ordine->colletta->prodotto) {
                    $ordine->colletta->setAttribute('fornitore', $ordine->colletta->prodotto->fornitore ?? null);
                }
                return $ordine;
            });

        return response()->json(['successo' => true, 'dati' => $ordini]);
    }

    public function show($id)
    {
        $ordine = OrdineFornitore::with('colletta.prodotto.fornitore')->find($id);

        if (!$ordine) {
            return response()->json(['successo' => false, 'errore' => 'Ordine fornitore non trovato.'], 404);
        }

        return response()->json(['successo' => true, 'dati' => $ordine]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id_colletta' => 'required|exists:collette,id',
            'quantita_ordinata' => 'required|integer|min:1',
            'prezzo_negoziato' => 'nullable|numeric|min:0.01',
            'importo_totale' => 'required|numeric|min:0.01',
        ]);

        if ($validator->fails()) {
            return response()->json(['successo' => false, 'errori' => $validator->errors()], 422);
        }

        $esistente = OrdineFornitore::where('id_colletta', $request->id_colletta)->exists();

        if ($esistente) {
            return response()->json(['successo' => false, 'errore' => 'Ordine già esistente per questa campagna.'], 422);
        }

        $ordine = OrdineFornitore::create([
            'id_colletta' => $request->id_colletta,
            'id_fornitore' => $request->input('id_fornitore'),
            'id_admin' => auth('api')->id(),
            'quantita_ordinata' => $request->quantita_ordinata,
            'prezzo_negoziato' => $request->input('prezzo_negoziato'),
            'importo_totale' => $request->importo_totale,
            'stato' => 'da_negoziare',
            'data_ordine' => now(),
        ]);

        $ordine->load('colletta.prodotto.fornitore');

        return response()->json(['successo' => true, 'dati' => $ordine], 201);
    }

    public function update(Request $request, $id)
    {
        $ordine = OrdineFornitore::find($id);

        if (!$ordine) {
            return response()->json(['successo' => false, 'errore' => 'Ordine fornitore non trovato.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'prezzo_negoziato' => 'nullable|numeric|min:0.01',
            'importo_totale' => 'sometimes|numeric|min:0.01',
            'stato' => 'sometimes|in:da_negoziare,inviato,consegnato,annullato',
            'data_consegna' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json(['successo' => false, 'errori' => $validator->errors()], 422);
        }

        $ordine->update($request->only([
            'prezzo_negoziato', 'importo_totale', 'stato', 'data_consegna',
        ]));

        if ($request->stato === 'inviato' && !$ordine->data_ordine) {
            $ordine->update(['data_ordine' => now()]);
        }
        if ($request->stato === 'consegnato' && !$ordine->data_consegna) {
            $ordine->update(['data_consegna' => now()]);
        }

        $ordine->load('colletta.prodotto.fornitore');

        return response()->json(['successo' => true, 'dati' => $ordine]);
    }
}
