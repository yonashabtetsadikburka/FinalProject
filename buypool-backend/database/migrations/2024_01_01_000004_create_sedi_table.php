<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sedi', function (Blueprint $table) {
            $table->id('id_sede');
            $table->string('nome', 200);
            $table->string('indirizzo', 255);
            $table->string('citta', 100);
            $table->string('telefono', 30)->nullable();
            $table->string('orari', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sedi');
    }
};
