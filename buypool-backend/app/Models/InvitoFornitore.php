<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvitoFornitore extends Model
{
    protected $table = 'inviti_fornitore';

    protected $fillable = [
        'id_fornitore', 'email', 'token', 'scadenza', 'usato',
    ];

    protected $casts = [
        'scadenza' => 'datetime',
        'usato' => 'boolean',
    ];

    public function fornitore()
    {
        return $this->belongsTo(Fornitore::class, 'id_fornitore');
    }

    public function isScaduto(): bool
    {
        return $this->scadenza->isPast();
    }
}