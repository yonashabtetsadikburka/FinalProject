<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventoStripe extends Model
{
    protected $table = 'eventi_stripe';
    public $timestamps = false;

    protected $fillable = [
        'stripe_event_id', 'tipo_evento', 'id_pagamento',
        'payload_json', 'elaborato',
    ];

    protected $casts = [
        'payload_json' => 'array',
    ];
}
