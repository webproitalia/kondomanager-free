<?php

/**
 * # Impostazioni → Aspetto: dove si aprono le schede (Web Pro Italia, 18/11/2026)
 *
 * La scheda di una persona, di un'unità e i moduli si aprono in una **scheda laterale**, che entra da destra sopra la pagina
 * in cui si è, oppure a pagina intera come sempre. La scelta è un'impostazione dell'aspetto (`aspetto.schede`, «lato» o
 * «pagina») e arriva al browser scritta sulla pagina (`<html data-km-schede="…">`), dove la legge
 * `resources/js/composables/useImpalcatura.ts`. La scheda laterale c'è solo con il menu laterale: con il menu in alto la
 * pagina porta sempre «pagina», e la scelta salvata resta dov'è per quando si torna al menu laterale.
 *
 * La scheda laterale è tutta nel browser: le pagine sono le stesse, agli stessi indirizzi, e il server risponde come sempre.
 *
 * Cosa copre: il valore predefinito; ciò che arriva alla pagina e al modulo; il salvataggio e i suoi permessi; il rifiuto di
 * un valore che non esiste; una pagina aperta prima dell'aggiornamento, che non tocca le schede; il menu in alto.
 *
 * Cosa NON copre: come la scheda si apre e si chiude (`resources/js/components/scheda/*.test.ts`); la migrazione
 * rieseguibile (`tests/Feature/System/UpgradeMigrationsRerunTest.php`).
 */

use App\Models\User;
use App\Settings\AspettoSettings;
use App\Support\Tema;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->withoutVite();
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo(
        Permission::firstOrCreate(['name' => \App\Enums\Permission::MANAGE_GENERAL_SETTINGS->value, 'guard_name' => 'web'])
    );

    $this->admin = User::factory()->create();
    $this->admin->assignRole($ruolo);
});

/** La pagina intera (non una risposta di Inertia): è lì che sta scritta la scelta. */
function paginaConLeSchede($test): string
{
    return (string) $test->actingAs($test->admin)->get('/impostazioni/aspetto')->assertOk()->getContent();
}

it('di serie le schede si aprono di lato, e la pagina lo porta scritto', function () {
    expect(app(AspettoSettings::class)->schede)->toBe('lato')
        ->and(Tema::schede())->toBe('lato')
        ->and(paginaConLeSchede($this))->toContain('data-km-schede="lato"');
});

it('la pagina Aspetto riceve la scelta salvata e le due possibilità', function () {
    $this->actingAs($this->admin)->get('/impostazioni/aspetto')
        ->assertInertia(fn (Assert $page) => $page
            ->component('impostazioni/impostazioniAspetto')
            ->where('aspetto.schede', 'lato')
            ->where('scelte.schede', [['valore' => 'lato', 'nome' => 'Di lato'], ['valore' => 'pagina', 'nome' => 'A pagina intera']])
        );
});

it('«a pagina intera» si salva, e da lì ogni pagina lo porta scritto; poi si torna alle schede di lato', function () {
    $this->actingAs($this->admin)
        ->post('/impostazioni/aspetto', ['colore' => 'grafite', 'angoli' => 'standard', 'menu' => 'laterale', 'schede' => 'pagina'])
        ->assertSessionHasNoErrors();

    expect(app(AspettoSettings::class)->refresh()->schede)->toBe('pagina')
        ->and(app(AspettoSettings::class)->menu)->toBe('laterale')
        ->and(paginaConLeSchede($this))->toContain('data-km-schede="pagina"')->not->toContain('data-km-schede="lato"');

    $this->actingAs($this->admin)
        ->post('/impostazioni/aspetto', ['colore' => 'grafite', 'angoli' => 'standard', 'menu' => 'laterale', 'schede' => 'lato'])
        ->assertSessionHasNoErrors();

    expect(app(AspettoSettings::class)->refresh()->schede)->toBe('lato')
        ->and(paginaConLeSchede($this))->toContain('data-km-schede="lato"');
});

it('con il menu in alto la pagina porta «pagina»: la scheda laterale c\'è solo con il menu laterale', function () {
    $this->actingAs($this->admin)
        ->post('/impostazioni/aspetto', ['colore' => 'grafite', 'angoli' => 'standard', 'menu' => 'alto', 'schede' => 'lato'])
        ->assertSessionHasNoErrors();

    // La scelta salvata resta «lato»: non vale adesso, e torna a valere con il menu laterale.
    expect(app(AspettoSettings::class)->refresh()->schede)->toBe('lato')
        ->and(Tema::schede())->toBe('pagina')
        ->and(paginaConLeSchede($this))->toContain('data-km-menu="alto"', 'data-km-schede="pagina"');

    $this->actingAs($this->admin)->get('/impostazioni/aspetto')
        ->assertInertia(fn (Assert $page) => $page->where('aspetto.menu', 'alto')->where('aspetto.schede', 'lato'));

    $this->actingAs($this->admin)
        ->post('/impostazioni/aspetto', ['colore' => 'grafite', 'angoli' => 'standard', 'menu' => 'laterale'])
        ->assertSessionHasNoErrors();

    expect(paginaConLeSchede($this))->toContain('data-km-menu="laterale"', 'data-km-schede="lato"');
});

it('una pagina aperta prima dell\'aggiornamento salva il resto e non tocca le schede', function () {
    $aspetto = app(AspettoSettings::class);
    $aspetto->schede = 'pagina';
    $aspetto->save();

    $this->actingAs($this->admin)
        ->post('/impostazioni/aspetto', ['colore' => 'blu', 'angoli' => 'morbidi', 'menu' => 'laterale'])
        ->assertSessionHasNoErrors();

    $salvato = app(AspettoSettings::class)->refresh();
    expect([$salvato->colore, $salvato->angoli, $salvato->menu, $salvato->schede])->toBe(['blu', 'morbidi', 'laterale', 'pagina']);
});

it('una scelta che non esiste è rifiutata, e niente cambia', function (mixed $valore) {
    $this->actingAs($this->admin)
        ->post('/impostazioni/aspetto', ['colore' => 'blu', 'angoli' => 'morbidi', 'schede' => $valore])
        ->assertSessionHasErrors('schede');

    $salvato = app(AspettoSettings::class)->refresh();
    expect([$salvato->colore, $salvato->angoli, $salvato->schede])->toBe(['grafite', 'standard', 'lato']);
})->with(['una parola qualunque' => ['sopra'], 'vuoto' => [''], 'un elenco' => [['pagina']]]);

it('chi non gestisce le impostazioni non cambia le schede', function () {
    $this->actingAs(User::factory()->create())
        ->post('/impostazioni/aspetto', ['colore' => 'grafite', 'angoli' => 'standard', 'schede' => 'pagina'])
        ->assertForbidden();

    expect(app(AspettoSettings::class)->refresh()->schede)->toBe('lato');
});

it('un valore sconosciuto rimasto nel database non rompe la pagina: valgono le schede di lato', function () {
    DB::table('settings')->where('group', 'aspetto')->where('name', 'schede')->update(['payload' => json_encode('a-fianco')]);
    app()->forgetInstance(AspettoSettings::class);

    expect(Tema::schede())->toBe('lato')
        ->and(paginaConLeSchede($this))->toContain('data-km-schede="lato"');
});
