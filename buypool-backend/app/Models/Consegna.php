<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Consegna extends Model
{
    protected $table = 'consegne';
    public $timestamps = false;

    protected $fillable = [
        'id_prenotazione', 'modalita', 'id_sede', 'indirizzo_consegna',
        'importo_consegna', 'stato', 'data_prevista', 'data_effettiva',
    ];

    public function sede()
    {
        return $this->belongsTo(Sede::class, 'id_sede');
    }
}
