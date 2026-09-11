<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('utenti', function (Blueprint $table) {
            $table->id();
            $table->string('nome', 100);
            $table->string('cognome', 100)->default('');
            $table->string('email', 255)->unique();
            $table->string('password_hash', 255)->nullable();
            $table->string('telefono', 30)->default('');
            $table->text('indirizzo')->nullable();
            $table->enum('tipo', ['privato', 'b2b'])->default('privato');
            $table->enum('ruolo', ['utente', 'fornitore', 'admin'])->default('utente');
            $table->enum('stato', ['attivo', 'sospeso'])->default('attivo');
            $table->string('google_id', 255)->unique()->nullable();
            $table->string('microsoft_id', 255)->unique()->nullable();
            $table->string('stripe_customer_id', 100)->unique()->nullable();
            $table->string('partita_iva', 20)->nullable();
            $table->timestamp('data_iscrizione')->useCurrent();

            $table->index('ruolo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('utenti');
    }
};
