<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Tymon\JWTAuth\Contracts\JWTSubject;

/**
 * Modello Utente — corrisponde alla tabella "utenti".
 * Implementa JWTSubject per essere usato con tymon/jwt-auth.
 */
class Utente extends Authenticatable implements JWTSubject
{
    protected $table = 'utenti';

    // Laravel gestisce created_at/updated_at di default: qui usiamo
    // data_iscrizione a mano, quindi disattiviamo i timestamp automatici
    public $timestamps = false;

    protected $fillable = [
        'nome', 'cognome', 'email', 'password_hash', 'telefono',
        'indirizzo', 'tipo', 'ruolo', 'stato',
        'google_id', 'microsoft_id', 'stripe_customer_id', 'partita_iva',
    ];

    protected $hidden = [
        'password_hash',
    ];

    // Laravel s'attend par défaut à une colonne "password" pour l'auth —
    // on lui dit d'utiliser "password_hash" à la place
    public function getAuthPassword()
    {
        return $this->password_hash;
    }

    // ------------------------------------------------------------
    // Relazioni
    // ------------------------------------------------------------
    public function fornitore()
    {
        return $this->hasOne(Fornitore::class, 'id_utente');
    }

    public function prenotazioni()
    {
        return $this->hasMany(Prenotazione::class, 'id_utente');
    }

    public function notifiche()
    {
        return $this->hasMany(Notifica::class, 'id_utente');
    }

    // ------------------------------------------------------------
    // JWTSubject — richiesto da tymon/jwt-auth
    // ------------------------------------------------------------
    public function getJWTIdentifier()
    {
        return $this->getKey(); // id
    }

    public function getJWTCustomClaims(): array
    {
        // Il ruolo est inclus dans le payload, comme dans notre version PHP pur —
        // le frontend l'utilise pour choisir quelle page afficher
        return [
            'ruolo' => $this->ruolo,
        ];
    }
}
