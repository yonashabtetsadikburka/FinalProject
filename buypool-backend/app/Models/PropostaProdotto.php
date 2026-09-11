<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PropostaProdotto extends Model
{
    protected $table = 'proposte_prodotti';
    protected $primaryKey = 'id_proposta';
    public $timestamps = false;

    protected $fillable = [
        'proponente_tipo', 'proponente_id', 'nome_prodotto', 'descrizione',
        'id_fornitore_suggerito', 'stato', 'id_admin_gestione', 'tot_voti',
    ];

    public function voti()
    {
        return $this->hasMany(VotoProposta::class, 'id_proposta');
    }

    public function proponente()
    {
        return $this->belongsTo(Utente::class, 'proponente_id');
    }
}
