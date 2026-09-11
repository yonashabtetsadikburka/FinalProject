<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qr_codes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_prenotazione');
            $table->string('token', 64)->unique();
            $table->integer('quantita_assegnata');
            $table->enum('stato', ['generato', 'scansionato', 'annullato'])->default('generato');
            $table->timestamp('data_generazione')->useCurrent();
            $table->timestamp('data_scansione')->nullable();

            $table->foreign('id_prenotazione')->references('id')->on('prenotazioni')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qr_codes');
    }
};
