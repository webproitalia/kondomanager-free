<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LA RATA DEI SALDI SCADE QUANDO DICI TU (Web Pro Italia, 13/11/2026).
 *
 * Con `metodo_distribuzione = rata_zero` i saldi pregressi stanno in una rata a parte (la rata 0), che fino a oggi
 * scadeva per forza lo stesso giorno della prima rata (`GenerateRateQuotesAction`: `$dateRate[0]`). Un'amministratrice
 * ha chiesto di poterle dare una data sua: prima rata il 15/01/2027, saldi il 15/12/2026.
 *
 * ## Perché nullable, e perché nessun backfill
 *
 * Come per `data_prima_scadenza`: `NULL` non è un dato mancante, significa **«scade insieme alla prima rata»**, cioè
 * il comportamento di sempre. Riempire la colonna sui piani esistenti li congelerebbe su una data calcolata adesso, e
 * un piano la cui prima rata venisse spostata smetterebbe di portarsi dietro la rata dei saldi. Chi non tocca niente
 * non si accorge di questa colonna.
 *
 * Una colonna sola, senza chiave esterna: la guardia è una, e rieseguire `up()` dopo un'interruzione non fa niente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('piani_rate', 'data_rata_zero')) {
            Schema::table('piani_rate', function (Blueprint $table) {
                $table->date('data_rata_zero')
                    ->nullable()
                    ->after('data_prima_scadenza');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('piani_rate', 'data_rata_zero')) {
            Schema::table('piani_rate', function (Blueprint $table) {
                $table->dropColumn('data_rata_zero');
            });
        }
    }
};
