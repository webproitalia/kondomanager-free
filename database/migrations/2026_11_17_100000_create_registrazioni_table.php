<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Le registrazioni delle riunioni del condominio (Web Pro Italia, 17/11/2026): il browser registra il microfono a pezzi da 25 secondi, ogni
 * pezzo sale subito e diventa il testo in diretta; a fine riunione i pezzi si uniscono, una passata sola riconosce le voci («Persona 1, 2…»)
 * e un modello scrive il riassunto e, se la registrazione è collegata a un'assemblea, una bozza per punto dell'ordine del giorno.
 *
 * Una registrazione non è sempre un'assemblea: `assemblea_id` è facoltativo, intero con indice e SENZA chiave esterna, perché la tabella
 * `assemblee` nasce in un altro lavoro; il vincolo si aggiunge con una migrazione a parte quando ci sono tutte e due.
 * L'audio non sta qui: è sul disco privato (`storage/app/private/registrazioni/<id>/seg-0001.webm`) e si cancella da solo dopo
 * `registrazioni.giorni_audio` giorni (`audio_cancellato_at`); la trascrizione resta finché la persona non elimina la registrazione.
 *
 * Rieseguibile: su MySQL la tabella, ognuno degli indici e ognuna delle chiavi esterne sono statement distinti (la `create table` porta solo
 * le colonne e la chiave primaria; il DDL non è transazionale), quindi una guardia per la tabella, una per ogni indice e una per ogni chiave.
 * Nel dataset di `UpgradeMigrationsRerunTest`.
 */
return new class extends Migration
{
    /** Nome dell'indice => colonne. */
    private const INDICI = [
        'registrazioni_condominio_stato_index' => ['condominio_id', 'stato'],
        'registrazioni_assemblea_id_index'     => ['assemblea_id'],
    ];

    /** Colonna => [tabella di arrivo, regola alla cancellazione]. */
    private const CHIAVI = [
        'condominio_id' => ['condomini', 'cascade'],
        'created_by'    => ['users', 'set null'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('registrazioni')) {
            Schema::create('registrazioni', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('condominio_id');
                $table->unsignedBigInteger('assemblea_id')->nullable()->comment('L\'assemblea a cui la registrazione appartiene, se c\'è. Senza chiave esterna per ora.');
                $table->string('titolo', 150);
                $table->string('stato', 20)->default('in_corso')->comment('in_corso, in_elaborazione, pronta, errore.');
                $table->timestamp('avviso_presenti_at')->nullable()->comment('Quando chi registra ha confermato di aver avvisato i presenti.');
                $table->timestamp('iniziata_at')->nullable();
                $table->timestamp('finita_at')->nullable();
                $table->unsignedInteger('durata_secondi')->nullable();
                $table->string('formato', 10)->nullable()->comment('webm, mp4 (iPhone) o ogg: il formato dei pezzi audio.');
                $table->json('segmenti')->nullable()->comment('I pezzi saliti, per numero: byte, secondi, testo in diretta, errore.');
                $table->longText('trascrizione_live')->nullable();
                $table->longText('trascrizione')->nullable()->comment('La trascrizione finale, con le voci.');
                $table->longText('riassunto')->nullable();
                $table->json('bozza_punti')->nullable()->comment('[{numero, discussione, delibera}] per l\'ordine del giorno dell\'assemblea.');
                $table->timestamp('audio_cancellato_at')->nullable();
                $table->text('errore')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        foreach (self::INDICI as $nome => $colonne) {
            if (! Schema::hasIndex('registrazioni', $nome)) {
                Schema::table('registrazioni', fn (Blueprint $table) => $table->index($colonne, $nome));
            }
        }

        foreach (self::CHIAVI as $colonna => [$arrivo, $regola]) {
            if (Schema::hasTable($arrivo) && ! $this->foreignKeyEsiste('registrazioni', $colonna)) {
                Schema::table('registrazioni', function (Blueprint $table) use ($colonna, $arrivo, $regola) {
                    $chiave = $table->foreign($colonna, "registrazioni_{$colonna}_foreign")->references('id')->on($arrivo);
                    $regola === 'cascade' ? $chiave->cascadeOnDelete() : $chiave->nullOnDelete();
                });
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('registrazioni');
    }

    /** Esiste una chiave esterna su questa colonna? Su SQLite si legge `PRAGMA foreign_key_list`. */
    private function foreignKeyEsiste(string $tabella, string $colonna): bool
    {
        if (DB::getDriverName() !== 'mysql' && DB::getDriverName() !== 'mariadb') {
            foreach (DB::select("PRAGMA foreign_key_list({$tabella})") as $fk) {
                if (($fk->from ?? null) === $colonna) {
                    return true;
                }
            }

            return false;
        }

        return DB::selectOne('
            SELECT COUNT(*) AS n
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE table_schema = DATABASE()
              AND table_name = ?
              AND column_name = ?
              AND referenced_table_name IS NOT NULL
        ', [$tabella, $colonna])->n > 0;
    }
};
