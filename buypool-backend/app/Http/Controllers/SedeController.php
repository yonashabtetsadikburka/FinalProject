<?php

namespace App\Http\Controllers;

use App\Models\Sede;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SedeController extends Controller
{
    public function index()
    {
        return response()->json([
            'successo' => true,
            'dati' => Sede::all(),
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'nome' => 'required|string|max:200',
            'indirizzo' => 'required|string|max:255',
            'citta' => 'required|string|max:100',
            'telefono' => 'nullable|string|max:30',
            'orari' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['successo' => false, 'errori' => $validator->errors()], 422);
        }

        $sede = Sede::create($request->only(['nome', 'indirizzo', 'citta', 'telefono', 'orari']));

        return response()->json(['successo' => true, 'dati' => $sede], 201);
    }

    public function show($id)
    {
        $sede = Sede::find($id);

        if (!$sede) {
            return response()->json(['successo' => false, 'errore' => 'Sede non trovata.'], 404);
        }

        return response()->json(['successo' => true, 'dati' => $sede]);
    }

    public function update(Request $request, $id)
    {
        $sede = Sede::find($id);

        if (!$sede) {
            return response()->json(['successo' => false, 'errore' => 'Sede non trovata.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'nome' => 'sometimes|string|max:200',
            'indirizzo' => 'sometimes|string|max:255',
            'citta' => 'sometimes|string|max:100',
            'telefono' => 'nullable|string|max:30',
            'orari' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['successo' => false, 'errori' => $validator->errors()], 422);
        }

        $sede->update($request->only(['nome', 'indirizzo', 'citta', 'telefono', 'orari']));

        return response()->json(['successo' => true, 'dati' => $sede]);
    }

    public function destroy($id)
    {
        $sede = Sede::find($id);

        if (!$sede) {
            return response()->json(['successo' => false, 'errore' => 'Sede non trovata.'], 404);
        }

        $sede->delete();

        return response()->json(['successo' => true, 'dati' => ['messaggio' => 'Sede eliminata.']]);
    }
}
