<?php

/*
 * Il catalogo degli agenti AI e i campi obbligatori (Web Pro Italia, 17/11/2026).
 *
 * Il catalogo legge i campi di una funzione dalle regole del suo modulo (FormRequest), senza validare niente. Molti moduli
 * hanno regole diverse per la creazione e per la modifica (`isMethod('post')`: obbligatorio in creazione, facoltativo in
 * modifica). Il modulo usato per leggerle non aveva un metodo, si credeva una GET, e il catalogo dava per facoltativi il
 * tipo, il titolo e la modalità di un'assemblea nuova, il nome e il corpo di un modello di verbale nuovo: un agente li
 * ometteva e scopriva l'errore solo scrivendo. Ora il modulo porta il metodo della rotta.
 */

use App\Models\ChiaveAgente;
use App\Models\Condominio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $ruolo = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'amministratore', 'guard_name' => 'web']);
    foreach (\App\Enums\Permission::cases() as $permesso) {
        \Spatie\Permission\Models\Permission::findOrCreate($permesso->value, 'web');
    }
    $ruolo->syncPermissions(\App\Enums\Permission::cases());

    $utente = User::factory()->create();
    $utente->assignRole($ruolo);
    [, $chiave] = ChiaveAgente::genera('Assistente di prova', $utente, false);
    $this->chiave = ['Authorization' => 'Bearer '.$chiave];
});

it('⚠️ il catalogo dà per obbligatori i campi che in creazione lo sono, e per facoltativi gli stessi campi in modifica', function () {
    $rotte = collect($this->getJson(route('agenti.catalogo'), $this->chiave)->assertOk()->json('dati.rotte'))->keyBy('nome');

    $nuova = $rotte['admin.gestionale.assemblee.store']['campi'];
    $modifica = $rotte['admin.gestionale.assemblee.update']['campi'];
    foreach (['tipo', 'titolo', 'modalita'] as $campo) {
        // In creazione obbligatorio, in modifica facoltativo.
        expect($nuova[$campo])->toContain('required')
            ->and($nuova[$campo])->not->toContain('sometimes')
            ->and($modifica[$campo])->toContain('sometimes')
            ->and($modifica[$campo])->not->toContain('required');
    }

    $modelloNuovo = $rotte['admin.gestionale.assemblee.modelli.store']['campi'];
    $modelloModifica = $rotte['admin.gestionale.assemblee.modelli.update']['campi'];
    foreach (['nome', 'corpo'] as $campo) {
        expect($modelloNuovo[$campo])->toContain('required')
            ->and($modelloModifica[$campo])->toContain('sometimes')
            ->and($modelloModifica[$campo])->not->toContain('required');
    }

    // I campi nuovi delle correzioni al verbale compaiono da soli, con le loro regole.
    expect($rotte['admin.gestionale.assemblee.verbale.invia']['campi'])->toHaveKeys(['dati_riletti', 'esiti_confermati'])
        ->and($modifica)->toHaveKey('elimina_punti')
        ->and($nuova)->not->toHaveKey('elimina_punti');
});

it('⚠️ anche il dettaglio di una funzione, con i parametri veri, legge le regole con il metodo della rotta', function () {
    $condominio = Condominio::factory()->create();

    $nuova = $this->getJson(route('agenti.catalogo', ['rotta' => 'admin.gestionale.assemblee.store', 'parametri' => ['condominio' => $condominio->id]]), $this->chiave)
        ->assertOk()->json('dati');

    expect($nuova['metodi'])->toBe(['POST'])
        ->and($nuova['campi']['titolo'])->toContain('required')
        ->and($nuova['campi']['tipo'])->toContain('required')
        ->and($nuova['campi']['modalita'])->toContain('required');
});
