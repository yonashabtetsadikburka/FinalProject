<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Notifica extends Model
{
    protected $table = 'notifiche';
    public $timestamps = false;

    protected $fillable = [
        'id_utente', 'tipo', 'titolo', 'messaggio',
        'tipo_riferimento', 'id_riferimento', 'letta', 'data_lettura',
    ];
}
