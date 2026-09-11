<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScaglionePrezzo extends Model
{
    protected $table = 'scaglioni_prezzo';
    public $timestamps = false;
    protected $fillable = ['id_colletta', 'soglia_partecipanti', 'prezzo_unitario'];
}
