<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Colletta extends Model
{
    protected $table = 'collette';
    public $timestamps = false;

    protected $fillable = [
        'id_prodotto', 'id_aperta_da', 'id_referente', 'id_sede',
        'quantita_minima', 'quantita_attuale', 'data_inizio', 'data_limite', 'stato',
        'data_agg_stato', 'regola_arrotondamento', 'prezzo_base', 'prezzo_corrente',
        'percentuale_commissione', 'id_admin_conferma', 'data_conferma',
    ];

    public function prodotto()
    {
        return $this->belongsTo(Prodotto::class, 'id_prodotto');
    }

    public function scaglioni()
    {
        return $this->hasMany(ScaglionePrezzo::class, 'id_colletta');
    }

    public function prenotazioni()
    {
        return $this->hasMany(Prenotazione::class, 'id_colletta');
    }

    public function ordineFornitore()
    {
        return $this->hasOne(OrdineFornitore::class, 'id_colletta');
    }

    /**
     * Percentuale di avanzamento vers la soglia — calculée, jamais stockée.
     */
    public function getPercentualeAvanzamentoAttribute(): float
    {
        if ($this->quantita_minima <= 0) {
            return 0.0;
        }

        return round(min(100, ($this->quantita_attuale / $this->quantita_minima) * 100), 1);
    }
}
