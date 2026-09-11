<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QrCode extends Model
{
    protected $table = 'qr_codes';
    public $timestamps = false;

    protected $fillable = [
        'id_prenotazione', 'token', 'quantita_assegnata', 'stato', 'data_scansione',
    ];
}
