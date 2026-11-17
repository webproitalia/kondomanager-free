<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Le correzioni al verbale dell'assemblea dopo la revisione (Web Pro Italia, 17/11/2026).
 *
 * 1. `verbali.impronta_dati`: l'impronta dei dati dell'assemblea (presenze, voti, esiti, ore, testi dei punti…) com'erano
 *    quando il testo del verbale è stato generato o salvato l'ultima volta. All'invio in approvazione si confronta con
 *    quella dei dati di adesso: se è diversa, il testo potrebbe non corrispondere più ai dati.
 * 2. `assemblea_presenze.nome`: il nome della persona fotografato nella riga, perché la riga resti leggibile anche se
 *    l'anagrafica viene eliminata. Per il delegato scelto in rubrica lo stesso compito lo fa `delegato_nome`, che c'era
 *    già: qui si riempie per le righe che hanno solo il legame.
 * 3. `assemblea_presenze.anagrafica_id` non cancella più la riga quando si elimina l'anagrafica: la colonna diventa
 *    facoltativa e la chiave esterna passa da CASCADE a SET NULL. Presenze e voti di un'assemblea restano.
 *
 * Rieseguibile: ogni passo ha la sua guardia. Sulla chiave i passi sono tre statement su MySQL e MariaDB (via la chiave
 * vecchia, colonna facoltativa, chiave nuova), che non toccano le righe: un'interruzione fra l'uno e l'altro riparte da
 * quello che manca.
 *
 * ⚠️ SQLite (lo usano solo i test) per cambiare una chiave ricostruisce la tabella: Laravel spegne le chiavi esterne
 * mentre lo fa, ma dentro una transazione SQLite non le spegne, e la tabella vecchia tolta porterebbe via a cascata i
 * voti (`assemblea_voti`). Per questo la migrazione non gira dentro una transazione (`$withinTransaction`).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (Schema::hasTable('verbali') && ! Schema::hasColumn('verbali', 'impronta_dati')) {
            Schema::table('verbali', function (Blueprint $table) {
                $table->string('impronta_dati', 64)->nullable();
            });
        }

        if (! Schema::hasTable('assemblea_presenze')) {
            return;
        }

        if (! Schema::hasColumn('assemblea_presenze', 'nome')) {
            Schema::table('assemblea_presenze', function (Blueprint $table) {
                $table->string('nome', 255)->nullable();
            });
        }

        // I nomi di chi c'è già: solo dove mancano, così una seconda passata non riscrive niente.
        $this->fotografaINomi('anagrafica_id', 'nome');
        $this->fotografaINomi('delegato_anagrafica_id', 'delegato_nome');

        $chiave = collect(Schema::getForeignKeys('assemblea_presenze'))
            ->first(fn (array $k) => $k['columns'] === ['anagrafica_id']);

        if ($chiave !== null && strtolower((string) ($chiave['on_delete'] ?? '')) !== 'set null') {
            // Su SQLite le chiavi non hanno un nome: si tolgono per colonna (la tabella viene ricostruita).
            $nome = DB::getDriverName() === 'sqlite' || empty($chiave['name']) ? ['anagrafica_id'] : $chiave['name'];
            Schema::table('assemblea_presenze', fn (Blueprint $table) => $table->dropForeign($nome));
            $chiave = null;
        }

        $colonna = collect(Schema::getColumns('assemblea_presenze'))->firstWhere('name', 'anagrafica_id');
        if ($colonna !== null && ! $colonna['nullable']) {
            Schema::table('assemblea_presenze', function (Blueprint $table) {
                $table->unsignedBigInteger('anagrafica_id')->nullable()->change();
            });
        }

        if ($chiave === null) {
            // Fra un passo e l'altro la tabella è rimasta senza vincolo: un legame con un'anagrafica che non c'è più si scioglie (il nome resta).
            DB::table('assemblea_presenze')
                ->whereNotNull('anagrafica_id')
                ->whereNotIn('anagrafica_id', DB::table('anagrafiche')->select('id'))
                ->update(['anagrafica_id' => null]);

            Schema::table('assemblea_presenze', function (Blueprint $table) {
                $table->foreign('anagrafica_id')->references('id')->on('anagrafiche')->nullOnDelete();
            });
        }
    }

    /**
     * Le due colonne nuove si tolgono. La chiave resta SET NULL e la colonna facoltativa: tornare a CASCADE e a
     * «obbligatoria» fallirebbe appena c'è una riga rimasta senza anagrafica, che è proprio il caso che si è voluto salvare.
     */
    public function down(): void
    {
        if (Schema::hasTable('verbali') && Schema::hasColumn('verbali', 'impronta_dati')) {
            Schema::table('verbali', fn (Blueprint $table) => $table->dropColumn('impronta_dati'));
        }
        if (Schema::hasTable('assemblea_presenze') && Schema::hasColumn('assemblea_presenze', 'nome')) {
            Schema::table('assemblea_presenze', fn (Blueprint $table) => $table->dropColumn('nome'));
        }
    }

    /** Copia nella riga il nome dell'anagrafica legata, dove il nome manca. */
    private function fotografaINomi(string $legame, string $colonna): void
    {
        DB::table('assemblea_presenze')
            ->whereNotNull($legame)
            ->where(fn ($q) => $q->whereNull($colonna)->orWhere($colonna, ''))
            ->select(['id', $legame])
            ->chunkById(500, function ($righe) use ($legame, $colonna) {
                $nomi = DB::table('anagrafiche')->whereIn('id', $righe->pluck($legame)->unique())->pluck('nome', 'id');
                foreach ($righe as $riga) {
                    $nome = trim((string) ($nomi[$riga->{$legame}] ?? ''));
                    if ($nome !== '') {
                        DB::table('assemblea_presenze')->where('id', $riga->id)->update([$colonna => mb_substr($nome, 0, $colonna === 'nome' ? 255 : 150)]);
                    }
                }
            });
    }
};
