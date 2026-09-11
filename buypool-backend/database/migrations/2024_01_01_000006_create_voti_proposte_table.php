<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voti_proposte', function (Blueprint $table) {
            $table->id('id_voto');
            $table->unsignedBigInteger('id_proposta');
            $table->unsignedBigInteger('id_utente');
            $table->enum('valore_voto', ['favore', 'contrario']);
            $table->timestamp('data_voto')->useCurrent();

            $table->foreign('id_proposta')->references('id_proposta')->on('proposte_prodotti')->onDelete('cascade');
            $table->foreign('id_utente')->references('id')->on('utenti')->onDelete('cascade');

            $table->unique(['id_proposta', 'id_utente'], 'uniq_voto_per_utente');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voti_proposte');
    }
};
