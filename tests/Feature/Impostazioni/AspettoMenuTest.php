<?php

/**
 * # Impostazioni → Aspetto: dove sta il menu (Web Pro Italia, 18/11/2026)
 *
 * L'installazione ha due impalcature: quella a menu laterale, predefinita, e quella con il menu in alto, che è la testata
 * di sempre. La scelta è un'impostazione dell'aspetto (`aspetto.menu`, «laterale» o «alto») e arriva al browser scritta
 * sulla pagina (`<html data-km-menu="…">`), dove la legge `resources/js/composables/useImpalcatura.ts`.
 *
 * Il colore dello studio dà anche la tinta del menu laterale: il fondo è il colore molto scuro, la voce attiva lo usa pieno.
 *
 * Cosa copre: il valore predefinito; il salvataggio e i suoi permessi; il rifiuto di un valore che non esiste; una pagina
 * aperta prima dell'aggiornamento, che salva colore e angoli e non tocca il menu; ciò che arriva alla pagina e al modulo;
 * le variabili del menu per ogni colore.
 *
 * Cosa NON copre: come le due impalcature si disegnano (`resources/js/layouts/Impalcature.test.ts`,
 * `resources/js/components/menu/*.test.ts`); la migrazione rieseguibile (`tests/Feature/System/UpgradeMigrationsRerunTest.php`).
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
function paginaAspetto($test): string
{
    return (string) $test->actingAs($test->admin)->get('/impostazioni/aspetto')->assertOk()->getContent();
}

it('di serie il menu è laterale, e la pagina lo porta scritto', function () {
    expect(app(AspettoSettings::class)->menu)->toBe('laterale')
        ->and(Tema::menu())->toBe('laterale')
        ->and(paginaAspetto($this))->toContain('data-km-menu="laterale"');
});

it('la pagina Aspetto riceve la scelta salvata e le due possibilità', function () {
    $this->actingAs($this->admin)->get('/impostazioni/aspetto')
        ->assertInertia(fn (Assert $page) => $page
            ->component('impostazioni/impostazioniAspetto')
            ->where('aspetto.menu', 'laterale')
            ->where('scelte.menu', [['valore' => 'laterale', 'nome' => 'Laterale'], ['valore' => 'alto', 'nome' => 'In alto']])
        );
});

it('«in alto» si salva, e da lì ogni pagina lo porta scritto; poi si torna al laterale', function () {
    $this->actingAs($this->admin)
        ->post('/impostazioni/aspetto', ['colore' => 'turchese', 'angoli' => 'standard', 'menu' => 'alto'])
        ->assertSessionHasNoErrors();

    expect(app(AspettoSettings::class)->refresh()->menu)->toBe('alto')
        ->and(app(AspettoSettings::class)->colore)->toBe('turchese')
        ->and(paginaAspetto($this))->toContain('data-km-menu="alto"')->not->toContain('data-km-menu="laterale"');

    $this->actingAs($this->admin)
        ->post('/impostazioni/aspetto', ['colore' => 'turchese', 'angoli' => 'standard', 'menu' => 'laterale'])
        ->assertSessionHasNoErrors();

    expect(app(AspettoSettings::class)->refresh()->menu)->toBe('laterale')
        ->and(paginaAspetto($this))->toContain('data-km-menu="laterale"');
});

it('una pagina aperta prima dell\'aggiornamento salva colore e angoli e non tocca il menu', function () {
    $aspetto = app(AspettoSettings::class);
    $aspetto->menu = 'alto';
    $aspetto->save();

    $this->actingAs($this->admin)
        ->post('/impostazioni/aspetto', ['colore' => 'blu', 'angoli' => 'morbidi'])
        ->assertSessionHasNoErrors();

    $salvato = app(AspettoSettings::class)->refresh();
    expect([$salvato->colore, $salvato->angoli, $salvato->menu])->toBe(['blu', 'morbidi', 'alto']);
});

it('un menu che non esiste è rifiutato, e niente cambia', function (mixed $valore) {
    $this->actingAs($this->admin)
        ->post('/impostazioni/aspetto', ['colore' => 'blu', 'angoli' => 'morbidi', 'menu' => $valore])
        ->assertSessionHasErrors('menu');

    $salvato = app(AspettoSettings::class)->refresh();
    expect([$salvato->colore, $salvato->angoli, $salvato->menu])->toBe(['grafite', 'standard', 'laterale']);
})->with(['una parola qualunque' => ['sotto'], 'vuoto' => [''], 'un elenco' => [['alto']]]);

it('chi non gestisce le impostazioni non cambia il menu', function () {
    $this->actingAs(User::factory()->create())
        ->post('/impostazioni/aspetto', ['colore' => 'grafite', 'angoli' => 'standard', 'menu' => 'alto'])
        ->assertForbidden();

    expect(app(AspettoSettings::class)->refresh()->menu)->toBe('laterale');
});

it('un valore sconosciuto rimasto nel database non rompe la pagina: vale il menu laterale', function () {
    DB::table('settings')->where('group', 'aspetto')->where('name', 'menu')->update(['payload' => json_encode('di-lato')]);
    app()->forgetInstance(AspettoSettings::class);

    expect(Tema::menu())->toBe('laterale')
        ->and(paginaAspetto($this))->toContain('data-km-menu="laterale"');
});

it('il colore dello studio dà la tinta del menu laterale: la tinta per il fondo scuro, il colore pieno per la voce attiva', function () {
    foreach (Tema::COLORI as $chiave => $colore) {
        $css = Tema::cssPer($chiave, 'standard');

        if ($chiave === 'grafite') {
            // L'aspetto originale non scrive niente: i suoi valori stanno in resources/css/menu-laterale.css.
            expect($css)->toBe('');

            continue;
        }

        $tinta = (int) strtok($colore['chiaro']['p'], ' ');
        [$chiaro, $scuro] = explode('.dark{', $css);

        expect($chiaro)->toContain("--km-tinta:{$tinta};", "--km-menu-attiva:hsl({$colore['chiaro']['p']});", '--km-menu-sat:')
            // Il menu è scuro in tutti e due i temi: nel tema scuro le sue variabili non si riscrivono.
            ->and($scuro)->not->toContain('--km-');
    }
});

it('l\'anteprima della pagina Aspetto porta, per ogni colore, anche la tinta del menu', function () {
    $anteprime = Tema::scelte()['anteprime'];

    expect($anteprime['turchese']['standard'])->toContain('--km-tinta:177;')
        ->and($anteprime['bordeaux']['squadrati'])->toContain('--km-tinta:345;')->toContain('--radius:0.25rem;');
});
