<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class WalletController extends Controller
{
    public function saldo()
    {
        return response()->json([
            'successo' => true,
            'dati' => [
                'saldo' => '0.00',
            ],
        ]);
    }

    public function movimenti()
    {
        return response()->json([
            'successo' => true,
            'dati' => [],
        ]);
    }
}
