<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ordini_fornitore', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_colletta')->unique();
            $table->unsignedBigInteger('id_fornitore');
            $table->unsignedBigInteger('id_admin')->nullable();
            $table->integer('quantita_ordinata');
            $table->decimal('prezzo_negoziato', 10, 2)->nullable();
            $table->decimal('importo_totale', 10, 2);
            $table->enum('stato', ['da_negoziare', 'inviato', 'consegnato', 'annullato'])->default('da_negoziare');
            $table->dateTime('data_ordine')->nullable();
            $table->dateTime('data_consegna')->nullable();

            $table->foreign('id_colletta')->references('id')->on('collette')->onDelete('cascade');
            $table->foreign('id_fornitore')->references('id')->on('utenti')->onDelete('cascade');
            $table->foreign('id_admin')->references('id')->on('utenti')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ordini_fornitore');
    }
};
