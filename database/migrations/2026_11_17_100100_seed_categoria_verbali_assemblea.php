<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La categoria dei documenti «Verbali di assemblea» (Web Pro Italia, 17/11/2026): ci finisce il PDF del verbale quando
 * l'assemblea lo approva.
 *
 * Come le altre categorie seminate (`2026_08_31_120000_seed_categorie_documento`): una migrazione e non un seeder, così
 * una categoria che l'amministratore cancella non risorge. Il codice la ritrova con la chiave stabile
 * `verbali-assemblea` (`CategoriaDocumento::perVerbaliAssemblea()`), non con l'etichetta, che resta libera.
 *
 * Accanto c'è già «Verbali» (verbali delle assemblee e delle riunioni, caricati a mano): non si tocca. Questa è la
 * categoria dei verbali che il programma scrive da sé.
 *
 * Idempotente: una guardia sulla chiave e una sul nome.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('categorie_documento') || ! Schema::hasColumn('categorie_documento', 'slug')) {
            return;
        }

        if (DB::table('categorie_documento')->where('slug', 'verbali-assemblea')->exists()) {
            return;
        }

        // Un'installazione che l'ha già creata a mano con questo nome: le si dà la chiave, senza un doppione.
        $esistente = DB::table('categorie_documento')->where('name', 'Verbali di assemblea')->whereNull('slug')->value('id');
        if ($esistente !== null) {
            DB::table('categorie_documento')->where('id', $esistente)->update(['slug' => 'verbali-assemblea']);

            return;
        }

        DB::table('categorie_documento')->insert([
            'name'        => 'Verbali di assemblea',
            'slug'        => 'verbali-assemblea',
            'description' => 'I verbali delle assemblee approvati nel gestionale',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    /** Come per le altre categorie seminate: il `down()` non cancella dati che da qui in poi sono dell'amministratore. */
    public function down(): void
    {
        // Volutamente vuoto.
    }
};
