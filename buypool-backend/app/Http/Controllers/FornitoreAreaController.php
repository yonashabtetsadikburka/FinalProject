<?php

namespace App\Http\Controllers;

use App\Models\Colletta;
use App\Models\Fornitore;
use App\Models\ImmagineProdotto;
use App\Models\OrdineFornitore;
use App\Models\Prodotto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Espace fournisseur : tableaux de bord, gestion des produits et suivi des
 * ordres/campagnes pour le compte retourné par le middleware richiedi_fornitore.
 *
 * Le compte "fornitore" est lié à une fiche Fornitore via fornitori.id_utente.
 * Les produits et les ordres du fournisseur sont référencés par utenti.id
 * (colonne id_fornitore), pas par l'id de fiche.
 */
class FornitoreAreaController extends Controller
{
    private function fiche(): ?Fornitore
    {
        return Fornitore::perUtente(auth('api')->id());
    }

    private function mieiIdsProdotti()
    {
        return Prodotto::where('id_fornitore', auth('api')->id())->pluck('id');
    }

    // ------------------------------------------------------------
    // Récapitulatif de l'espace fournisseur
    // ------------------------------------------------------------
    public function me()
    {
        $fornitore = $this->fiche();

        if (!$fornitore) {
            return response()->json(['successo' => false, 'errore' => 'Nessuna scheda fornitore collegata al tuo account.'], 404);
        }

        $idsProdotti = $this->mieiIdsProdotti();

        $collette = Colletta::whereIn('id_prodotto', $idsProdotti)->get();
        $ordini = OrdineFornitore::where('id_fornitore', auth('api')->id())->get();

        $fatturato = $ordini
            ->reject(fn ($o) => $o->stato === 'annullato')
            ->sum(fn ($o) => (float) $o->importo_totale);

        $statistiche = [
            'prodotti' => $idsProdotti->count(),
            'collette_attive' => $collette->where('stato', 'in_corso')->count(),
            'collette_totali' => $collette->count(),
            'ordini_da_negoziare' => $ordini->where('stato', 'da_negoziare')->count(),
            'ordini_totali' => $ordini->count(),
            'fatturato' => round($fatturato, 2),
        ];

        $fornitore->unsetRelation('prodotti');
        $fornitore->unsetRelation('inviti');

        return response()->json([
            'successo' => true,
            'dati' => [
                'fornitore' => $fornitore,
                'statistiche' => $statistiche,
            ],
        ]);
    }

    // ------------------------------------------------------------
    // Produits du fournisseur
    // ------------------------------------------------------------
    public function prodotti()
    {
        $prodotti = Prodotto::with(['categoria', 'immagini', 'collette'])
            ->where('id_fornitore', auth('api')->id())
            ->orderByDesc('data_creazione')
            ->get();

        return response()->json(['successo' => true, 'dati' => $prodotti]);
    }

    public function salvaProdotto(Request $request)
    {
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

        $prodotto = Prodotto::create([
            'id_fornitore' => auth('api')->id(),
            'id_categoria' => $request->input('id_categoria'),
            'nome' => $request->nome,
            'descrizione' => $request->descrizione,
            'prezzo_unitario' => $request->prezzo_unitario,
            'quantita_minima' => $request->quantita_minima,
            'stato' => 'attivo',
        ]);

        $this->sincronizzaImmagini($prodotto, $request->input('immagini'));

        $prodotto->load(['categoria', 'immagini', 'collette']);

        return response()->json(['successo' => true, 'dati' => $prodotto], 201);
    }

    public function aggiornaProdotto(Request $request, $id)
    {
        $prodotto = $this->prodottoProprio($id);

        if (!$prodotto) {
            return response()->json(['successo' => false, 'errore' => 'Prodotto non trovato.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'nome' => 'sometimes|string|max:200',
            'descrizione' => 'nullable|string',
            'prezzo_unitario' => 'sometimes|numeric|min:0.01',
            'quantita_minima' => 'sometimes|integer|min:1',
            'id_categoria' => 'nullable|exists:categorie,id',
            'stato' => 'sometimes|in:attivo,archiviato',
            'immagini' => 'nullable|array',
            'immagini.*.url' => 'required_with:immagini|url',
        ]);

        if ($validator->fails()) {
            return response()->json(['successo' => false, 'errori' => $validator->errors()], 422);
        }

        $prodotto->update($request->only([
            'nome', 'descrizione', 'prezzo_unitario', 'quantita_minima', 'id_categoria', 'stato',
        ]));

        if ($request->has('immagini')) {
            ImmagineProdotto::where('id_prodotto', $prodotto->id)->delete();
            $this->sincronizzaImmagini($prodotto, $request->input('immagini'));
        }

        $prodotto->load(['categoria', 'immagini', 'collette']);

        return response()->json(['successo' => true, 'dati' => $prodotto]);
    }

    public function eliminaProdotto($id)
    {
        $prodotto = $this->prodottoProprio($id);

        if (!$prodotto) {
            return response()->json(['successo' => false, 'errore' => 'Prodotto non trovato.'], 404);
        }

        $haCollette = Colletta::where('id_prodotto', $prodotto->id)->exists();

        if ($haCollette) {
            return response()->json([
                'successo' => false,
                'errore' => 'Impossibile eliminare il prodotto: esistono campagne collegate. Puoi archiviarlo.',
            ], 422);
        }

        $prodotto->delete();

        return response()->json(['successo' => true, 'dati' => ['messaggio' => 'Prodotto eliminato.']]);
    }

    // ------------------------------------------------------------
    // Ordres passés vers le fournisseur
    // ------------------------------------------------------------
    public function ordini()
    {
        $ordini = OrdineFornitore::with('colletta.prodotto')
            ->where('id_fornitore', auth('api')->id())
            ->orderByDesc('data_ordine')
            ->get()
            ->map(function ($ordine) {
                if ($ordine->colletta) {
                    $colletta = $ordine->colletta;
                    $colletta->setAttribute('percentuale_avanzamento', $colletta->percentuale_avanzamento);
                }
                return $ordine;
            });

        return response()->json(['successo' => true, 'dati' => $ordini]);
    }

    // ------------------------------------------------------------
    // Campagne lancées sur les produits du fournisseur
    // ------------------------------------------------------------
    public function collette()
    {
        $idsProdotti = $this->mieiIdsProdotti();

        $collette = Colletta::with(['prodotto', 'prenotazioni'])
            ->whereIn('id_prodotto', $idsProdotti)
            ->orderByDesc('data_inizio')
            ->get()
            ->map(function ($colletta) {
                $colletta->setAttribute('percentuale_avanzamento', $colletta->percentuale_avanzamento);
                return $colletta;
            });

        return response()->json(['successo' => true, 'dati' => $collette]);
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------
    private function prodottoProprio($id): ?Prodotto
    {
        return Prodotto::where('id', $id)
            ->where('id_fornitore', auth('api')->id())
            ->first();
    }

    private function sincronizzaImmagini(Prodotto $prodotto, ?array $immagini): void
    {
        if (empty($immagini)) {
            return;
        }

        foreach ($immagini as $index => $immagine) {
            ImmagineProdotto::create([
                'id_prodotto' => $prodotto->id,
                'url' => $immagine['url'],
                'ordine' => $immagine['ordine'] ?? $index,
                'principale' => $immagine['principale'] ?? ($index === 0),
            ]);
        }
    }
}