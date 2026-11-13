<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Il fascicolo di bilancio di un esercizio (Web Pro Italia, 13/11/2026): la nota sintetica esplicativa scritta dallo
 * studio e le scelte su che cosa stampare. I numeri non stanno qui: il fascicolo li legge ogni volta dai registri.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fascicoli_bilancio', function (Blueprint $table) {
            $table->id();
            $table->foreignId('esercizio_id')->unique()->constrained('esercizi')->cascadeOnDelete();
            $table->string('tipo', 20)->nullable();          // consuntivo | preventivo | null = lo decide il gestionale
            $table->string('titolo', 150)->nullable();
            $table->string('sottotitolo', 250)->nullable();
            $table->longText('nota')->nullable();
            $table->json('sezioni')->nullable();              // null = le sezioni di serie per quel tipo
            $table->foreignId('aggiornato_da')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fascicoli_bilancio');
    }
};
