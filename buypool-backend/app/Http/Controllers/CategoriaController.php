<?php

namespace App\Http\Controllers;

use App\Models\Categoria;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CategoriaController extends Controller
{
    public function index()
    {
        return response()->json([
            'successo' => true,
            'dati' => Categoria::all(),
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'nome' => 'required|string|max:100|unique:categorie,nome',
            'descrizione' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['successo' => false, 'errori' => $validator->errors()], 422);
        }

        $categoria = Categoria::create($request->only(['nome', 'descrizione']));

        return response()->json(['successo' => true, 'dati' => $categoria], 201);
    }

    public function show($id)
    {
        $categoria = Categoria::find($id);

        if (!$categoria) {
            return response()->json(['successo' => false, 'errore' => 'Categoria non trovata.'], 404);
        }

        return response()->json(['successo' => true, 'dati' => $categoria]);
    }

    public function update(Request $request, $id)
    {
        $categoria = Categoria::find($id);

        if (!$categoria) {
            return response()->json(['successo' => false, 'errore' => 'Categoria non trovata.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'nome' => 'sometimes|string|max:100|unique:categorie,nome,' . $id,
            'descrizione' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['successo' => false, 'errori' => $validator->errors()], 422);
        }

        $categoria->update($request->only(['nome', 'descrizione']));

        return response()->json(['successo' => true, 'dati' => $categoria]);
    }

    public function destroy($id)
    {
        $categoria = Categoria::find($id);

        if (!$categoria) {
            return response()->json(['successo' => false, 'errore' => 'Categoria non trovata.'], 404);
        }

        $categoria->delete();

        return response()->json(['successo' => true, 'dati' => ['messaggio' => 'Categoria eliminata.']]);
    }
}
