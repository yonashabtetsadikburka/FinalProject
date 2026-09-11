<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('immagini_prodotto', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_prodotto');
            $table->string('url', 500);
            $table->integer('ordine')->default(0);
            $table->boolean('principale')->default(false);

            $table->foreign('id_prodotto')->references('id')->on('prodotti')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('immagini_prodotto');
    }
};
