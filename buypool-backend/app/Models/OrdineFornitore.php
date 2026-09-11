<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrdineFornitore extends Model
{
    protected $table = 'ordini_fornitore';
    public $timestamps = false;

    protected $fillable = [
        'id_colletta', 'id_fornitore', 'id_admin', 'quantita_ordinata',
        'prezzo_negoziato', 'importo_totale', 'stato', 'data_ordine', 'data_consegna',
    ];

    public function colletta()
    {
        return $this->belongsTo(Colletta::class, 'id_colletta');
    }

    public function fornitore()
    {
        return $this->belongsTo(Fornitore::class, 'id_fornitore', 'id_utente');
    }
}
