<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fornitori', function (Blueprint $table) {
            // Chiave primaria = chiave esterna verso utenti (relazione 1:1)
            $table->unsignedBigInteger('id_utente')->primary();
            $table->string('nome_azienda', 200);
            $table->string('email_contatto', 255)->nullable();
            $table->string('telefono', 30)->nullable();
            $table->text('indirizzo')->nullable();
            $table->text('descrizione')->nullable();
            $table->string('logo_url', 500)->nullable();
            $table->boolean('partner_pubblico')->default(true);
            $table->integer('trust_score')->default(0);
            $table->integer('num_campagne')->default(0);
            $table->timestamp('data_partnership')->useCurrent();

            $table->foreign('id_utente')->references('id')->on('utenti')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fornitori');
    }
};
