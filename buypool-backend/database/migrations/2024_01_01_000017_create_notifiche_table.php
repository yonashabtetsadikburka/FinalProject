<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifiche', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_utente');
            $table->enum('tipo', [
                'SCADENZA', 'ORDINE_DISPONIBILE', 'ORDINE_INVIATO', 'RITIRATO',
                'PROPOSTA_APPROVATA', 'PAGAMENTO_RIUSCITO', 'PAGAMENTO_FALLITO',
                'RIMBORSO', 'MOQ_RAGGIUNTO', 'ORDINE_CONFERMATO', 'MERCE_PRONTA',
                'NUOVO_SCAGLIONE', 'TRACCIAMENTO', 'SISTEMA',
            ]);
            $table->string('titolo', 150);
            $table->text('messaggio');
            $table->enum('tipo_riferimento', ['colletta', 'prenotazione', 'proposta'])->nullable();
            $table->unsignedBigInteger('id_riferimento')->nullable();
            $table->boolean('letta')->default(false);
            $table->timestamp('data_creazione')->useCurrent();
            $table->timestamp('data_lettura')->nullable();

            $table->foreign('id_utente')->references('id')->on('utenti')->onDelete('cascade');

            $table->index(['id_utente', 'letta']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifiche');
    }
};
