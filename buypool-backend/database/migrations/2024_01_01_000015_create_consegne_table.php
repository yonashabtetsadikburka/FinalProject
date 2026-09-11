<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consegne', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_prenotazione')->unique();
            $table->enum('modalita', ['ritiro_sede', 'consegna_domicilio']);
            $table->unsignedBigInteger('id_sede')->nullable();
            $table->string('indirizzo_consegna', 255)->nullable();
            $table->decimal('importo_consegna', 10, 2)->default(0.00);
            $table->enum('stato', ['in_attesa', 'pronta', 'spedita', 'consegnata', 'ritirata'])->default('in_attesa');
            $table->dateTime('data_prevista')->nullable();
            $table->dateTime('data_effettiva')->nullable();

            $table->foreign('id_prenotazione')->references('id')->on('prenotazioni')->onDelete('cascade');
            $table->foreign('id_sede')->references('id_sede')->on('sedi')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consegne');
    }
};
