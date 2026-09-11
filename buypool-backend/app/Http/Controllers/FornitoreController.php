<?php

namespace App\Http\Controllers;

use App\Models\Fornitore;
use Illuminate\Http\Request;

class FornitoreController extends Controller
{
    public function index(Request $request)
    {
        $fornitori = Fornitore::where('partner_pubblico', true)
            ->orderByDesc('trust_score')
            ->get();

        return response()->json(['successo' => true, 'dati' => $fornitori]);
    }

    public function show(Request $request, $id)
    {
        $fornitore = Fornitore::with('prodotti')
            ->where('partner_pubblico', true)
            ->find($id);

        if (!$fornitore) {
            return response()->json(['successo' => false, 'errore' => 'Fornitore non trovato.'], 404);
        }

        $numCampagne = $fornitore->prodotti->reduce(function ($carry, $prodotto) {
            return $carry + $prodotto->collette()->count();
        }, 0);

        $fornitore->setAttribute('num_campagne', $numCampagne);

        return response()->json(['successo' => true, 'dati' => $fornitore]);
    }
}
