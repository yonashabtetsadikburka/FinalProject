<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scaglioni_prezzo', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_colletta');
            $table->integer('soglia_partecipanti');
            $table->decimal('prezzo_unitario', 10, 2);

            $table->foreign('id_colletta')->references('id')->on('collette')->onDelete('cascade');
            $table->unique(['id_colletta', 'soglia_partecipanti'], 'uniq_scaglione');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scaglioni_prezzo');
    }
};
