<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prodotti', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_fornitore');
            $table->unsignedBigInteger('id_categoria')->nullable();
            $table->unsignedBigInteger('id_proposta_origine')->nullable();
            $table->string('nome', 200);
            $table->text('descrizione')->nullable();
            $table->decimal('prezzo_unitario', 10, 2);
            $table->integer('quantita_minima')->default(1);
            $table->enum('stato', ['attivo', 'archiviato'])->default('attivo');
            $table->timestamp('data_creazione')->useCurrent();

            $table->foreign('id_fornitore')->references('id')->on('utenti')->onDelete('cascade');
            $table->foreign('id_categoria')->references('id')->on('categorie')->onDelete('set null');
            $table->foreign('id_proposta_origine')->references('id_proposta')->on('proposte_prodotti')->onDelete('set null');

            $table->index('id_fornitore');
            $table->index('id_categoria');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prodotti');
    }
};
