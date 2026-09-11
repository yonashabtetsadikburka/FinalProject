<?php

namespace App\Http\Controllers;

use App\Models\Prenotazione;
use App\Models\Pagamento;
use App\Models\EventoStripe;
use App\Models\QrCode;
use App\Models\Notifica;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Stripe\Checkout\Session as StripeSession;
use Stripe\PaymentIntent;
use Tymon\JWTAuth\Facades\JWTAuth;

class PagamentoController extends Controller
{
    private function stripe()
    {
        return new \Stripe\StripeClient(config('services.stripe.secret'));
    }

    public function checkout(Request $request)
    {
        $utente = JWTAuth::parseToken()->authenticate();

        $request->validate([
            'prenotazione_id' => 'required|integer',
        ]);

        $prenotazione = Prenotazione::with('colletta.prodotto')
            ->where('id', $request->prenotazione_id)
            ->where('id_utente', $utente->id)
            ->first();

        if (!$prenotazione) {
            return response()->json(['successo' => false, 'errore' => 'Prenotazione non trovata.'], 404);
        }

        if ($prenotazione->stato !== 'confermata') {
            return response()->json(['successo' => false, 'errore' => 'La prenotazione deve essere confermata per procedere al pagamento.'], 422);
        }

        $importoSaldo = $prenotazione->importo_saldo ?? round($prenotazione->quantita * $prenotazione->colletta->prezzo_corrente, 2);

        try {
            $session = StripeSession::create(
                [
                    'mode' => 'payment',
                    'payment_method_types' => ['card'],
                    'line_items' => [[
                        'price_data' => [
                            'currency' => 'eur',
                            'product_data' => [
                                'name' => $prenotazione->colletta->prodotto->nome,
                            ],
                            'unit_amount' => (int) round($importoSaldo * 100),
                        ],
                        'quantity' => $prenotazione->quantita,
                    ]],
                    'customer_email' => $utente->email,
                    'client_reference_id' => (string) $prenotazione->id,
                    'metadata' => [
                        'prenotazione_id' => (string) $prenotazione->id,
                        'id_utente' => (string) $utente->id,
                    ],
                    'success_url' => config('app.frontend_url') . '/app.html#/pagamento/successo?session_id={CHECKOUT_SESSION_ID}',
                    'cancel_url' => config('app.frontend_url') . '/app.html#/partecipazioni',
                ],
                ['idempotency_key' => 'checkout_' . $prenotazione->id . '_' . Str::random(8)]
            );

            return response()->json(['successo' => true, 'dati' => ['url' => $session->url]]);
        } catch (\Exception $e) {
            return response()->json(['successo' => false, 'errore' => 'Errore nella creazione della sessione di pagamento.'], 500);
        }
    }

    public function stato(Request $request)
    {
        $request->validate(['session_id' => 'required|string']);

        try {
            $session = StripeSession::retrieve($request->session_id);

            $prenotazione = Prenotazione::with('colletta.prodotto.fornitore')
                ->find($session->client_reference_id);

            if (!$prenotazione) {
                return response()->json(['successo' => false, 'errore' => 'Prenotazione non trovata.'], 404);
            }

            return response()->json([
                'successo' => true,
                'dati' => [
                    'stato' => $session->payment_status === 'paid' ? 'pagato' : 'in_attesa',
                    'pagato' => $session->payment_status === 'paid',
                    'session_id' => $session->id,
                    'prenotazione' => $prenotazione,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['successo' => false, 'errore' => 'Sessione non valida.'], 500);
        }
    }

    public function webhook(Request $request)
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $endpointSecret = config('services.stripe.webhook_secret');

        try {
            $event = \Stripe\Webhook::constructEvent(
                $payload,
                $sigHeader,
                $endpointSecret
            );
        } catch (\Exception $e) {
            return response()->json(['successo' => false, 'errore' => 'Firma non valida.'], 400);
        }

        $eventId = $event->id;

        // Idempotence obligatoire : on ignore les événements déjà traités
        $dejaTraite = EventoStripe::where('stripe_event_id', $eventId)->exists();
        if ($dejaTraite) {
            return response()->json(['successo' => true, 'dati' => ['idempotent' => true]]);
        }

        DB::beginTransaction();
        try {
            $evento = EventoStripe::create([
                'stripe_event_id' => $eventId,
                'tipo_evento' => $event->type,
                'payload_json' => $event->toArray(),
                'elaborato' => true,
            ]);

            switch ($event->type) {
                case 'payment_intent.succeeded':
                    $this->handlePaymentIntentSuccess($event->data->object);
                    break;
                case 'checkout.session.completed':
                    $this->handleCheckoutCompleted($event->data->object);
                    break;
                default:
                    break;
            }

            DB::commit();

            return response()->json(['successo' => true, 'dati' => ['ricevuto' => true]]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['successo' => false, 'errore' => 'Errore durante l\'elaborazione.'], 500);
        }
    }

    private function handleCheckoutCompleted($session)
    {
        $prenotazioneId = $session->metadata['prenotazione_id'] ?? $session->client_reference_id;

        if (!$prenotazioneId) {
            return;
        }

        $prenotazione = Prenotazione::find($prenotazioneId);
        if (!$prenotazione) {
            return;
        }

        if ($session->payment_status !== 'paid') {
            return;
        }

        $importo = $session->amount_total / 100;

        Pagamento::create([
            'id_prenotazione' => $prenotazione->id,
            'tipo_pagamento' => 'saldo',
            'importo' => $importo,
            'valuta' => 'EUR',
            'stripe_payment_intent_id' => $session->payment_intent,
            'stripe_payment_method' => $session->payment_method_types[0] ?? 'card',
            'stato_stripe' => 'paid',
            'stato' => 'confermato',
            'data_conferma' => now(),
        ]);

        $prenotazione->update(['stato' => 'confermata', 'data_pagamento' => now()]);

        Notifica::create([
            'id_utente' => $prenotazione->id_utente,
            'tipo' => 'PAGAMENTO_RIUSCITO',
            'titolo' => 'Pagamento Ricevuto',
            'messaggio' => 'Il tuo pagamento di ' . number_format($importo, 2, ',', ' ') . ' € è stato ricevuto con successo.',
            'tipo_riferimento' => 'prenotazione',
            'id_riferimento' => $prenotazione->id,
        ]);
    }

    private function handlePaymentIntentSuccess($paymentIntent)
    {
        // Capturé via la session checkout ; les données sont persistées dans checkout.session.completed
    }
}
