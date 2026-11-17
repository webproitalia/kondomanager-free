<?php

use App\Models\Assemblee\VerbaleModello;
use App\Services\Assemblee\ModelliDiSerie;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * I modelli di serie della convocazione (Web Pro Italia, 17/11/2026): l'avviso di convocazione con il modulo di delega,
 * il testo dell'email che lo porta e quello con cui si manda il verbale approvato. Ognuno è il predefinito del suo
 * tipo, finché lo studio non mette il suo.
 *
 * Una migrazione e non un seeder, come il modello di verbale di serie: un modello che lo studio cancella non risorge.
 * Idempotente: ogni tipo si scrive solo se la tabella non ha ancora nessun modello di quel tipo.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('verbale_modelli') || ! Schema::hasColumn('verbale_modelli', 'tipo')) {
            return;
        }

        foreach ([VerbaleModello::CONVOCAZIONE, VerbaleModello::EMAIL_CONVOCAZIONE, VerbaleModello::TRASMISSIONE_VERBALE] as $tipo) {
            if (DB::table('verbale_modelli')->where('tipo', $tipo)->exists()) {
                continue;
            }
            $modello = ModelliDiSerie::perTipo($tipo);

            DB::table('verbale_modelli')->insert([
                'tipo'        => $tipo,
                'nome'        => $modello['nome'],
                'descrizione' => $modello['descrizione'],
                'corpo'       => $modello['corpo'],
                'predefinito' => true,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }
    }

    /** I modelli da qui in poi sono dello studio: il `down()` non li tocca. */
    public function down(): void
    {
        // Volutamente vuoto.
    }
};
