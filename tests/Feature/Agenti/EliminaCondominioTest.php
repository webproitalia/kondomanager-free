<?php

/*
 * Eliminare un condominio con tutto il suo contenuto (Web Pro Italia, 15/11/2026).
 *
 * Il condominio di queste prove è quello dimostrativo, costruito dai servizi veri (unità, persone, tabelle, piano
 * rate, casse, fatture, pagamenti, deleghe F24, scritture) e poi «promosso» a condominio vero togliendogli la
 * marcatura: è il caso più pieno che il programma sappia costruire, ed è proprio quello che il tasto «elimina»
 * normale rifiuta. Qui si prova l'altra porta: quella che chiede il nome, salva una copia e a un agente AI chiede
 * un permesso sulla chiave.
 */

use App\Actions\Condominio\CreaCondominioDimostrativoAction;
use App\Models\AzioneAgente;
use App\Models\ChiaveAgente;
use App\Models\Condominio;
use App\Models\User;
use App\Services\Condomini\EliminaCondominio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([
        \Database\Seeders\CategoriaEventoSeeder::class,
        \Database\Seeders\TipologieImmobiliSeeder::class,
    ]);

    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $ruolo = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'amministratore', 'guard_name' => 'web']);
    foreach (\App\Enums\Permission::cases() as $permesso) {
        \Spatie\Permission\Models\Permission::findOrCreate($permesso->value, 'web');
    }
    $ruolo->syncPermissions(\App\Enums\Permission::cases());

    $this->utente = User::factory()->create();
    $this->utente->assignRole($ruolo);
    $this->actingAs($this->utente);

    Storage::fake('local');
});

/** Un condominio vero e pieno: quello dimostrativo senza la marcatura, con un nome suo. */
function condominioPieno(string $nome = 'Residenza Aurora'): Condominio
{
    $condominio = app(CreaCondominioDimostrativoAction::class)->esegui()['condominio'];
    $condominio->forceFill(['is_demo' => false, 'nome' => $nome])->save();

    return $condominio->fresh();
}

/** Le righe rimaste, in qualunque tabella, che citano ancora il condominio. */
function residuiDi(int $id): array
{
    $residui = [];

    foreach (Schema::getTables() as $tabella) {
        $nome = $tabella['name'];

        if (! Schema::hasColumn($nome, 'condominio_id')) {
            continue;
        }

        $n = DB::table($nome)->where('condominio_id', $id)->count();

        if ($n > 0) {
            $residui[$nome] = $n;
        }
    }

    return $residui;
}

function chiaveAgente(User $utente, bool $puoEliminare = false, bool $solaLettura = false): string
{
    [$chiave, $testo] = ChiaveAgente::genera('Assistente di prova', $utente, $solaLettura);
    $chiave->forceFill(['puo_eliminare' => $puoEliminare])->save();

    return $testo;
}

it('il tasto «elimina» normale continua a rifiutare un condominio con la contabilità', function () {
    $c = condominioPieno();

    $this->delete(route('condomini.destroy', $c));

    expect(Condominio::find($c->id))->not->toBeNull();
});

it('⚠️ riscrivendo il nome, una persona lo elimina con tutto il suo contenuto', function () {
    $c = condominioPieno();
    $contiContabili = DB::table('conti_contabili')->where('condominio_id', $c->id)->pluck('id');
    $persone = DB::table('anagrafica_condominio')->where('condominio_id', $c->id)->pluck('anagrafica_id');

    expect($contiContabili)->not->toBeEmpty()->and($persone)->not->toBeEmpty();

    // Maiuscole e spazi doppi non contano: conta che il nome sia quello.
    $risposta = $this->delete(route('condomini.elimina-tutto', $c), ['conferma_nome' => '  residenza   AURORA ']);

    $risposta->assertRedirect(route('condomini.index'));
    expect(Condominio::find($c->id))->toBeNull('Il condominio è ancora lì.');
    expect(residuiDi($c->id))->toBe([], 'Sono rimaste righe che citano il condominio eliminato.');
    expect(DB::table('conti_contabili')->whereIn('id', $contiContabili)->count())->toBe(0, 'i conti contabili');
    expect(DB::table('anagrafiche')->whereIn('id', $persone)->count())->toBe(0, 'le persone che avevano solo questo condominio');
});

it('⚠️ con il nome sbagliato non elimina niente', function () {
    $c = condominioPieno();
    $unita = DB::table('immobili')->where('condominio_id', $c->id)->count();

    $this->delete(route('condomini.elimina-tutto', $c), ['conferma_nome' => 'Residenza Tramonto'])
        ->assertSessionHasErrors('conferma_nome');
    $this->delete(route('condomini.elimina-tutto', $c), [])->assertSessionHasErrors('conferma_nome');

    expect(Condominio::find($c->id))->not->toBeNull()
        ->and(DB::table('immobili')->where('condominio_id', $c->id)->count())->toBe($unita);
});

it('⚠️ non tocca gli altri condomìni, né le persone che hanno anche un altro condominio o un accesso', function () {
    $c = condominioPieno();
    $altro = Condominio::factory()->create(['nome' => 'Palazzo Verdi']);
    $persone = DB::table('anagrafica_condominio')->where('condominio_id', $c->id)->orderBy('anagrafica_id')->pluck('anagrafica_id');

    // La prima persona sta anche nell'altro condominio; la seconda è un utente del programma.
    DB::table('anagrafica_condominio')->insert(['anagrafica_id' => $persone[0], 'condominio_id' => $altro->id, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('anagrafiche')->where('id', $persone[1])->update(['user_id' => $this->utente->id]);

    app(EliminaCondominio::class)->esegui($c);

    expect(Condominio::find($altro->id))->not->toBeNull()
        ->and(DB::table('anagrafiche')->whereIn('id', [$persone[0], $persone[1]])->count())->toBe(2, 'chi ha un altro condominio o un accesso resta in rubrica')
        ->and(DB::table('anagrafica_condominio')->where('condominio_id', $altro->id)->count())->toBe(1)
        ->and(DB::table('anagrafiche')->whereIn('id', $persone->slice(2))->count())->toBe(0);
});

it('⚠️ prima di cancellare salva una copia con tutte le righe, e ci sposta i file dei documenti', function () {
    $c = condominioPieno();
    $fattura = DB::table('fatture_passive')->where('condominio_id', $c->id)->value('id');
    Storage::disk('local')->put('documenti/fattura-aurora.pdf', 'pdf vero');
    $documento = DB::table('documenti')->insertGetId([
        'name' => 'Fattura', 'description' => 'allegato', 'created_by' => $this->utente->id, 'path' => 'documenti/fattura-aurora.pdf',
        'documentable_type' => \App\Models\Gestionale\FatturaPassiva::class, 'documentable_id' => $fattura,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $esito = app(EliminaCondominio::class)->esegui($c);

    expect($esito['copia'])->toStartWith(EliminaCondominio::CARTELLA.'/')
        ->and($esito['documenti_tolti'])->toBe(1)
        ->and(DB::table('documenti')->where('id', $documento)->exists())->toBeFalse()
        ->and(Storage::disk('local')->exists('documenti/fattura-aurora.pdf'))->toBeFalse()
        ->and(Storage::disk('local')->get($esito['copia'].'/file/fattura-aurora.pdf'))->toBe('pdf vero');

    $copia = json_decode(gzdecode(Storage::disk('local')->get($esito['copia'].'/dati.json.gz')), true);

    expect($copia['condominio']['nome'])->toBe('Residenza Aurora')
        ->and(count($copia['tabelle']['immobili']))->toBe(4)
        ->and(count($copia['tabelle']['fatture_passive']))->toBe(5)
        ->and(count($copia['tabelle']['casse']))->toBe(2)
        ->and($copia['tabelle'])->toHaveKeys(['righe_fattura', 'scritture_contabili', 'righe_scritture', 'rate', 'anagrafiche', 'documenti'])
        // Le rubriche condivise non finiscono nella copia per intero, e gli utenti mai.
        ->and($copia['tabelle'])->not->toHaveKey('users')
        ->and($copia['tabelle'])->not->toHaveKey('fornitori');
});

it('le copie più vecchie di trenta giorni si buttano alla cancellazione successiva', function () {
    Storage::disk('local')->put(EliminaCondominio::CARTELLA.'/20260101-000000-9-vecchio/dati.json.gz', 'x');
    touch(Storage::disk('local')->path(EliminaCondominio::CARTELLA.'/20260101-000000-9-vecchio/dati.json.gz'), now()->subDays(40)->getTimestamp());

    app(EliminaCondominio::class)->esegui(condominioPieno());

    expect(Storage::disk('local')->exists(EliminaCondominio::CARTELLA.'/20260101-000000-9-vecchio/dati.json.gz'))->toBeFalse()
        ->and(Storage::disk('local')->directories(EliminaCondominio::CARTELLA))->toHaveCount(1);
});

it('⚠️ un agente senza il permesso sulla chiave non può, e il rifiuto resta nel registro con il motivo', function () {
    $c = condominioPieno();
    $testo = chiaveAgente($this->utente, puoEliminare: false);

    $this->withToken($testo)->deleteJson("/condomini/{$c->id}/tutto", ['conferma_nome' => 'Residenza Aurora'])
        ->assertStatus(403)->assertJson(['ok' => false]);

    expect(Condominio::find($c->id))->not->toBeNull();

    $riga = AzioneAgente::latest('id')->first();
    expect($riga->stato)->toBe(403)
        ->and($riga->rotta)->toBe('condomini.elimina-tutto')
        ->and($riga->motivo)->toContain('non ha il permesso di eliminare un condominio');
});

it('⚠️ per un agente il tasto «elimina» normale resta vietato anche con il permesso', function () {
    $c = condominioPieno();
    $testo = chiaveAgente($this->utente, puoEliminare: true);

    $this->withToken($testo)->deleteJson("/condomini/{$c->id}")->assertStatus(403);

    expect(Condominio::find($c->id))->not->toBeNull()
        ->and(AzioneAgente::latest('id')->first()->stato)->toBe(403);
});

it('⚠️ un agente con il permesso in anteprima vede che cosa sparirebbe, e non sparisce niente', function () {
    $c = condominioPieno();
    $testo = chiaveAgente($this->utente, puoEliminare: true);

    $risposta = $this->withToken($testo)->withHeaders(['X-Kondo-Prova' => '1'])
        ->deleteJson("/condomini/{$c->id}/tutto", ['conferma_nome' => 'Residenza Aurora']);

    $risposta->assertOk()->assertJson(['ok' => true, 'prova' => true]);
    expect(collect($risposta->json('avvisi'))->pluck('testo')->implode(' '))
        ->toContain('Anteprima')->toContain('verrebbe eliminato')->toContain('4 unità')->toContain('5 fatture')
        ->toContain('1 esercizio,')->toContain('Non è stato cancellato niente');

    expect(Condominio::find($c->id))->not->toBeNull('In anteprima il condominio deve restare.')
        ->and(DB::table('immobili')->where('condominio_id', $c->id)->count())->toBe(4)
        ->and(Storage::disk('local')->directories(EliminaCondominio::CARTELLA))->toBe([], 'In anteprima non si salva nessuna copia.');
});

it('⚠️ un agente con il permesso lo elimina davvero, e la scrittura resta nel registro', function () {
    $c = condominioPieno();
    $testo = chiaveAgente($this->utente, puoEliminare: true);

    $risposta = $this->withToken($testo)->deleteJson("/condomini/{$c->id}/tutto", ['conferma_nome' => 'Residenza Aurora']);

    $risposta->assertOk()->assertJson(['ok' => true]);
    expect(Condominio::find($c->id))->toBeNull()
        ->and(residuiDi($c->id))->toBe([])
        ->and(Storage::disk('local')->directories(EliminaCondominio::CARTELLA))->toHaveCount(1);

    $riga = AzioneAgente::latest('id')->first();
    expect($riga->stato)->toBe(200)->and($riga->prova)->toBeFalse()->and($riga->motivo)->toBeNull();
});

it('⚠️ un agente con il permesso ma con il nome sbagliato viene rifiutato, e il registro dice quale campo', function () {
    $c = condominioPieno();
    $testo = chiaveAgente($this->utente, puoEliminare: true);

    $this->withToken($testo)->deleteJson("/condomini/{$c->id}/tutto", ['conferma_nome' => 'Un altro nome'])
        ->assertStatus(422)->assertJson(['ok' => false]);

    expect(Condominio::find($c->id))->not->toBeNull();

    $riga = AzioneAgente::latest('id')->first();
    expect($riga->stato)->toBe(422)
        ->and($riga->campi)->toBe('conferma_nome')
        ->and($riga->motivo)->toContain('nome');
});

it('il permesso si accende e si spegne nelle impostazioni, e una chiave non può darselo da sola', function () {
    [$chiave, $testo] = ChiaveAgente::genera('Assistente di prova', $this->utente, false);
    [$solaLettura] = ChiaveAgente::genera('Solo consultazione', $this->utente, true);
    $impostazioni = app(\App\Settings\GeneralSettings::class);
    \Illuminate\Support\Facades\Gate::before(fn () => true);

    $this->patch("/impostazioni/agenti/{$chiave->id}", ['puo_eliminare' => true])->assertRedirect();
    expect($chiave->fresh()->puo_eliminare)->toBeTrue();

    $this->patch("/impostazioni/agenti/{$solaLettura->id}", ['puo_eliminare' => true])->assertRedirect();
    expect($solaLettura->fresh()->puo_eliminare)->toBeFalse('una chiave di sola consultazione non può eliminare');

    $this->patch("/impostazioni/agenti/{$chiave->id}", ['puo_eliminare' => false])->assertRedirect();
    expect($chiave->fresh()->puo_eliminare)->toBeFalse();

    // Mai a un collegamento tecnico, come l'app dei condòmini.
    $portineria = User::factory()->create(['email' => 'portineria@app.invalid']);
    [$tecnica] = ChiaveAgente::genera('Portineria', $portineria, false);
    $this->patch("/impostazioni/agenti/{$tecnica->id}", ['puo_eliminare' => true])->assertRedirect();
    expect($tecnica->fresh()->puo_eliminare)->toBeFalse('un collegamento tecnico non può eliminare');

    // Con la chiave, la stessa richiesta è vietata: le impostazioni non sono per gli agenti.
    $this->withToken($testo)->patchJson("/impostazioni/agenti/{$chiave->id}", ['puo_eliminare' => true])->assertStatus(403);
    expect($chiave->fresh()->puo_eliminare)->toBeFalse();
});

it('nel registro finiscono anche le letture andate male, non quelle riuscite', function () {
    $testo = chiaveAgente($this->utente);
    $prima = AzioneAgente::count();

    $this->withToken($testo)->getJson('/condomini')->assertOk();
    expect(AzioneAgente::count())->toBe($prima, 'una lettura riuscita non si registra');

    $this->withToken($testo)->getJson('/condomini/999999')->assertStatus(404);
    $riga = AzioneAgente::latest('id')->first();
    expect(AzioneAgente::count())->toBe($prima + 1)
        ->and($riga->metodo)->toBe('GET')
        ->and($riga->stato)->toBe(404);
});

it('⚠️ se il contatore delle richieste inciampa, la richiesta passa lo stesso', function () {
    $testo = chiaveAgente($this->utente);
    RateLimiter::shouldReceive('attempt')->andThrow(new RuntimeException('Deadlock found when trying to get lock'));

    $this->withToken($testo)->getJson('/condomini')->assertOk();
});

it('un rifiuto scritto in testo semplice arriva all\'agente con il suo motivo, e finisce nel registro', function () {
    \Illuminate\Support\Facades\Route::middleware('web')->post('/prova-rifiuto-in-testo', fn () => response('Manca il canale di invio per 3 persone.', 422, ['Content-Type' => 'text/plain; charset=UTF-8']))
        ->name('prova.rifiuto-in-testo');
    $testo = chiaveAgente($this->utente);

    $this->withToken($testo)->postJson('/prova-rifiuto-in-testo')
        ->assertStatus(422)->assertJson(['ok' => false, 'messaggio' => 'Manca il canale di invio per 3 persone.']);

    expect(AzioneAgente::latest('id')->first()->motivo)->toBe('Manca il canale di invio per 3 persone.');
});
