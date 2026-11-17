<?php

/**
 * # La sezione «Assemblee» (Web Pro Italia, 17/11/2026)
 *
 * L'elenco e la nuova assemblea con l'ordine del giorno (punti che si aggiungono, si tolgono e si riordinano), lo
 * svolgimento, l'elenco degli aventi diritto preparato dalla pagina, le presenze toccate in sala, i modelli di verbale
 * dello studio e i permessi: chi non ha «Visualizza assemblee» non entra, chi non ha «Approva verbali» non approva.
 */

use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\AssembleaPunto;
use App\Models\Assemblee\VerbaleModello;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/AssembleeHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->utente = asmAmministratore();
    $this->actingAs($this->utente);
});

it('crea un\'assemblea con l\'ordine del giorno, lo riordina e non toglie un punto che ha già dei voti', function () {
    $s = asmCondominio(['A' => ['millesimi' => '1000', 'titolari' => ['Anna Alberti' => 'proprietario']]]);
    $c = $s['condominio'];

    $this->get(route('admin.gestionale.assemblee.create', ['condominio' => $c->id]))
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('gestionale/assemblee/AssembleaForm')->where('proposta.tabella_id', $s['tabella']->id));

    $this->post(route('admin.gestionale.assemblee.store', ['condominio' => $c->id]), [
        'tipo' => 'ordinaria', 'titolo' => 'Assemblea ordinaria 2026', 'modalita' => 'mista', 'luogo' => 'Sala parrocchiale',
        'data_prima_convocazione' => '2026-11-22 09:00', 'data_seconda_convocazione' => '2026-11-23 18:30', 'tabella_id' => $s['tabella']->id,
        'punti' => [
            ['titolo' => 'Rendiconto 2025', 'maggioranza' => 'ordinaria'],
            ['titolo' => 'Nomina dell\'amministratore', 'maggioranza' => 'art1136c4'],
            ['titolo' => 'Varie ed eventuali', 'maggioranza' => 'informativa'],
        ],
    ])->assertSessionHasNoErrors()->assertRedirect();

    $a = Assemblea::where('titolo', 'Assemblea ordinaria 2026')->firstOrFail();
    expect($a->punti->pluck('titolo')->all())->toBe(['Rendiconto 2025', 'Nomina dell\'amministratore', 'Varie ed eventuali'])
        ->and($a->stato)->toBe('bozza')
        ->and($a->created_by)->toBe($this->utente->id);

    [$p1, $p2, $p3] = $a->punti->all();

    // Riordino: la nomina va prima, il rendiconto dopo; le varie si tolgono; un punto nuovo in fondo.
    $this->put(route('admin.gestionale.assemblee.update', ['condominio' => $c->id, 'assemblea' => $a->id]), ['punti' => [
        ['id' => $p2->id, 'titolo' => 'Nomina dell\'amministratore', 'maggioranza' => 'art1136c4'],
        ['id' => $p1->id, 'titolo' => 'Rendiconto 2025', 'maggioranza' => 'ordinaria'],
        ['titolo' => 'Preventivo 2026', 'maggioranza' => 'ordinaria'],
    ]])->assertSessionHasNoErrors();

    $punti = $a->fresh()->punti;
    expect($punti->map(fn ($p) => $p->numero.' '.$p->titolo)->all())->toBe(['1 Nomina dell\'amministratore', '2 Rendiconto 2025', '3 Preventivo 2026'])
        ->and(AssembleaPunto::find($p3->id))->toBeNull()
        ->and($a->fresh()->luogo)->toBe('Sala parrocchiale');

    // Un punto con dei voti non si toglie.
    $this->post(route('admin.gestionale.assemblee.presenze.prepara', ['condominio' => $c->id, 'assemblea' => $a->id]))->assertSessionHasNoErrors();
    asmSegna($a, [$s['persone']['Anna Alberti']]);
    asmVota($p1->fresh(), [[$s['persone']['Anna Alberti'], 'favorevole']]);
    $this->put(route('admin.gestionale.assemblee.update', ['condominio' => $c->id, 'assemblea' => $a->id]), ['punti' => [
        ['id' => $p2->id, 'titolo' => 'Nomina dell\'amministratore', 'maggioranza' => 'art1136c4'],
    ]])->assertSessionHasErrors('punti');
    expect($a->fresh()->punti)->toHaveCount(3);
});

it('lo svolgimento: «inizia» e «chiudi» segnano lo stato e l\'ora; presidente e segretario si salvano', function () {
    $s = asmCondominio(['A' => ['millesimi' => '1000', 'titolari' => ['Anna Alberti' => 'proprietario']]]);
    $a = $s['assemblea'];
    $indirizzo = route('admin.gestionale.assemblee.svolgimento', ['condominio' => $a->condominio_id, 'assemblea' => $a->id]);

    $this->put($indirizzo, ['adesso' => 'inizia', 'convocazione_tenuta' => 'seconda', 'presidente' => ' Anna Alberti '])->assertSessionHasNoErrors();
    $a->refresh();
    expect($a->stato)->toBe('in_corso')
        ->and($a->ora_inizio)->not->toBeNull()
        ->and($a->presidente)->toBe('Anna Alberti')
        ->and($a->convocazione_tenuta)->toBe('seconda');

    $this->put($indirizzo, ['adesso' => 'chiudi', 'ora_fine' => '20:15'])->assertSessionHasNoErrors();
    expect($a->fresh()->stato)->toBe('chiusa')
        ->and(substr((string) $a->fresh()->ora_fine, 0, 5))->toBe('20:15');
});

it('in sala: presente, delega a un nome scritto a mano, arrivo e uscita', function () {
    $s = asmCondominio([
        'A' => ['millesimi' => '600', 'titolari' => ['Anna Alberti' => 'proprietario']],
        'B' => ['millesimi' => '400', 'titolari' => ['Bruno Bassi' => 'proprietario']],
    ], ['ora_inizio' => '18:30']);
    $a = $s['assemblea'];
    $this->post(route('admin.gestionale.assemblee.presenze.prepara', ['condominio' => $a->condominio_id, 'assemblea' => $a->id]))->assertSessionHasNoErrors();
    $anna = asmPresenza($a, $s['persone']['Anna Alberti']);
    $bruno = asmPresenza($a, $s['persone']['Bruno Bassi']);
    $indirizzo = fn ($p) => route('admin.gestionale.assemblee.presenze.update', ['condominio' => $a->condominio_id, 'assemblea' => $a->id, 'presenza' => $p->id]);

    $this->put($indirizzo($anna), ['tipo' => 'presente'])->assertSessionHasNoErrors();
    $this->put($indirizzo($bruno), ['delegato_nome' => 'Lucia Bassi'])->assertSessionHasNoErrors();
    expect($bruno->fresh()->tipo)->toBe('delega')->and($bruno->fresh()->nomeDelegato())->toBe('Lucia Bassi');

    $this->put($indirizzo($anna), ['segna' => 'uscita'])->assertSessionHasNoErrors();
    expect($anna->fresh()->inSala())->toBeFalse();
    $this->put($indirizzo($anna), ['segna' => 'rientro'])->assertSessionHasNoErrors();
    expect($anna->fresh()->inSala())->toBeTrue();

    // Assente: si toglie anche il delegato.
    $this->put($indirizzo($bruno), ['tipo' => 'assente'])->assertSessionHasNoErrors();
    expect($bruno->fresh()->delegato_nome)->toBeNull();

    // La pagina dell'assemblea si apre con la fotografia.
    $this->get(route('admin.gestionale.assemblee.show', ['condominio' => $a->condominio_id, 'assemblea' => $a->id]))
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('gestionale/assemblee/AssembleaShow')
            ->has('fotografia.presenze', 2)
            ->where('fotografia.conteggi.costituzione.teste_presenti', 1));
});

it('un\'assemblea, un punto o una presenza di un altro condominio rispondono 404', function () {
    $mia = asmCondominio(['A' => ['millesimi' => '1000', 'titolari' => ['Anna Alberti' => 'proprietario']]]);
    $altra = asmCondominio(['B' => ['millesimi' => '1000', 'titolari' => ['Bruno Bassi' => 'proprietario']]]);
    $punto = AssembleaPunto::create(['assemblea_id' => $altra['assemblea']->id, 'numero' => 1, 'titolo' => 'X', 'maggioranza' => 'ordinaria']);

    $this->get(route('admin.gestionale.assemblee.show', ['condominio' => $mia['condominio']->id, 'assemblea' => $altra['assemblea']->id]))->assertNotFound();
    $this->put(route('admin.gestionale.assemblee.punti.update', ['condominio' => $mia['condominio']->id, 'assemblea' => $mia['assemblea']->id, 'punto' => $punto->id]), ['delibera' => 'x'])->assertNotFound();
});

it('i modelli di verbale: si creano, si provano su un\'assemblea, si fa predefinito un altro, si eliminano', function () {
    $s = asmCondominio(['A' => ['millesimi' => '1000', 'titolari' => ['Anna Alberti' => 'proprietario']]], ['presidente' => 'Anna Alberti']);
    $c = $s['condominio'];
    $canonico = VerbaleModello::predefinito();

    $this->get(route('admin.gestionale.assemblee.modelli.index', ['condominio' => $c->id]))
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('gestionale/assemblee/ModelliVerbale')->has('modelli', 1)->has('segnaposti.punti.delibera'));

    $this->post(route('admin.gestionale.assemblee.modelli.store', ['condominio' => $c->id]), [
        'nome' => 'Verbale dello studio', 'corpo' => "Presiede {{presidente}}.\n{{campo_inventato}}", 'predefinito' => true,
    ])->assertSessionHasNoErrors();
    $nuovo = VerbaleModello::where('nome', 'Verbale dello studio')->firstOrFail();
    expect(VerbaleModello::predefinito()->id)->toBe($nuovo->id)
        ->and($canonico->fresh()->predefinito)->toBeFalse();

    $prova = $this->postJson(route('admin.gestionale.assemblee.modelli.prova', ['condominio' => $c->id]), ['corpo' => $nuovo->corpo, 'assemblea_id' => $s['assemblea']->id])->assertOk()->json();
    expect($prova['testo'])->toBe("Presiede Anna Alberti.\n{{campo_inventato}}\n")
        ->and($prova['sconosciuti'])->toBe(['campo_inventato']);

    $this->put(route('admin.gestionale.assemblee.modelli.update', ['condominio' => $c->id, 'modello' => $nuovo->id]), ['corpo' => 'Presiede {{presidente}}.'])->assertSessionHasNoErrors();
    expect($nuovo->fresh()->corpo)->toBe('Presiede {{presidente}}.');

    // Eliminato il predefinito, lo diventa il primo che resta.
    $this->delete(route('admin.gestionale.assemblee.modelli.destroy', ['condominio' => $c->id, 'modello' => $nuovo->id]))->assertSessionHasNoErrors();
    expect(VerbaleModello::find($nuovo->id))->toBeNull()
        ->and($canonico->fresh()->predefinito)->toBeTrue();
});

it('i permessi: senza «Visualizza assemblee» non si entra; con «Gestisci assemblee» si lavora ma non si approva', function () {
    $s = asmCondominio(['A' => ['millesimi' => '1000', 'titolari' => ['Anna Alberti' => 'proprietario']]]);
    $a = $s['assemblea'];

    $ruolo = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'collaboratore', 'guard_name' => 'web']);
    $ruolo->syncPermissions(array_map(fn ($p) => $p->value, \App\Enums\Role::COLLABORATORE->permissions()));
    $collaboratore = User::factory()->create();
    $collaboratore->assignRole($ruolo);
    $this->actingAs($collaboratore);

    $this->get(route('admin.gestionale.assemblee.index', ['condominio' => $a->condominio_id]))->assertOk();
    $this->put(route('admin.gestionale.assemblee.svolgimento', ['condominio' => $a->condominio_id, 'assemblea' => $a->id]), ['presidente' => 'X'])->assertSessionHasNoErrors();
    $this->post(route('admin.gestionale.assemblee.verbale.approva', ['condominio' => $a->condominio_id, 'assemblea' => $a->id]))->assertForbidden();

    $ruolo->revokePermissionTo(\App\Enums\Permission::VIEW_ASSEMBLEE->value);
    $ruolo->revokePermissionTo(\App\Enums\Permission::MANAGE_ASSEMBLEE->value);
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $this->get(route('admin.gestionale.assemblee.index', ['condominio' => $a->condominio_id]))->assertForbidden();
});
