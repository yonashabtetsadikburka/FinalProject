<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collette', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_prodotto');
            $table->unsignedBigInteger('id_aperta_da')->nullable();
            $table->unsignedBigInteger('id_referente')->nullable();
            $table->unsignedBigInteger('id_sede')->nullable();
            $table->integer('quantita_minima');
            $table->integer('quantita_attuale')->default(0);
            $table->timestamp('data_inizio')->useCurrent();
            $table->dateTime('data_limite');
            $table->enum('stato', [
                'in_corso', 'riuscita', 'fallita',
                'ordine_fornitore', 'consegnata', 'annullata',
            ])->default('in_corso');
            $table->timestamp('data_agg_stato')->nullable();
            $table->enum('regola_arrotondamento', ['difetto', 'eccesso'])->default('difetto');
            $table->decimal('prezzo_base', 10, 2);
            $table->decimal('prezzo_corrente', 10, 2);
            $table->decimal('percentuale_commissione', 5, 2)->default(10.00);
            $table->unsignedBigInteger('id_admin_conferma')->nullable();
            $table->timestamp('data_conferma')->nullable();

            $table->foreign('id_prodotto')->references('id')->on('prodotti')->onDelete('cascade');
            $table->foreign('id_aperta_da')->references('id')->on('utenti')->onDelete('set null');
            $table->foreign('id_referente')->references('id')->on('utenti')->onDelete('set null');
            $table->foreign('id_sede')->references('id_sede')->on('sedi')->onDelete('set null');
            $table->foreign('id_admin_conferma')->references('id')->on('utenti')->onDelete('set null');

            $table->index(['stato', 'data_limite']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collette');
    }
};
