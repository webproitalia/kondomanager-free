<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * L'avviso di convocazione dell'assemblea (Web Pro Italia, 17/11/2026).
 *
 * - `verbale_modelli.tipo`: i modelli dello studio non sono più solo quelli del verbale. `verbale` (quelli che c'erano,
 *   ed è il valore di serie: le righe già scritte restano modelli di verbale), `convocazione` (l'avviso di convocazione),
 *   `email_convocazione` (il testo breve dell'email che porta l'avviso) e `trasmissione_verbale` (il testo dell'email e
 *   della lettera con cui si manda il verbale approvato). Un predefinito per tipo.
 * - `assemblee.convocazione_testo`: il testo dell'avviso, generato da un modello e poi libero, come il testo del verbale;
 *   `assemblee.convocazione_modello_id`: da quale modello è stato generato l'ultima volta. Senza chiave esterna, di
 *   proposito: è solo il nome da mostrare («generato da …»), e un modello cancellato dopo non deve fermare niente.
 * - `assemblee.collegamento_videoconferenza`: l'indirizzo della riunione a distanza, se c'è.
 *
 * Gli invii (a chi, quando, come) non hanno una tabella nuova: stanno nel registro degli invii della 1.12
 * (`registro_invii`), con l'assemblea come documento e i tipi `convocazione_assemblea` e `verbale_assemblea`.
 *
 * Rieseguibile: ogni colonna è uno statement con la sua guardia. Nel dataset di `UpgradeMigrationsRerunTest`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('verbale_modelli') && ! Schema::hasColumn('verbale_modelli', 'tipo')) {
            Schema::table('verbale_modelli', function (Blueprint $table) {
                $table->string('tipo', 30)->default('verbale')->after('id');
            });
        }

        if (! Schema::hasTable('assemblee')) {
            return;
        }

        if (! Schema::hasColumn('assemblee', 'convocazione_testo')) {
            Schema::table('assemblee', function (Blueprint $table) {
                $table->longText('convocazione_testo')->nullable();
            });
        }

        if (! Schema::hasColumn('assemblee', 'convocazione_modello_id')) {
            Schema::table('assemblee', function (Blueprint $table) {
                $table->unsignedBigInteger('convocazione_modello_id')->nullable();
            });
        }

        if (! Schema::hasColumn('assemblee', 'collegamento_videoconferenza')) {
            Schema::table('assemblee', function (Blueprint $table) {
                $table->string('collegamento_videoconferenza', 500)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['convocazione_testo', 'convocazione_modello_id', 'collegamento_videoconferenza'] as $colonna) {
            if (Schema::hasTable('assemblee') && Schema::hasColumn('assemblee', $colonna)) {
                Schema::table('assemblee', fn (Blueprint $table) => $table->dropColumn($colonna));
            }
        }
        if (Schema::hasTable('verbale_modelli') && Schema::hasColumn('verbale_modelli', 'tipo')) {
            Schema::table('verbale_modelli', fn (Blueprint $table) => $table->dropColumn('tipo'));
        }
    }
};
