<?php

namespace App\Http\Controllers;

use App\Models\Fornitore;
use App\Models\InvitoFornitore;
use App\Models\Utente;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/**
 * Flux invitation → activation → collegamento des comptes fournisseur.
 *
 * Un fournisseur ne s'inscrit plus lui-même : un admin génère une invitation
 * pour une fiche fornitori libre, le fournisseur l'active en choisissant un
 * mot de passe, puis se connecte normalement via /auth/login (compte utenti
 * classique avec ruolo = 'fornitore').
 */
class FornitoreInvitoController extends Controller
{
    // ------------------------------------------------------------
    // Admin : liste toutes les fiches fournisseur (liées ou non)
    // ------------------------------------------------------------
    public function tutte()
    {
        $fornitori = Fornitore::with('inviti')->get()->map(function (Fornitore $fornitore) {
            $invitoAttivo = $fornitore->inviti
                ->where('usato', false)
                ->reject(fn (InvitoFornitore $invito) => $invito->scadenza->isPast())
                ->sortByDesc('scadenza')
                ->first();

            $stato = 'libero';
            if ($fornitore->id_utente !== null) {
                $stato = 'collegato';
            } elseif ($invitoAttivo) {
                $stato = 'in_attesa';
            }

            return [
                'id' => $fornitore->id,
                'id_utente' => $fornitore->id_utente,
                'nome_azienda' => $fornitore->nome_azienda,
                'email_contatto' => $fornitore->email_contatto,
                'partner_pubblico' => $fornitore->partner_pubblico,
                'stato' => $stato,
                'scadenza_invito' => $invitoAttivo ? $invitoAttivo->scadenza : null,
            ];
        });

        return response()->json(['successo' => true, 'dati' => $fornitori]);
    }

    // ------------------------------------------------------------
    // Admin : crée une fiche fournisseur et (optionnellement) l'invitation
    // ------------------------------------------------------------
    public function crea(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'nome_azienda' => 'required|string|max:200',
            'email_contatto' => 'required|email|max:255',
            'telefono' => 'nullable|string|max:30',
            'indirizzo' => 'nullable|string',
            'descrizione' => 'nullable|string',
            'logo_url' => 'nullable|url|max:500',
            'partner_pubblico' => 'sometimes|boolean',
            'genera_invito' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['successo' => false, 'errori' => $validator->errors()], 422);
        }

        $email = $request->input('email_contatto');
        $generaInvito = $request->boolean('genera_invito', true);

        if (Fornitore::where('email_contatto', $email)->exists()) {
            return response()->json([
                'successo' => false,
                'errore' => 'Esiste già una scheda fornitore con questa email.',
            ], 422);
        }

        if ($generaInvito && Utente::where('email', $email)->exists()) {
            return response()->json([
                'successo' => false,
                'errore' => "Esiste già un account con questa email: utilizza la funzione Collega per associarlo a una scheda fornitore.",
            ], 422);
        }

        $fornitore = Fornitore::create([
            'nome_azienda' => $request->nome_azienda,
            'email_contatto' => $email,
            'telefono' => $request->input('telefono'),
            'indirizzo' => $request->input('indirizzo'),
            'descrizione' => $request->input('descrizione'),
            'logo_url' => $request->input('logo_url'),
            'partner_pubblico' => $request->boolean('partner_pubblico', false),
            'trust_score' => 0,
            'num_campagne' => 0,
        ]);

        $invito = null;
        if ($generaInvito) {
            $invito = InvitoFornitore::create([
                'id_fornitore' => $fornitore->id,
                'email' => $email,
                'token' => bin2hex(random_bytes(32)),
                'scadenza' => now()->addDays(7),
                'usato' => false,
            ]);
        }

        return response()->json([
            'successo' => true,
            'dati' => [
                'fornitore' => [
                    'id' => $fornitore->id,
                    'nome_azienda' => $fornitore->nome_azienda,
                    'email_contatto' => $fornitore->email_contatto,
                ],
                'invito' => $invito ? [
                    'id' => $invito->id,
                    'token' => $invito->token,
                    'email' => $invito->email,
                    'scadenza' => $invito->scadenza,
                ] : null,
            ],
        ], 201);
    }

    // ------------------------------------------------------------
    // Admin : génère une invitation pour une fiche fournisseur libre
    // ------------------------------------------------------------
    public function genera(Request $request, int $id)
    {
        $fornitore = Fornitore::find($id);

        if (!$fornitore) {
            return response()->json(['successo' => false, 'errore' => 'Fornitore non trovato.'], 404);
        }

        if ($fornitore->id_utente !== null) {
            return response()->json([
                'successo' => false,
                'errore' => 'Questa scheda fornitore è già collegata a un account.',
            ], 422);
        }

        if (blank($fornitore->email_contatto)) {
            return response()->json([
                'successo' => false,
                'errore' => 'Impossibile invitare il fornitore: nessuna email di contatto registrata.',
            ], 422);
        }

        // Invalide les invitations précédentes non encore utilisées
        InvitoFornitore::where('id_fornitore', $fornitore->id)
            ->where('usato', false)
            ->update(['usato' => true]);

        $invito = InvitoFornitore::create([
            'id_fornitore' => $fornitore->id,
            'email' => $fornitore->email_contatto,
            'token' => bin2hex(random_bytes(32)),
            'scadenza' => now()->addDays(7),
            'usato' => false,
        ]);

        return response()->json([
            'successo' => true,
            'dati' => [
                'id' => $invito->id,
                'token' => $invito->token,
                'email' => $invito->email,
                'scadenza' => $invito->scadenza,
            ],
        ], 201);
    }

    // ------------------------------------------------------------
    // Public : vérifie une invitation (données non sensibles)
    // ------------------------------------------------------------
    public function verifica(string $token)
    {
        $invito = InvitoFornitore::where('token', $token)->first();

        if (!$invito) {
            return response()->json(['successo' => false, 'errore' => 'Invito non valido.'], 404);
        }

        if ($invito->usato) {
            return response()->json(['successo' => false, 'errore' => 'Questo invito è già stato utilizzato.'], 422);
        }

        if ($invito->scadenza->isPast()) {
            return response()->json(['successo' => false, 'errore' => "Questo invito è scaduto. Contatta l'amministratore per un nuovo invito."], 410);
        }

        $fornitore = $invito->fornitore;

        if (!$fornitore) {
            return response()->json(['successo' => false, 'errore' => 'Invito non valido.'], 404);
        }

        return response()->json([
            'successo' => true,
            'dati' => [
                'nome_azienda' => $fornitore->nome_azienda,
                'email' => $invito->email,
            ],
        ]);
    }

    // ------------------------------------------------------------
    // Public : active l'invitation et crée le compte fornitore
    // ------------------------------------------------------------
    public function attiva(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required|string',
            'password' => 'required|string|min:8',
        ]);

        if ($validator->fails()) {
            return response()->json(['successo' => false, 'errori' => $validator->errors()], 422);
        }

        try {
            DB::transaction(function () use ($request) {
                $invito = InvitoFornitore::where('token', $request->token)->lockForUpdate()->first();

                if (!$invito) {
                    throw $this->errore('Invito non valido.', 404);
                }

                if ($invito->usato) {
                    throw $this->errore('Questo invito è già stato utilizzato.', 422);
                }

                if ($invito->scadenza->isPast()) {
                    throw $this->errore("Questo invito è scaduto. Contatta l'amministratore per un nuovo invito.", 410);
                }

                $fornitore = Fornitore::where('id', $invito->id_fornitore)->lockForUpdate()->first();

                if (!$fornitore) {
                    throw $this->errore('Invito non valido.', 404);
                }

                if ($fornitore->id_utente !== null) {
                    throw $this->errore('Questa scheda fornitore è già collegata a un account.', 422);
                }

                if (Utente::where('email', $invito->email)->exists()) {
                    throw $this->errore("Esiste già un account con l'email indicata.", 422);
                }

                $utente = Utente::create([
                    'nome' => $fornitore->nome_azienda ?: 'Fornitore',
                    'cognome' => '',
                    'email' => $invito->email,
                    'password_hash' => Hash::make($request->password),
                    'tipo' => 'b2b',
                    'ruolo' => 'fornitore',
                    'stato' => 'attivo',
                ]);

                // Lien conditionnel (protection anti-race) : ne lie que si
                // la fiche est toujours libre.
                $collegato = Fornitore::where('id', $fornitore->id)
                    ->whereNull('id_utente')
                    ->update(['id_utente' => $utente->id]);

                if ($collegato !== 1) {
                    throw $this->errore('La scheda fornitore è appena stata collegata a un altro account. Riprova.', 409);
                }

                $invito->update(['usato' => true]);
            });
        } catch (HttpResponseException $e) {
            return $e->getResponse();
        }

        return response()->json([
            'successo' => true,
            'dati' => ['messaggio' => 'Account attivato con successo. Ora puoi accedere con la tua email.'],
        ]);
    }

    // ------------------------------------------------------------
    // Admin : collega une fiche fornitore libre à un compte existant
    // ------------------------------------------------------------
    public function collega(Request $request, int $id)
    {
        $validator = Validator::make($request->all(), [
            'id_utente' => 'required|integer|exists:utenti,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['successo' => false, 'errori' => $validator->errors()], 422);
        }

        $fornitore = Fornitore::find($id);

        if (!$fornitore) {
            return response()->json(['successo' => false, 'errore' => 'Fornitore non trovato.'], 404);
        }

        if ($fornitore->id_utente !== null) {
            return response()->json([
                'successo' => false,
                'errore' => 'Questa scheda fornitore è già collegata a un account.',
            ], 422);
        }

        $utente = Utente::find($request->id_utente);

        if (!$utente) {
            return response()->json(['successo' => false, 'errore' => 'Utente non trovato.'], 404);
        }

        $giaCollegatoAltrove = Fornitore::where('id_utente', $utente->id)
            ->where('id', '!=', $fornitore->id)
            ->exists();

        if ($giaCollegatoAltrove) {
            return response()->json([
                'successo' => false,
                'errore' => 'Questo account è già collegato a un\'altra scheda fornitore.',
            ], 422);
        }

        try {
            DB::transaction(function () use ($fornitore, $utente) {
                $fornitoreAttuale = Fornitore::where('id', $fornitore->id)->lockForUpdate()->first();

                if ($fornitoreAttuale->id_utente !== null) {
                    throw $this->errore('Questa scheda fornitore è già collegata a un account.', 422);
                }

                $utenteAttuale = Utente::where('id', $utente->id)->lockForUpdate()->first();

                $collegatoAltrove = Fornitore::where('id_utente', $utenteAttuale->id)
                    ->where('id', '!=', $fornitoreAttuale->id)
                    ->exists();

                if ($collegatoAltrove) {
                    throw $this->errore('Questo account è già collegato a un\'altra scheda fornitore.', 422);
                }

                $fornitoreAttuale->update(['id_utente' => $utenteAttuale->id]);

                if ($utenteAttuale->ruolo !== 'admin') {
                    $utenteAttuale->update(['ruolo' => 'fornitore']);
                }
            });
        } catch (HttpResponseException $e) {
            return $e->getResponse();
        }

        return response()->json([
            'successo' => true,
            'dati' => ['messaggio' => 'Scheda fornitore collegata all\'account con successo.'],
        ]);
    }

    private function errore(string $messaggio, int $stato): HttpResponseException
    {
        return new HttpResponseException(
            response()->json(['successo' => false, 'errore' => $messaggio], $stato),
        );
    }
}