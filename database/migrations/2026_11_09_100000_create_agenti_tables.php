<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agenti AI (Web Pro Italia, 09/11/2026): le chiavi con cui un agente (Claude, Maeve, un server MCP) usa il gestionale
 * al posto di una persona, e il registro di quello che ha fatto.
 *
 * Una chiave appartiene a un utente: l'agente vede e fa quello che può fare quell'utente, con gli stessi permessi e
 * gli stessi controlli dei moduli. Della chiave si salva solo l'impronta (sha256): il testo si mostra una volta sola,
 * alla creazione. Il registro conserva metodo, percorso, rotta ed esito di ogni scrittura, non i dati inviati.
 *
 * Guardie su ogni tabella: rieseguire `up()` dopo un'interruzione non fa niente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('agenti_chiavi')) {
            Schema::create('agenti_chiavi', function (Blueprint $table) {
                $table->id();
                $table->string('nome', 100);
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('creata_da')->nullable()->constrained('users')->nullOnDelete();
                $table->string('impronta', 64)->unique();
                $table->string('inizio', 12);
                $table->boolean('sola_lettura')->default(true);
                $table->timestamp('ultimo_uso_at')->nullable();
                $table->timestamp('scade_at')->nullable();
                $table->timestamp('revocata_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('agenti_azioni')) {
            Schema::create('agenti_azioni', function (Blueprint $table) {
                $table->id();
                $table->foreignId('chiave_id')->constrained('agenti_chiavi')->cascadeOnDelete();
                $table->string('metodo', 10);
                $table->string('percorso', 500);
                $table->string('rotta', 150)->nullable();
                $table->unsignedSmallInteger('stato');
                $table->boolean('prova')->default(false);
                $table->unsignedInteger('durata_ms')->default(0);
                $table->timestamp('created_at')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('agenti_azioni');
        Schema::dropIfExists('agenti_chiavi');
    }
};
