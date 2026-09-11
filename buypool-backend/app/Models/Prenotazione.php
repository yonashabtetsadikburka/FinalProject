<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Prenotazione extends Model
{
    protected $table = 'prenotazioni';
    public $timestamps = false;

    protected $fillable = [
        'id_colletta', 'id_utente', 'quantita', 'importo_acconto',
        'importo_saldo', 'importo_commissione', 'stato', 'data_pagamento',
    ];

    public function colletta()
    {
        return $this->belongsTo(Colletta::class, 'id_colletta');
    }

    public function utente()
    {
        return $this->belongsTo(Utente::class, 'id_utente');
    }

    public function pagamenti()
    {
        return $this->hasMany(Pagamento::class, 'id_prenotazione');
    }

    public function qrCode()
    {
        return $this->hasOne(QrCode::class, 'id_prenotazione');
    }

    public function consegna()
    {
        return $this->hasOne(Consegna::class, 'id_prenotazione');
    }
}
