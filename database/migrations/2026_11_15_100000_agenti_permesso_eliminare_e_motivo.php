<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agenti AI (Web Pro Italia, 15/11/2026): due aggiunte.
 *
 * 1. `agenti_chiavi.puo_eliminare`: il permesso, spento di serie, con cui l'amministratore lascia che un agente
 *    elimini un condominio con tutto il suo contenuto (serve a chi carica i condomìni: un caricamento sbagliato si
 *    butta e si rifà, senza cancellare unità e persone una per una). Si accende e si spegne in Impostazioni →
 *    Agenti AI, che agli agenti è vietata: una chiave non può darselo da sola.
 *
 * 2. `agenti_azioni.motivo` e `agenti_azioni.campi`: quando il gestionale rifiuta una richiesta, il registro
 *    conserva la frase con cui l'ha rifiutata e i NOMI dei campi sbagliati (mai i valori inviati). Prima si leggeva
 *    solo «rifiutata», e per capire perché bisognava chiederlo a chi stava lavorando.
 *
 * Guardie su ogni colonna: rieseguire `up()` dopo un'interruzione non fa niente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('agenti_chiavi') && ! Schema::hasColumn('agenti_chiavi', 'puo_eliminare')) {
            Schema::table('agenti_chiavi', function (Blueprint $table) {
                $table->boolean('puo_eliminare')->default(false)->after('sola_lettura');
            });
        }

        if (Schema::hasTable('agenti_azioni')) {
            if (! Schema::hasColumn('agenti_azioni', 'motivo')) {
                Schema::table('agenti_azioni', function (Blueprint $table) {
                    $table->string('motivo', 400)->nullable()->after('stato');
                });
            }

            if (! Schema::hasColumn('agenti_azioni', 'campi')) {
                Schema::table('agenti_azioni', function (Blueprint $table) {
                    $table->string('campi', 400)->nullable()->after('motivo');
                });
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('agenti_chiavi', 'puo_eliminare')) {
            Schema::table('agenti_chiavi', fn (Blueprint $table) => $table->dropColumn('puo_eliminare'));
        }

        foreach (['motivo', 'campi'] as $colonna) {
            if (Schema::hasColumn('agenti_azioni', $colonna)) {
                Schema::table('agenti_azioni', fn (Blueprint $table) => $table->dropColumn($colonna));
            }
        }
    }
};
