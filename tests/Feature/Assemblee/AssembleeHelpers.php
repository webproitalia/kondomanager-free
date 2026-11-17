<?php

/**
 * Gli aiuti dei test delle assemblee (Web Pro Italia, 17/11/2026). Nessun test qui dentro: solo il condominio di prova
 * con le unità, i titolari e la tabella dei millesimi che ogni prova sceglie. Prefisso `asm`: Pest carica tutti i file
 * nello stesso processo.
 */

use App\Models\Anagrafica;
use App\Models\Assemblee\Assemblea;
use App\Models\Condominio;
use App\Models\Immobile;
use App\Models\Tabella;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Un utente con il ruolo amministratore e tutti i permessi, collegato. */
function asmAmministratore(): User
{
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $ruolo = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'amministratore', 'guard_name' => 'web']);
    foreach (\App\Enums\Permission::cases() as $permesso) {
        \Spatie\Permission\Models\Permission::findOrCreate($permesso->value, 'web');
    }
    $ruolo->syncPermissions(\App\Enums\Permission::cases());

    $utente = User::factory()->create(['name' => 'Studio Prova']);
    $utente->assignRole($ruolo);

    return $utente;
}

/**
 * Un condominio con le unità date: `['Int. 1' => ['millesimi' => '250', 'titolari' => ['Rossi Mario' => 'proprietario']], …]`.
 * Un titolare può essere `['proprietario', 60]` per dire la quota. Le persone con lo stesso nome sono la stessa persona.
 *
 * @return array{condominio: Condominio, tabella: Tabella, unita: array<string, Immobile>, persone: array<string, Anagrafica>, assemblea: Assemblea}
 */
function asmCondominio(array $unita, array $assemblea = []): array
{
    static $seq = 0;
    $seq++;

    $condominio = Condominio::factory()->create(['nome' => 'Le Rose '.$seq, 'codice_fiscale' => '9000000000'.$seq]);
    $tabella = Tabella::create(['condominio_id' => $condominio->id, 'nome' => 'Generale di proprietà', 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true]);

    $persone = [];
    $creati = [];
    foreach ($unita as $nomeUnita => $u) {
        $immobile = Immobile::create(['condominio_id' => $condominio->id, 'nome' => $nomeUnita, 'interno' => (string) (count($creati) + 1)]);
        $creati[$nomeUnita] = $immobile;
        if (array_key_exists('millesimi', $u)) {
            DB::table('quote_tabella')->insert(['tabella_id' => $tabella->id, 'immobile_id' => $immobile->id, 'valore' => $u['millesimi'], 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ($u['titolari'] ?? [] as $nome => $ruolo) {
            [$tipologia, $quota] = is_array($ruolo) ? $ruolo : [$ruolo, 100];
            if (! isset($persone[$nome])) {
                $n = count($persone) + 1;
                $persone[$nome] = Anagrafica::forceCreate([
                    'nome' => $nome, 'email' => "asm{$seq}-{$n}@prova.it", 'indirizzo' => 'Via Roma 1',
                    'codice_fiscale' => 'ASM'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT).str_pad((string) $n, 9, '0', STR_PAD_LEFT),
                ]);
                $persone[$nome]->condomini()->syncWithoutDetaching([$condominio->id]);
            }
            DB::table('anagrafica_immobile')->insert([
                'anagrafica_id' => $persone[$nome]->id, 'immobile_id' => $immobile->id, 'tipologia' => $tipologia, 'quota' => $quota,
                'attivo' => true, 'data_inizio' => '2020-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    $a = Assemblea::create(array_merge([
        'condominio_id' => $condominio->id,
        'tipo' => 'ordinaria',
        'titolo' => 'Assemblea ordinaria',
        'data_prima_convocazione' => '2026-11-22 09:00',
        'data_seconda_convocazione' => '2026-11-23 18:30',
        'luogo' => 'la sala condominiale, Via Roma 1',
        'modalita' => 'presenza',
        'tabella_id' => $tabella->id,
        'stato' => 'convocata',
    ], $assemblea));

    return ['condominio' => $condominio, 'tabella' => $tabella, 'unita' => $creati, 'persone' => $persone, 'assemblea' => $a];
}

/** La presenza di una persona (il rappresentante) nell'assemblea. */
function asmPresenza(Assemblea $assemblea, Anagrafica $persona): \App\Models\Assemblee\AssembleaPresenza
{
    return \App\Models\Assemblee\AssembleaPresenza::where('assemblea_id', $assemblea->id)->where('anagrafica_id', $persona->id)->firstOrFail();
}

/** Segna presenti (o per delega) le persone date. */
function asmSegna(Assemblea $assemblea, array $persone, string $tipo = 'presente', ?string $delegato = null): void
{
    foreach ($persone as $p) {
        asmPresenza($assemblea, $p)->forceFill(['tipo' => $tipo, 'delegato_nome' => $tipo === 'delega' ? ($delegato ?? 'Delegato Qualunque') : null])->save();
    }
}

/** I voti di un punto: `[persona, 'favorevole']`, …. */
function asmVota(\App\Models\Assemblee\AssembleaPunto $punto, array $voti): void
{
    foreach ($voti as [$persona, $voto]) {
        \App\Models\Assemblee\AssembleaVoto::updateOrCreate(
            ['assemblea_punto_id' => $punto->id, 'assemblea_presenza_id' => asmPresenza($punto->assemblea, $persona)->id],
            ['voto' => $voto],
        );
    }
}

/** La votazione calcolata di un punto, con le presenze rilette. */
function asmVotazione(\App\Models\Assemblee\AssembleaPunto $punto): array
{
    $assemblea = Assemblea::with(['presenze.anagrafica', 'presenze.delegato'])->findOrFail($punto->assemblea_id);

    return app(\App\Services\Assemblee\ConteggiAssemblea::class)->votazione($assemblea, $punto->fresh(['voti']));
}

/** La costituzione calcolata, con le presenze rilette. */
function asmCostituzione(Assemblea $assemblea): array
{
    return app(\App\Services\Assemblee\ConteggiAssemblea::class)->costituzione(Assemblea::with(['presenze.anagrafica'])->findOrFail($assemblea->id));
}
