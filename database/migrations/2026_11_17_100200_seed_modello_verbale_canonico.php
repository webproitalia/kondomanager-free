<?php

use App\Services\Assemblee\ModelloCanonico;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Il modello di verbale di serie (Web Pro Italia, 17/11/2026): un verbale di assemblea condominiale con le frasi di
 * rito, marcato predefinito, finché lo studio non mette il suo.
 *
 * Una migrazione e non un seeder, come per le categorie dei documenti: un modello che lo studio cancella non risorge.
 * Idempotente: si scrive solo se la tabella non ha ancora nessun modello.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('verbale_modelli') || DB::table('verbale_modelli')->exists()) {
            return;
        }

        DB::table('verbale_modelli')->insert([
            'nome'        => ModelloCanonico::NOME,
            'descrizione' => ModelloCanonico::DESCRIZIONE,
            'corpo'       => ModelloCanonico::CORPO,
            'predefinito' => true,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    /** I modelli da qui in poi sono dello studio: il `down()` non li tocca. */
    public function down(): void
    {
        // Volutamente vuoto.
    }
};
