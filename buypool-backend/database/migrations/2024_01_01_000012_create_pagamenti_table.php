<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pagamenti', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_prenotazione');
            $table->enum('tipo_pagamento', ['acconto', 'saldo', 'consegna']);
            $table->decimal('importo', 10, 2);
            $table->char('valuta', 3)->default('EUR');
            $table->decimal('commissione_agenzia', 10, 2)->default(0.00);
            $table->string('stripe_payment_intent_id', 100)->unique()->nullable();
            $table->string('stripe_charge_id', 100)->nullable();
            $table->string('stripe_payment_method', 50)->nullable();
            $table->string('stato_stripe', 50)->nullable();
            $table->enum('stato', ['in_attesa', 'confermato', 'fallito', 'rimborsato'])->default('in_attesa');
            $table->timestamp('data_pagamento')->useCurrent();
            $table->timestamp('data_conferma')->nullable();

            $table->foreign('id_prenotazione')->references('id')->on('prenotazioni')->onDelete('cascade');

            $table->index('stripe_payment_intent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pagamenti');
    }
};
