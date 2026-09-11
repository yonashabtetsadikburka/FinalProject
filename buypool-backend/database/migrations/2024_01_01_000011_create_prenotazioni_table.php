<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prenotazioni', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_colletta');
            $table->unsignedBigInteger('id_utente');
            $table->integer('quantita')->default(1);
            $table->decimal('importo_acconto', 10, 2)->default(0);
            $table->decimal('importo_saldo', 10, 2)->nullable();
            $table->decimal('importo_commissione', 10, 2)->default(0);
            $table->enum('stato', ['prenotata', 'confermata', 'annullata', 'rimborsata'])->default('prenotata');
            $table->timestamp('data_prenotazione')->useCurrent();
            $table->timestamp('data_pagamento')->nullable();

            $table->foreign('id_colletta')->references('id')->on('collette')->onDelete('cascade');
            $table->foreign('id_utente')->references('id')->on('utenti')->onDelete('cascade');

            $table->unique(['id_colletta', 'id_utente'], 'uniq_pren_utente_colletta');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prenotazioni');
    }
};
