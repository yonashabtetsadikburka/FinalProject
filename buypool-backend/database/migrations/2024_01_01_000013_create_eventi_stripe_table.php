<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eventi_stripe', function (Blueprint $table) {
            $table->id();
            $table->string('stripe_event_id', 100)->unique();
            $table->string('tipo_evento', 100);
            $table->unsignedBigInteger('id_pagamento')->nullable();
            $table->json('payload_json')->nullable();
            $table->boolean('elaborato')->default(false);
            $table->timestamp('data_ricezione')->useCurrent();

            $table->foreign('id_pagamento')->references('id')->on('pagamenti')->onDelete('set null');

            $table->index('tipo_evento');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eventi_stripe');
    }
};
