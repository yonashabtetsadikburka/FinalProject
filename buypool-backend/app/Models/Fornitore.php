<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Fornitore extends Model
{
    protected $table = 'fornitori';
    protected $primaryKey = 'id';
    public $incrementing = true;

    // La PK "id" est auto-incrémentée (gérée par Laravel) ; "id_utente" est la
    // FK par défaut vers utenti (déclarée explicitement via $fillable et
    // belongsTo) : NULL tant que la fiche n'est liée à aucun compte.

    public $timestamps = false;

    protected $fillable = [
        'id_utente', 'nome_azienda', 'email_contatto', 'telefono',
        'indirizzo', 'descrizione', 'logo_url', 'partner_pubblico',
        'trust_score', 'num_campagne',
    ];

    protected $casts = [
        'id_utente' => 'integer',
        'partner_pubblico' => 'boolean',
    ];

    public function utente()
    {
        return $this->belongsTo(Utente::class, 'id_utente');
    }

    public function prodotti()
    {
        return $this->hasMany(Prodotto::class, 'id_fornitore', 'id_utente');
    }

    public function inviti()
    {
        return $this->hasMany(InvitoFornitore::class, 'id_fornitore');
    }

    /**
     * Fiche fournisseur liée à un compte utilisateur donné (helper équivalent
     * à fornitore_id_corrente() : renvoie l'id de fiche, ou null).
     */
    public static function perUtente(?int $idUtente): ?self
    {
        if (!$idUtente) {
            return null;
        }

        return self::where('id_utente', $idUtente)->first();
    }

    /**
     * La fiche est-elle libre (aucun compte utilisateur lié) ?
     */
    public function isLibero(): bool
    {
        return $this->id_utente === null;
    }
}