<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Il legame fra una registrazione e la sua assemblea diventa una chiave esterna (Web Pro Italia, 17/11/2026).
 *
 * Le due tabelle sono nate in due lavori separati, e `registrazioni.assemblea_id` era un numero senza vincolo. Eliminando un'assemblea la
 * registrazione resta (è una riunione registrata, con la sua trascrizione) e perde solo il legame: `ON DELETE SET NULL`.
 *
 * Rieseguibile: la chiave si aggiunge solo se non c'è, dopo aver sciolto i legami con assemblee che non esistono.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('registrazioni') || ! Schema::hasTable('assemblee') || ! Schema::hasColumn('registrazioni', 'assemblea_id')) {
            return;
        }

        $giaLegata = collect(Schema::getForeignKeys('registrazioni'))
            ->contains(fn (array $chiave) => $chiave['columns'] === ['assemblea_id']);

        if ($giaLegata) {
            return;
        }

        DB::table('registrazioni')
            ->whereNotNull('assemblea_id')
            ->whereNotIn('assemblea_id', DB::table('assemblee')->select('id'))
            ->update(['assemblea_id' => null]);

        Schema::table('registrazioni', function (Blueprint $table) {
            $table->foreign('assemblea_id')->references('id')->on('assemblee')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('registrazioni')) {
            return;
        }

        $giaLegata = collect(Schema::getForeignKeys('registrazioni'))
            ->contains(fn (array $chiave) => $chiave['columns'] === ['assemblea_id']);

        if ($giaLegata) {
            Schema::table('registrazioni', fn (Blueprint $table) => $table->dropForeign(['assemblea_id']));
        }
    }
};
