<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VotoProposta extends Model
{
    protected $table = 'voti_proposte';
    protected $primaryKey = 'id_voto';
    public $timestamps = false;
    protected $fillable = ['id_proposta', 'id_utente', 'valore_voto'];
}
