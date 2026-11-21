<?php

/**
 * # Impostazioni → Aspetto: lo stile dell'area di lavoro (Web Pro Italia, 21/11/2026)
 *
 * L'area di lavoro ha due stili: «professionale» — superfici piane, una riga sola e sottile, angoli piccoli, il carattere
 * del sistema: i valori sono quelli di Odoo 16 — e «morbido», quello di prima. La scelta è un'impostazione dell'aspetto
 * (`aspetto.stile`) e arriva al browser scritta sulla pagina (`<html data-km-stile="…">`): tutte le regole dello stile
 * professionale, in `resources/css/stile-professionale.css`, valgono solo sotto quell'attributo. Lo stile professionale
 * veste l'impalcatura a menu laterale: con il menu in alto la pagina porta sempre «morbido», e la scelta salvata resta
 * dov'è per quando si torna al menu laterale.
 *
 * Con lo stile è arrivato anche un colore: «Glicine», il viola del gestionale da cui vengono i valori (#71639e).
 *
 * È solo aspetto: il server non fa altro che ricordare la scelta e scriverla sulla pagina.
 *
 * Cosa copre: il valore predefinito; ciò che arriva alla pagina e al modulo; il salvataggio e i suoi permessi; il rifiuto di
 * un valore che non esiste; una pagina aperta prima dell'aggiornamento, che non tocca lo stile; il menu in alto; il colore
 * nuovo, con la tinta che dà al menu.
 *
 * Cosa NON copre: che cosa cambia nelle pagine (`resources/js/composables/stileProfessionale.test.ts` controlla il foglio;
 * le fotografie prima/dopo stanno con il lavoro); la migrazione rieseguibile (`tests/Feature/System/UpgradeMigrationsRerunTest.php`).
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
function paginaConLoStile($test): string
{
    return (string) $test->actingAs($test->admin)->get('/impostazioni/aspetto')->assertOk()->getContent();
}

it('di serie lo stile è professionale, e la pagina lo porta scritto', function () {
    expect(app(AspettoSettings::class)->stile)->toBe('professionale')
        ->and(Tema::stile())->toBe('professionale')
        ->and(paginaConLoStile($this))->toContain('data-km-stile="professionale"');
});

it('la pagina Aspetto riceve la scelta salvata e le due possibilità', function () {
    $this->actingAs($this->admin)->get('/impostazioni/aspetto')
        ->assertInertia(fn (Assert $page) => $page
            ->component('impostazioni/impostazioniAspetto')
            ->where('aspetto.stile', 'professionale')
            ->where('scelte.stile', [['valore' => 'professionale', 'nome' => 'Professionale'], ['valore' => 'morbido', 'nome' => 'Morbido']])
        );
});

it('«morbido» si salva, e da lì ogni pagina lo porta scritto; poi si torna al professionale', function () {
    $this->actingAs($this->admin)
        ->post('/impostazioni/aspetto', ['colore' => 'grafite', 'angoli' => 'standard', 'menu' => 'laterale', 'stile' => 'morbido'])
        ->assertSessionHasNoErrors();

    expect(app(AspettoSettings::class)->refresh()->stile)->toBe('morbido')
        ->and(paginaConLoStile($this))->toContain('data-km-stile="morbido"')->not->toContain('data-km-stile="professionale"');

    $this->actingAs($this->admin)
        ->post('/impostazioni/aspetto', ['colore' => 'grafite', 'angoli' => 'standard', 'menu' => 'laterale', 'stile' => 'professionale'])
        ->assertSessionHasNoErrors();

    expect(app(AspettoSettings::class)->refresh()->stile)->toBe('professionale')
        ->and(paginaConLoStile($this))->toContain('data-km-stile="professionale"');
});

it('con il menu in alto la pagina porta «morbido»: lo stile professionale veste solo il menu laterale', function () {
    $this->actingAs($this->admin)
        ->post('/impostazioni/aspetto', ['colore' => 'grafite', 'angoli' => 'standard', 'menu' => 'alto', 'stile' => 'professionale'])
        ->assertSessionHasNoErrors();

    // La scelta salvata resta «professionale»: non vale adesso, e torna a valere con il menu laterale.
    expect(app(AspettoSettings::class)->refresh()->stile)->toBe('professionale')
        ->and(Tema::stile())->toBe('morbido')
        ->and(paginaConLoStile($this))->toContain('data-km-menu="alto"', 'data-km-stile="morbido"');

    $this->actingAs($this->admin)->get('/impostazioni/aspetto')
        ->assertInertia(fn (Assert $page) => $page->where('aspetto.menu', 'alto')->where('aspetto.stile', 'professionale'));

    $this->actingAs($this->admin)
        ->post('/impostazioni/aspetto', ['colore' => 'grafite', 'angoli' => 'standard', 'menu' => 'laterale'])
        ->assertSessionHasNoErrors();

    expect(paginaConLoStile($this))->toContain('data-km-menu="laterale"', 'data-km-stile="professionale"');
});

it('una pagina aperta prima dell\'aggiornamento salva il resto e non tocca lo stile', function () {
    $aspetto = app(AspettoSettings::class);
    $aspetto->stile = 'morbido';
    $aspetto->save();

    $this->actingAs($this->admin)
        ->post('/impostazioni/aspetto', ['colore' => 'blu', 'angoli' => 'morbidi', 'menu' => 'laterale', 'schede' => 'lato'])
        ->assertSessionHasNoErrors();

    $salvato = app(AspettoSettings::class)->refresh();
    expect([$salvato->colore, $salvato->angoli, $salvato->menu, $salvato->schede, $salvato->stile])->toBe(['blu', 'morbidi', 'laterale', 'lato', 'morbido']);
});

it('uno stile che non esiste è rifiutato, e niente cambia', function (mixed $valore) {
    $this->actingAs($this->admin)
        ->post('/impostazioni/aspetto', ['colore' => 'blu', 'angoli' => 'morbidi', 'stile' => $valore])
        ->assertSessionHasErrors('stile');

    $salvato = app(AspettoSettings::class)->refresh();
    expect([$salvato->colore, $salvato->angoli, $salvato->stile])->toBe(['grafite', 'standard', 'professionale']);
})->with(['una parola qualunque' => ['odoo'], 'vuoto' => [''], 'un elenco' => [['morbido']]]);

it('chi non gestisce le impostazioni non cambia lo stile', function () {
    $this->actingAs(User::factory()->create())
        ->post('/impostazioni/aspetto', ['colore' => 'grafite', 'angoli' => 'standard', 'stile' => 'morbido'])
        ->assertForbidden();

    expect(app(AspettoSettings::class)->refresh()->stile)->toBe('professionale');
});

it('un valore sconosciuto rimasto nel database non rompe la pagina: vale lo stile professionale', function () {
    DB::table('settings')->where('group', 'aspetto')->where('name', 'stile')->update(['payload' => json_encode('lucido')]);
    app()->forgetInstance(AspettoSettings::class);

    expect(Tema::stile())->toBe('professionale')
        ->and(paginaConLoStile($this))->toContain('data-km-stile="professionale"');
});

it('«Glicine» è fra i colori: si salva, colora pulsanti e selezioni e dà la sua tinta al menu', function () {
    expect(Tema::COLORI['glicine']['campione'])->toBe('#71639e');

    $this->actingAs($this->admin)->get('/impostazioni/aspetto')
        ->assertInertia(fn (Assert $page) => $page->where('scelte.colori', fn ($colori) => collect($colori)->contains(fn ($c) => $c['valore'] === 'glicine' && $c['nome'] === 'Glicine' && $c['campione'] === '#71639e')));

    $this->actingAs($this->admin)
        ->post('/impostazioni/aspetto', ['colore' => 'glicine', 'angoli' => 'standard'])
        ->assertSessionHasNoErrors();

    expect(app(AspettoSettings::class)->refresh()->colore)->toBe('glicine')
        ->and(Tema::css())->toContain('--primary:hsl(254 23.3% 50.4%);', '--km-tinta:254;', '--km-menu-attiva:hsl(254 23.3% 50.4%);')
        ->and(paginaConLoStile($this))->toContain('--primary:hsl(254 23.3% 50.4%)');
});
