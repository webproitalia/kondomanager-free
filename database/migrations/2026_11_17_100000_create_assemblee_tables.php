<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le assemblee del condominio (Web Pro Italia, 17/11/2026): l'assemblea con le sue date e la tabella dei conteggi,
 * l'ordine del giorno, le presenze con la fotografia dei millesimi, i voti punto per punto e il verbale.
 *
 * ⚠️ **I nomi delle tabelle e delle colonne li legge anche la registrazione dell'assemblea**, costruita in parallelo:
 * non si rinominano senza dirlo di là.
 *
 * Il verbale parte da un MODELLO dello studio (`verbale_modelli`: un testo con i segnaposto, uno predefinito), da cui
 * il programma genera il testo; il testo poi è libero (`verbali.testo`) e si stampa così com'è. `verbali.modello_id`
 * dice da quale modello è stato generato l'ultima volta.
 *
 * Due colonne in più rispetto al progetto, tutte e due facoltative: `assemblee.data_avviso` (il giorno dell'avviso di
 * convocazione, che l'intestazione del verbale nomina se c'è) e `assemblee.millesimi_totali` (il totale della tabella
 * quando si è preparato l'elenco degli aventi diritto: i conteggi lo usano come «valore dell'edificio», così una
 * tabella corretta dopo l'assemblea non cambia i numeri di un verbale già scritto).
 *
 * Rieseguibile: un aggiornamento interrotto a metà ripassa di qui, e ogni tabella che c'è già si salta. Le chiavi
 * esterne verso le tabelle che il condominio porta via a cascata (tabelle millesimali, esercizi, documenti) sono
 * SET NULL: l'eliminazione di un condominio con tutto il contenuto (`EliminaCondominio`) toglie i documenti prima del
 * condominio, e un vincolo RESTRICT su `verbali.documento_id` la fermerebbe.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('assemblee')) {
            Schema::create('assemblee', function (Blueprint $table) {
                $table->id();
                $table->foreignId('condominio_id')->constrained('condomini')->cascadeOnDelete();
                $table->foreignId('esercizio_id')->nullable()->constrained('esercizi')->nullOnDelete();
                $table->string('tipo', 20)->default('ordinaria');                 // ordinaria | straordinaria
                $table->string('titolo', 200);
                $table->dateTime('data_prima_convocazione')->nullable();
                $table->dateTime('data_seconda_convocazione')->nullable();
                $table->string('convocazione_tenuta', 10)->nullable();            // prima | seconda
                $table->date('data_avviso')->nullable();
                $table->string('luogo', 255)->nullable();
                $table->string('modalita', 20)->default('presenza');              // presenza | videoconferenza | mista
                $table->foreignId('tabella_id')->nullable()->constrained('tabelle')->nullOnDelete();
                $table->decimal('millesimi_totali', 12, 5)->nullable();
                $table->string('presidente', 150)->nullable();
                $table->string('segretario', 150)->nullable();
                $table->time('ora_inizio')->nullable();
                $table->time('ora_fine')->nullable();
                $table->string('stato', 20)->default('bozza');                    // bozza | convocata | in_corso | chiusa
                $table->boolean('registrata')->default(false);
                $table->text('note')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['condominio_id', 'stato'], 'assemblee_condominio_stato_index');
            });
        }

        if (! Schema::hasTable('assemblea_punti')) {
            Schema::create('assemblea_punti', function (Blueprint $table) {
                $table->id();
                $table->foreignId('assemblea_id')->constrained('assemblee')->cascadeOnDelete();
                $table->unsignedInteger('numero');
                $table->string('titolo', 500);
                $table->text('descrizione')->nullable();
                $table->string('maggioranza', 20)->default('ordinaria');
                $table->longText('discussione')->nullable();
                $table->longText('delibera')->nullable();
                $table->string('esito', 20)->nullable();                          // approvato | respinto | rinviato | informativa
                $table->timestamps();

                $table->index(['assemblea_id', 'numero'], 'assemblea_punti_assemblea_numero_index');
            });
        }

        if (! Schema::hasTable('assemblea_presenze')) {
            Schema::create('assemblea_presenze', function (Blueprint $table) {
                $table->id();
                $table->foreignId('assemblea_id')->constrained('assemblee')->cascadeOnDelete();
                $table->foreignId('anagrafica_id')->constrained('anagrafiche')->cascadeOnDelete();
                $table->string('tipo', 10)->default('assente');                   // presente | delega | assente
                $table->foreignId('delegato_anagrafica_id')->nullable()->constrained('anagrafiche')->nullOnDelete();
                $table->string('delegato_nome', 150)->nullable();
                $table->decimal('millesimi', 12, 5)->default(0);
                $table->json('unita')->nullable();
                $table->time('ora_arrivo')->nullable();
                $table->time('ora_uscita')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('assemblea_voti')) {
            Schema::create('assemblea_voti', function (Blueprint $table) {
                $table->id();
                $table->foreignId('assemblea_punto_id')->constrained('assemblea_punti')->cascadeOnDelete();
                $table->foreignId('assemblea_presenza_id')->constrained('assemblea_presenze')->cascadeOnDelete();
                $table->string('voto', 12);                                       // favorevole | contrario | astenuto
                $table->timestamps();

                $table->unique(['assemblea_punto_id', 'assemblea_presenza_id'], 'assemblea_voti_punto_presenza_unique');
            });
        }

        if (! Schema::hasTable('verbale_modelli')) {
            Schema::create('verbale_modelli', function (Blueprint $table) {
                $table->id();
                $table->string('nome', 150);
                $table->string('descrizione', 500)->nullable();
                $table->longText('corpo');
                $table->boolean('predefinito')->default(false);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('verbali')) {
            Schema::create('verbali', function (Blueprint $table) {
                $table->id();
                $table->foreignId('assemblea_id')->unique()->constrained('assemblee')->cascadeOnDelete();
                $table->string('stato', 20)->default('bozza');                    // bozza | da_approvare | approvato
                $table->longText('intestazione')->nullable();
                $table->longText('apertura')->nullable();
                $table->longText('chiusura')->nullable();
                $table->longText('testo')->nullable();
                $table->foreignId('modello_id')->nullable()->constrained('verbale_modelli')->nullOnDelete();
                $table->unsignedInteger('versione')->default(1);
                $table->foreignId('documento_id')->nullable()->constrained('documenti')->nullOnDelete();
                $table->timestamp('inviato_in_approvazione_at')->nullable();
                $table->timestamp('approvato_at')->nullable();
                $table->foreignId('approvato_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('verbali');
        Schema::dropIfExists('verbale_modelli');
        Schema::dropIfExists('assemblea_voti');
        Schema::dropIfExists('assemblea_presenze');
        Schema::dropIfExists('assemblea_punti');
        Schema::dropIfExists('assemblee');
    }
};
