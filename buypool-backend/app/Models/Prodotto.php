<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Prodotto extends Model
{
    protected $table = 'prodotti';
    public $timestamps = false;

    protected $fillable = [
        'id_fornitore', 'id_categoria', 'id_proposta_origine',
        'nome', 'descrizione', 'prezzo_unitario', 'quantita_minima', 'stato',
    ];

    public function fornitore()
    {
        return $this->belongsTo(Fornitore::class, 'id_fornitore', 'id_utente');
    }

    public function categoria()
    {
        return $this->belongsTo(Categoria::class, 'id_categoria');
    }

    public function immagini()
    {
        return $this->hasMany(ImmagineProdotto::class, 'id_prodotto');
    }

    public function collette()
    {
        return $this->hasMany(Colletta::class, 'id_prodotto');
    }
}
