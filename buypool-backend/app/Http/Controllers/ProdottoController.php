<?php

namespace App\Http\Controllers;

use App\Models\Prodotto;
use App\Models\ImmagineProdotto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Facades\JWTAuth;

class ProdottoController extends Controller
{
    public function index(Request $request)
    {
        $query = Prodotto::with(['fornitore', 'categoria', 'immagini'])
            ->where('stato', 'attivo');

        if ($request->has('categoria')) {
            $query->where('id_categoria', $request->input('categoria'));
        }

        if ($request->has('fornitore')) {
            $query->where('id_fornitore', $request->input('fornitore'));
        }

        $prodotti = $query->orderByDesc('data_creazione')->get();

        return response()->json(['successo' => true, 'dati' => $prodotti]);
    }

    public function show($id)
    {
        $prodotto = Prodotto::with(['fornitore', 'categoria', 'immagini'])->find($id);

        if (!$prodotto) {
            return response()->json(['successo' => false, 'errore' => 'Prodotto non trovato.'], 404);
        }

        return response()->json(['successo' => true, 'dati' => $prodotto]);
    }

    public function store(Request $request)
    {
        $utente = JWTAuth::parseToken()->authenticate();
        $isAdmin = $utente->ruolo === 'admin';
        $isFornitore = $utente->ruolo === 'fornitore';

        if (!$isAdmin && !$isFornitore) {
            return response()->json(['successo' => false, 'errore' => 'Accesso non autorizzato.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'nome' => 'required|string|max:200',
            'descrizione' => 'nullable|string',
            'prezzo_unitario' => 'required|numeric|min:0.01',
            'quantita_minima' => 'required|integer|min:1',
            'id_categoria' => 'nullable|exists:categorie,id',
            'immagini' => 'nullable|array',
            'immagini.*.url' => 'required_with:immagini|url',
        ]);

        if ($validator->fails()) {
            return response()->json(['successo' => false, 'errori' => $validator->errors()], 422);
        }

        $idFornitore = $isAdmin
            ? ($request->input('id_fornitore') ?? $utente->id)
            : $utente->id;

        $prodotto = Prodotto::create([
            'id_fornitore' => $idFornitore,
            'id_categoria' => $request->input('id_categoria'),
            'id_proposta_origine' => $request->input('id_proposta_origine'),
            'nome' => $request->nome,
            'descrizione' => $request->descrizione,
            'prezzo_unitario' => $request->prezzo_unitario,
            'quantita_minima' => $request->quantita_minima,
            'stato' => 'attivo',
        ]);

        if ($request->has('immagini')) {
            foreach ($request->input('immagini') as $index => $immagine) {
                ImmagineProdotto::create([
                    'id_prodotto' => $prodotto->id,
                    'url' => $immagine['url'],
                    'ordine' => $immagine['ordine'] ?? $index,
                    'principale' => $immagine['principale'] ?? ($index === 0),
                ]);
            }
        }

        $prodotto->load(['fornitore', 'categoria', 'immagini']);

        return response()->json(['successo' => true, 'dati' => $prodotto], 201);
    }

    public function update(Request $request, $id)
    {
        $prodotto = Prodotto::find($id);

        if (!$prodotto) {
            return response()->json(['successo' => false, 'errore' => 'Prodotto non trovato.'], 404);
        }

        $utente = JWTAuth::parseToken()->authenticate();
        $isAdmin = $utente->ruolo === 'admin';
        $isOwner = $utente->ruolo === 'fornitore' && $prodotto->id_fornitore === $utente->id;

        if (!$isAdmin && !$isOwner) {
            return response()->json(['successo' => false, 'errore' => 'Accesso non autorizzato.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'nome' => 'sometimes|string|max:200',
            'descrizione' => 'nullable|string',
            'prezzo_unitario' => 'sometimes|numeric|min:0.01',
            'quantita_minima' => 'sometimes|integer|min:1',
            'id_categoria' => 'nullable|exists:categorie,id',
            'stato' => 'sometimes|in:attivo,archiviato',
        ]);

        if ($validator->fails()) {
            return response()->json(['successo' => false, 'errori' => $validator->errors()], 422);
        }

        $prodotto->update($request->only([
            'nome', 'descrizione', 'prezzo_unitario', 'quantita_minima', 'id_categoria', 'stato',
        ]));

        $prodotto->load(['fornitore', 'categoria', 'immagini']);

        return response()->json(['successo' => true, 'dati' => $prodotto]);
    }

    public function destroy(Request $request, $id)
    {
        $prodotto = Prodotto::find($id);

        if (!$prodotto) {
            return response()->json(['successo' => false, 'errore' => 'Prodotto non trovato.'], 404);
        }

        $utente = JWTAuth::parseToken()->authenticate();
        $isAdmin = $utente->ruolo === 'admin';
        $isOwner = $utente->ruolo === 'fornitore' && $prodotto->id_fornitore === $utente->id;

        if (!$isAdmin && !$isOwner) {
            return response()->json(['successo' => false, 'errore' => 'Accesso non autorizzato.'], 403);
        }

        $prodotto->delete();

        return response()->json(['successo' => true, 'dati' => ['messaggio' => 'Prodotto eliminato.']]);
    }
}
