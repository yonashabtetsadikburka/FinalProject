<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inviti_fornitore', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_fornitore');
            $table->string('email', 255);
            $table->string('token', 64)->unique();
            $table->dateTime('scadenza');
            $table->boolean('usato')->default(false);
            $table->timestamps();

            $table->foreign('id_fornitore')->references('id')->on('fornitori')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inviti_fornitore');
    }
};