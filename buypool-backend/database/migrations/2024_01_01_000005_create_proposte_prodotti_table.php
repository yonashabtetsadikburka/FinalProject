<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proposte_prodotti', function (Blueprint $table) {
            $table->id('id_proposta');
            $table->enum('proponente_tipo', ['utente', 'admin'])->default('utente');
            $table->unsignedBigInteger('proponente_id');
            $table->string('nome_prodotto', 200);
            $table->text('descrizione')->nullable();
            $table->unsignedBigInteger('id_fornitore_suggerito')->nullable();
            $table->enum('stato', [
                'in_attesa', 'in_votazione', 'approvata_admin',
                'rifiutata', 'pubblicata', 'respinta_votazione',
            ])->default('in_attesa');
            $table->unsignedBigInteger('id_admin_gestione')->nullable();
            $table->integer('tot_voti')->default(0);
            $table->timestamp('data_proposta')->useCurrent();
            $table->timestamp('data_gestione')->nullable();

            $table->foreign('proponente_id')->references('id')->on('utenti')->onDelete('cascade');
            $table->foreign('id_fornitore_suggerito')->references('id')->on('utenti')->onDelete('set null');
            $table->foreign('id_admin_gestione')->references('id')->on('utenti')->onDelete('set null');

            $table->index('stato');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proposte_prodotti');
    }
};
