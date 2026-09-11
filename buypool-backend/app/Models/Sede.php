<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Sede extends Model
{
    protected $table = 'sedi';
    protected $primaryKey = 'id_sede';
    public $timestamps = false;
    protected $fillable = ['nome', 'indirizzo', 'citta', 'telefono', 'orari'];
}
