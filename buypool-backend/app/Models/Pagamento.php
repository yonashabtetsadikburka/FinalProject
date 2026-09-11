<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Pagamento extends Model
{
    protected $table = 'pagamenti';
    public $timestamps = false;

    protected $fillable = [
        'id_prenotazione', 'tipo_pagamento', 'importo', 'valuta',
        'commissione_agenzia', 'stripe_payment_intent_id', 'stripe_charge_id',
        'stripe_payment_method', 'stato_stripe', 'stato', 'data_conferma',
    ];

    public function prenotazione()
    {
        return $this->belongsTo(Prenotazione::class, 'id_prenotazione');
    }

    public function eventiStripe()
    {
        return $this->hasMany(EventoStripe::class, 'id_pagamento');
    }
}
