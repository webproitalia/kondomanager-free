<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Collegamenti con sistemi esterni (Web Pro Italia, 10/11/2026): un'app o un programma fuori dal gestionale (per
 * esempio l'app dei condòmini «Portineria») tiene allineate le sue segnalazioni con quelle di KondoManager.
 *
 * Ogni riga lega un oggetto del sistema esterno (sorgente + riferimento) a un oggetto del gestionale (una
 * segnalazione, un commento). Serve a non creare doppioni quando lo stesso oggetto arriva due volte, e a sapere a
 * chi mandare l'avviso quando l'oggetto cambia qui. `eliminato_at` ricorda che l'oggetto è stato cancellato nel
 * gestionale: il sistema esterno non lo ricrea.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('collegamenti_esterni')) {
            Schema::create('collegamenti_esterni', function (Blueprint $table) {
                $table->id();
                $table->string('sorgente', 50);
                $table->string('riferimento', 100);
                $table->string('collegabile_type', 100);
                $table->unsignedBigInteger('collegabile_id')->nullable();
                $table->json('dati')->nullable();
                $table->timestamp('eliminato_at')->nullable();
                $table->timestamps();

                $table->unique(['sorgente', 'collegabile_type', 'riferimento'], 'collegamenti_esterni_unico');
                $table->index(['collegabile_type', 'collegabile_id'], 'collegamenti_esterni_oggetto');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('collegamenti_esterni');
    }
};
