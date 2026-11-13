<?php

/**
 * LA RATA DEI SALDI SCADE QUANDO DICI TU (Web Pro Italia, 13/11/2026).
 *
 * Con `metodo_distribuzione = rata_zero` i saldi pregressi stanno in una rata a parte, la rata 0, che scadeva per forza
 * lo stesso giorno della prima rata. Un'amministratrice ha chiesto di poterle dare una data sua: prima rata il
 * 15/01/2027, saldi il 15/12/2026. Il piano porta ora `data_rata_zero`, facoltativa.
 *
 * Che cosa presidia questo file:
 * - con la data, la rata 0 (e ogni sua quota) scade quel giorno, e le rate ordinarie non si spostano;
 * - senza la data, la rata 0 scade con la prima rata: il comportamento di sempre;
 * - la creazione del piano dal modulo la salva, e solo con la rata separata;
 * - «Ricalcola» la conserva; con `data_rata_zero` nella richiesta la cambia, vuota la toglie; su un piano approvato
 *   il cambio si rifiuta e non riscrive niente.
 *
 * Che cosa NON copre: il campo a video (`PianiRateNew.vue`) e il badge della pagina del piano.
 */

use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Actions\PianoRate\GenerateRateQuotesAction;
use App\Enums\StatoPianoRate;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Rata;
use App\Models\Gestionale\RataQuote;
use App\Models\Gestione;
use App\Models\Immobile;
use App\Models\Saldo;
use App\Models\Tabella;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Il caso della segnalazione: esercizio e gestione 2027, un'unità con il suo proprietario, una voce di spesa da
 * 1.000 € e un saldo pregresso a debito di 300 €. (Lo stesso impianto di `SaldiLockOrfanoTest`, con nomi suoi: le
 * funzioni di un file di test sono globali.)
 */
function rzsScenario(): object
{
    $condominio = Condominio::create([
        'nome' => 'Condominio Rata Zero',
        'uuid' => (string) Str::uuid(),
        'indirizzo' => 'Via Roma 1', 'citta' => 'Milano', 'cap' => '20100', 'provincia' => 'MI',
    ]);

    $esercizio = Esercizio::create([
        'condominio_id' => $condominio->id,
        'nome' => '2027',
        'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31', 'stato' => 'aperto',
    ]);

    $gestione = Gestione::create([
        'condominio_id' => $condominio->id,
        'nome' => 'Ordinaria 2027',
        'data_inizio' => '2027-01-01', 'tipo' => 'ordinaria',
        'saldo_applicato' => false,
    ]);
    $gestione->esercizi()->attach($esercizio->id, ['attiva' => true]);

    $anagrafica = Anagrafica::create([
        'condominio_id' => $condominio->id,
        'nome' => 'Mario', 'cognome' => 'Rossi', 'email' => 'mario.ratazero@test.it',
        'indirizzo' => 'Via Verdi 10', 'cap' => '00100', 'citta' => 'Roma', 'provincia' => 'RM',
        'codice_fiscale' => 'RSSMRA80A01H501U',
    ]);

    $tabella = Tabella::create([
        'condominio_id' => $condominio->id,
        'nome' => 'Generale', 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true,
    ]);

    $immobile = Immobile::create([
        'condominio_id' => $condominio->id,
        'nome' => 'Int 1', 'descrizione' => 'Appartamento', 'interno' => '1',
        'foglio' => '1', 'particella' => '100', 'subalterno' => '1',
    ]);
    $immobile->anagrafiche()->attach($anagrafica->id, [
        'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => '2019-01-01',
    ]);

    DB::table('quote_tabella')->insert([
        'tabella_id' => $tabella->id, 'immobile_id' => $immobile->id, 'valore' => 1000,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $pianoConti = PianoConto::create([
        'condominio_id' => $condominio->id, 'gestione_id' => $gestione->id, 'nome' => 'Preventivo 2027',
    ]);

    $capitolo = Conto::forceCreate([
        'piano_conto_id' => $pianoConti->id, 'nome' => 'Spese Generali',
        'importo' => 100000, 'tipo' => 'spesa', 'attivo' => true,
    ]);

    $pivotId = DB::table('conto_tabella_millesimale')->insertGetId([
        'conto_id' => $capitolo->id, 'tabella_id' => $tabella->id, 'coefficiente' => 100,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('conto_tabella_ripartizioni')->insert([
        'conto_tabella_millesimale_id' => $pivotId, 'soggetto' => 'proprietario', 'percentuale' => 100,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $saldo = Saldo::create([
        'esercizio_id' => $esercizio->id,
        'condominio_id' => $condominio->id,
        'anagrafica_id' => $anagrafica->id,
        'immobile_id' => $immobile->id,
        'gestione_id' => $gestione->id,
        'saldo_iniziale' => 30000, // positivo = debito pregresso
        'origine' => 'manuale',
        'is_applicato' => false,
    ]);

    return (object) compact('condominio', 'esercizio', 'gestione', 'anagrafica', 'immobile', 'capitolo', 'saldo');
}

function rzsAdmin(): User
{
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);

    $user = User::factory()->create();
    $user->assignRole($ruolo);

    return $user;
}

/** Un piano a due rate (15/01 e 15/02/2027) con la rata separata, generato con i saldi; `$dataRataZero` null = come sempre. */
function rzsPianoGenerato(object $s, ?string $dataRataZero, string $metodo = 'rata_zero'): PianoRate
{
    $piano = PianoRate::create([
        'condominio_id' => $s->condominio->id,
        'gestione_id' => $s->gestione->id,
        'nome' => 'Piano 2027',
        'numero_rate' => 2,
        'giorno_scadenza' => 15,
        'data_prima_scadenza' => '2027-01-15',
        'data_rata_zero' => $dataRataZero,
        'metodo_distribuzione' => $metodo,
        'stato' => 'bozza',
        'tipo' => 'ordinario',
    ]);

    app(GeneratePianoRateAction::class)->execute(pianoRate: $piano, forzaApplicazioneSaldi: true);

    return $piano->refresh();
}

/** Le scadenze delle rate del piano, per numero di rata: `[0 => '2026-12-15', 1 => '2027-01-15', …]`. */
function rzsScadenze(PianoRate $piano): array
{
    return Rata::where('piano_rate_id', $piano->id)->orderBy('numero_rata')->get()
        ->mapWithKeys(fn (Rata $r) => [(int) $r->numero_rata => $r->data_scadenza->toDateString()])
        ->all();
}

/** Le scadenze scritte sulle quote della rata 0 (ognuna porta la sua copia della data). */
function rzsScadenzeQuoteRataZero(PianoRate $piano): array
{
    $rataZero = Rata::where('piano_rate_id', $piano->id)->where('numero_rata', 0)->firstOrFail();

    return RataQuote::where('rata_id', $rataZero->id)->get()
        ->map(fn (RataQuote $q) => substr((string) $q->getRawOriginal('data_scadenza'), 0, 10))
        ->unique()->values()->all();
}

function rzsRicalcola($test, User $user, object $s, PianoRate $piano, array $corpo = [])
{
    return $test->actingAs($user)->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [
        $s->condominio->id, $s->esercizio->id, $piano->id,
    ]), $corpo);
}

// ---------------------------------------------------------------------------
// 1. La generazione
// ---------------------------------------------------------------------------

test('con data_rata_zero la rata dei saldi scade quel giorno, e le rate ordinarie non si spostano', function () {
    $s = rzsScenario();

    $piano = rzsPianoGenerato($s, '2026-12-15');

    expect(rzsScadenze($piano))->toBe([0 => '2026-12-15', 1 => '2027-01-15', 2 => '2027-02-15'])
        // ogni quota della rata 0 porta la stessa data della sua rata
        ->and(rzsScadenzeQuoteRataZero($piano))->toBe(['2026-12-15']);

    // I soldi non cambiano: il pregresso sta tutto sulla rata 0, il preventivo sulle due ordinarie.
    $importi = Rata::where('piano_rate_id', $piano->id)->orderBy('numero_rata')->pluck('importo_totale', 'numero_rata')->map(fn ($i) => (int) $i)->all();
    expect($importi)->toBe([0 => 30000, 1 => 50000, 2 => 50000]);
});

test('senza data_rata_zero la rata dei saldi scade con la prima rata, come sempre', function () {
    $s = rzsScenario();

    $piano = rzsPianoGenerato($s, null);

    expect($piano->data_rata_zero)->toBeNull()
        ->and(rzsScadenze($piano))->toBe([0 => '2027-01-15', 1 => '2027-01-15', 2 => '2027-02-15'])
        ->and(rzsScadenzeQuoteRataZero($piano))->toBe(['2027-01-15']);
});

test('chiamata diretta: l\'azione legge la data dal piano e lascia le date delle rate come le riceve', function () {
    $s = rzsScenario();
    $base = [
        'condominio_id' => $s->condominio->id, 'gestione_id' => $s->gestione->id, 'numero_rate' => 2,
        'metodo_distribuzione' => 'rata_zero', 'stato' => 'bozza', 'tipo' => 'ordinario',
    ];
    $saldi = [$s->anagrafica->id => [$s->immobile->id => ['importo' => 30000, 'meta_storico' => ['tipo_riparto' => 'nominale']]]];
    $totali = [$s->anagrafica->id => [$s->immobile->id => 100000]];
    $date = ['2027-01-15', '2027-02-15'];

    $con = PianoRate::create($base + ['nome' => 'Con la data', 'data_rata_zero' => '2026-12-15']);
    $senza = PianoRate::create($base + ['nome' => 'Senza la data']);

    app(GenerateRateQuotesAction::class)->execute($con, $totali, $date, $saldi);
    app(GenerateRateQuotesAction::class)->execute($senza, $totali, $date, $saldi);

    expect(rzsScadenze($con))->toBe([0 => '2026-12-15', 1 => '2027-01-15', 2 => '2027-02-15'])
        ->and(rzsScadenze($senza))->toBe([0 => '2027-01-15', 1 => '2027-01-15', 2 => '2027-02-15']);
});

test('con un altro metodo di distribuzione la data non crea nessuna rata dei saldi', function () {
    $s = rzsScenario();

    // Una data rimasta su un piano che somma i saldi alla prima rata non fa nascere una rata 0.
    $piano = rzsPianoGenerato($s, '2026-12-15', 'prima_rata');

    expect(rzsScadenze($piano))->toBe([1 => '2027-01-15', 2 => '2027-02-15']);
});

// ---------------------------------------------------------------------------
// 2. La creazione dal modulo
// ---------------------------------------------------------------------------

test('la creazione del piano salva la data e la usa per la rata dei saldi', function () {
    $s = rzsScenario();

    $this->actingAs(rzsAdmin())
        ->post(route('admin.gestionale.esercizi.piani-rate.store', [$s->condominio->id, $s->esercizio->id]), [
            'gestione_id' => $s->gestione->id,
            'nome' => 'Piano dal modulo',
            'tipo' => 'ordinario',
            'metodo_distribuzione' => 'rata_zero',
            'numero_rate' => 2,
            'giorno_scadenza' => 15,
            'data_prima_scadenza' => '2027-01-15',
            'data_rata_zero' => '2026-12-15',
            'capitoli_ids' => [$s->capitolo->id],
            'genera_subito' => true,
            'recurrence_enabled' => false,
        ])->assertSessionHasNoErrors();

    $piano = PianoRate::where('gestione_id', $s->gestione->id)->firstOrFail();

    expect($piano->data_rata_zero?->toDateString())->toBe('2026-12-15')
        ->and(rzsScadenze($piano))->toBe([0 => '2026-12-15', 1 => '2027-01-15', 2 => '2027-02-15']);
});

test('la creazione rifiuta una data che non è una data, e con un altro metodo non la salva', function () {
    $s = rzsScenario();
    $user = rzsAdmin();
    $corpo = fn (array $extra) => array_merge([
        'gestione_id' => $s->gestione->id,
        'nome' => 'Piano dal modulo',
        'tipo' => 'ordinario',
        'metodo_distribuzione' => 'rata_zero',
        'numero_rate' => 2,
        'giorno_scadenza' => 15,
        'capitoli_ids' => [$s->capitolo->id],
        'genera_subito' => false,
        'recurrence_enabled' => false,
    ], $extra);
    $rotta = route('admin.gestionale.esercizi.piani-rate.store', [$s->condominio->id, $s->esercizio->id]);

    $this->actingAs($user)->post($rotta, $corpo(['data_rata_zero' => 'dicembre']))->assertSessionHasErrors('data_rata_zero');
    expect(PianoRate::count())->toBe(0);

    $this->actingAs($user)->post($rotta, $corpo(['metodo_distribuzione' => 'prima_rata', 'data_rata_zero' => '2026-12-15']))->assertSessionHasNoErrors();
    expect(PianoRate::firstOrFail()->data_rata_zero)->toBeNull();
});

// ---------------------------------------------------------------------------
// 3. «Ricalcola»
// ---------------------------------------------------------------------------

test('il ricalcolo conserva la data salvata sul piano', function () {
    $s = rzsScenario();
    $piano = rzsPianoGenerato($s, '2026-12-15');

    $ratePrima = Rata::where('piano_rate_id', $piano->id)->pluck('id')->all();

    rzsRicalcola($this, rzsAdmin(), $s, $piano)->assertSessionHas('message.type', 'success');

    expect($piano->fresh()->data_rata_zero->toDateString())->toBe('2026-12-15')
        ->and(rzsScadenze($piano))->toBe([0 => '2026-12-15', 1 => '2027-01-15', 2 => '2027-02-15'])
        // le rate sono state riscritte davvero: il test non passa su un ricalcolo che non è partito
        ->and(Rata::where('piano_rate_id', $piano->id)->pluck('id')->all())->not->toBe($ratePrima);
});

test('il ricalcolo con data_rata_zero nella richiesta la cambia, su un piano in bozza', function () {
    $s = rzsScenario();
    $user = rzsAdmin();
    $piano = rzsPianoGenerato($s, '2026-12-15');

    rzsRicalcola($this, $user, $s, $piano, ['data_rata_zero' => '2026-12-20'])->assertSessionHas('message.type', 'success');

    expect($piano->fresh()->data_rata_zero->toDateString())->toBe('2026-12-20')
        ->and(rzsScadenze($piano))->toBe([0 => '2026-12-20', 1 => '2027-01-15', 2 => '2027-02-15'])
        ->and(rzsScadenzeQuoteRataZero($piano))->toBe(['2026-12-20']);
});

test('il ricalcolo con data_rata_zero nella richiesta la mette su un piano che non l\'aveva', function () {
    $s = rzsScenario();
    $piano = rzsPianoGenerato($s, null);

    rzsRicalcola($this, rzsAdmin(), $s, $piano, ['data_rata_zero' => '2026-12-15'])->assertSessionHas('message.type', 'success');

    expect($piano->fresh()->data_rata_zero->toDateString())->toBe('2026-12-15')
        ->and(rzsScadenze($piano))->toBe([0 => '2026-12-15', 1 => '2027-01-15', 2 => '2027-02-15']);
});

test('il ricalcolo con data_rata_zero vuota la toglie: la rata dei saldi torna con la prima rata', function (mixed $vuota) {
    $s = rzsScenario();
    $piano = rzsPianoGenerato($s, '2026-12-15');

    rzsRicalcola($this, rzsAdmin(), $s, $piano, ['data_rata_zero' => $vuota])->assertSessionHas('message.type', 'success');

    expect($piano->fresh()->data_rata_zero)->toBeNull()
        ->and(rzsScadenze($piano))->toBe([0 => '2027-01-15', 1 => '2027-01-15', 2 => '2027-02-15']);
})->with([
    'stringa vuota' => [''],
    'null' => [null],
]);

test('su un piano approvato il cambio della data si rifiuta e non riscrive niente; senza la chiave il ricalcolo passa', function () {
    $s = rzsScenario();
    $user = rzsAdmin();
    $piano = rzsPianoGenerato($s, '2026-12-15');
    $piano->update(['stato' => StatoPianoRate::APPROVATO]);
    $ratePrima = Rata::where('piano_rate_id', $piano->id)->pluck('id')->all();

    $rifiuto = rzsRicalcola($this, $user, $s, $piano, ['data_rata_zero' => '2026-12-20']);
    $rifiuto->assertSessionHas('message.type', 'error');

    expect(session('message.message'))->toContain('piano in bozza')
        ->and($piano->fresh()->data_rata_zero->toDateString())->toBe('2026-12-15')
        // le rate sono ancora quelle di prima: il rifiuto arriva prima di cancellarle
        ->and(Rata::where('piano_rate_id', $piano->id)->pluck('id')->all())->toBe($ratePrima);

    // La stessa data di prima non è un cambio: il ricalcolo di un piano approvato resta quello di sempre.
    rzsRicalcola($this, $user, $s, $piano, ['data_rata_zero' => '2026-12-15'])->assertSessionHas('message.type', 'success');
    expect(rzsScadenze($piano))->toBe([0 => '2026-12-15', 1 => '2027-01-15', 2 => '2027-02-15'])
        ->and(Rata::where('piano_rate_id', $piano->id)->pluck('id')->all())->not->toBe($ratePrima);
});

test('il ricalcolo rifiuta una data che non è una data', function () {
    $s = rzsScenario();
    $piano = rzsPianoGenerato($s, '2026-12-15');

    rzsRicalcola($this, rzsAdmin(), $s, $piano, ['data_rata_zero' => 'dicembre'])->assertSessionHasErrors('data_rata_zero');

    expect($piano->fresh()->data_rata_zero->toDateString())->toBe('2026-12-15');
});
