<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Categoria extends Model
{
    protected $table = 'categorie';
    public $timestamps = false;
    protected $fillable = ['nome', 'descrizione'];

    public function prodotti()
    {
        return $this->hasMany(Prodotto::class, 'id_categoria');
    }
}
