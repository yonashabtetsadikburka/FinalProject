<?php

namespace App\Http\Controllers;

use App\Models\Colletta;
use App\Models\Utente;
use App\Models\Prodotto;
use App\Models\Prenotazione;
use Illuminate\Http\Request;

class SessionController extends Controller
{
    public function io()
    {
        return response()->json([
            'successo' => true,
            'dati' => [
                'api' => 'online',
                'servizio' => 'offline',
            ],
        ]);
    }

    public function statistiche()
    {
        return response()->json([
            'successo' => true,
            'dati' => [
                'utenti' => Utente::count(),
                'prodotti' => Prodotto::count(),
                'campagne' => Colletta::count(),
                'campagne_attive' => Colletta::where('stato', 'in_corso')->count(),
                'prenotazioni' => Prenotazione::count(),
            ],
        ]);
    }
}
