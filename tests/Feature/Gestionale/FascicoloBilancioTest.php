<?php

/**
 * # Il fascicolo di bilancio (Web Pro Italia, 13/11/2026)
 *
 * Un PDF solo per l'assemblea: copertina, nota sintetica esplicativa, rendiconto delle spese, situazione dei condòmini,
 * stato patrimoniale, fatture da pagare, registro, preventivo e rate.
 *
 * ⚠️ **La promessa che questi test tengono ferma è una sola: il fascicolo non ha numeri suoi.** Ogni cifra è quella del
 * registro da cui viene. Se un giorno il fascicolo e lo stato patrimoniale dicessero due cose diverse, i condòmini
 * riceverebbero un bilancio che non quadra con il gestionale che lo ha prodotto — ed è il difetto peggiore che un
 * documento del genere possa avere, perché nessuno lo ricontrolla a mano.
 */

use App\Actions\Condominio\CreaCondominioDimostrativoAction;
use App\Models\Esercizio;
use App\Models\Gestionale\FascicoloBilancio;
use App\Services\Fascicolo\FascicoloBilancioService;
use App\Services\Fascicolo\TestoNota;
use App\Services\Gestionale\StatoPatrimonialePaginaService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Stessi dati di riferimento e stesso utente del condominio dimostrativo: vedi CondominioDimostrativoTest.
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

    $utente = \App\Models\User::factory()->create();
    $utente->assignRole($ruolo);
    $this->actingAs($utente);
});

/** @return array{0: \App\Models\Condominio, 1: Esercizio} */
function condominioDelFascicolo(): array
{
    $condominio = app(CreaCondominioDimostrativoAction::class)->esegui()['condominio'];

    return [$condominio, Esercizio::where('condominio_id', $condominio->id)->orderBy('id')->firstOrFail()];
}

it('⚠️ non ha numeri suoi: ogni totale è quello dello stato patrimoniale e del registro', function () {
    [$condominio, $esercizio] = condominioDelFascicolo();

    $fascicolo = app(FascicoloBilancioService::class)->costruisci($condominio, $esercizio);
    $pagina = app(StatoPatrimonialePaginaService::class)->costruisci($condominio, $esercizio);

    expect($fascicolo['tipo'])->toBe('consuntivo')
        ->and(array_column($fascicolo['sezioni'], 'chiave'))->toContain('nota', 'spese', 'condomini', 'patrimoniale', 'fatture', 'registro');

    $dati = $fascicolo['dati'];

    // Quello che i condòmini devono ancora versare è il credito verso i condòmini dello stato patrimoniale.
    expect($dati['condomini']['totale']['saldo'])->toBe($pagina['sintesi']['crediti_condomini'])
        // Lo speso ancora da pagare, sommato sulle voci, è il residuo delle fatture: al centesimo.
        ->and($dati['spese']['totale']['da_pagare'])->toBe($pagina['debiti']['totale_residui'])
        ->and($dati['fatture']['totale'])->toBe($pagina['debiti']['totale_residui'])
        // Il registro finisce sul saldo delle casse.
        ->and($dati['registro']['finale'])->toBe($pagina['riepilogo']['totale']['finale'])
        ->and($dati['registro']['iniziale'] + $dati['registro']['entrate'] - $dati['registro']['uscite'])->toBe($dati['registro']['finale'])
        // E lo stato patrimoniale stampato quadra: attività + spese da conguagliare = passività + entrate.
        ->and($dati['patrimoniale']['attivo']['totale'] + $dati['patrimoniale']['costi'])
        ->toBe($dati['patrimoniale']['passivo']['totale'] + $dati['patrimoniale']['ricavi']);
});

it('il rendiconto delle spese somma le voci: preventivo, speso e da pagare tornano con i totali', function () {
    [$condominio, $esercizio] = condominioDelFascicolo();

    $spese = app(FascicoloBilancioService::class)->costruisci($condominio, $esercizio)['dati']['spese'];

    expect($spese['gestioni'])->not->toBeEmpty();

    foreach ($spese['gestioni'] as $gestione) {
        foreach (['preventivo', 'speso', 'da_pagare'] as $colonna) {
            $somma = 0;
            foreach ($gestione['gruppi'] as $gruppo) {
                $somma += array_sum(array_column($gruppo['voci'], $colonna));
            }
            expect($somma)->toBe($gestione['totale'][$colonna], "Gestione «{$gestione['nome']}», colonna {$colonna}");
        }
    }
});

it('stampa un PDF, anche in bozza', function () {
    [$condominio, $esercizio] = condominioDelFascicolo();

    $risposta = $this->get(route('admin.gestionale.esercizi.fascicolo-bilancio.print', ['condominio' => $condominio->id, 'esercizio' => $esercizio->id]).'?bozza=1');

    $risposta->assertOk();
    expect($risposta->headers->get('Content-Type'))->toBe('application/pdf')
        ->and(substr($risposta->getContent(), 0, 5))->toBe('%PDF-')
        ->and($risposta->headers->get('Content-Disposition'))->toContain('bozza-fascicolo-bilancio');
});

it('salva la nota, il titolo e le sezioni scelte, e il fascicolo li usa', function () {
    [$condominio, $esercizio] = condominioDelFascicolo();
    $parametri = ['condominio' => $condominio->id, 'esercizio' => $esercizio->id];

    $this->put(route('admin.gestionale.esercizi.fascicolo-bilancio.update', $parametri), [
        'tipo' => 'consuntivo',
        'titolo' => 'Rendiconto 2026',
        'nota' => "Gentili Condòmini,\n\n# I. Il conto corrente\nTutte le somme sono transitate sul conto.",
        'sezioni' => ['nota', 'spese', 'registro'],
    ])->assertRedirect();

    $salvato = FascicoloBilancio::where('esercizio_id', $esercizio->id)->firstOrFail();
    $fascicolo = app(FascicoloBilancioService::class)->costruisci($condominio, $esercizio, $salvato);

    expect(array_column($fascicolo['sezioni'], 'chiave'))->toBe(['nota', 'spese', 'registro'])
        ->and($fascicolo['copertina']['titoli'])->toBe(['Rendiconto 2026'])
        ->and($fascicolo['dati']['nota']['testo'])->toContain('Il conto corrente');

    // Una sezione che non esiste non si salva.
    $this->put(route('admin.gestionale.esercizi.fascicolo-bilancio.update', $parametri), ['sezioni' => ['inventata']])
        ->assertSessionHasErrors('sezioni.0');

    // La pagina si apre e dice che cosa uscirebbe.
    $this->get(route('admin.gestionale.esercizi.fascicolo-bilancio.index', $parametri))
        ->assertOk()
        ->assertInertia(fn ($pagina) => $pagina
            ->component('gestionale/movimenti/fascicoloBilancio/Show')
            ->where('fascicolo.titolo', 'Rendiconto 2026')
            ->where('anteprima.tipo', 'consuntivo')
            ->has('anteprima.sezioni', 3));
});

it('un esercizio senza movimenti dà un fascicolo preventivo; con i movimenti, consuntivo', function () {
    [$condominio, $esercizio] = condominioDelFascicolo();
    $servizio = app(FascicoloBilancioService::class);

    $nuovo = Esercizio::create([
        'condominio_id' => $condominio->id,
        'nome' => 'Esercizio successivo',
        'data_inizio' => $esercizio->data_fine->copy()->addDay(),
        'data_fine' => $esercizio->data_fine->copy()->addYear(),
        'stato' => 'aperto',
    ]);

    expect($servizio->tipo($esercizio))->toBe('consuntivo')
        ->and($servizio->tipo($nuovo))->toBe('preventivo')
        // La scelta dello studio vince sempre.
        ->and($servizio->tipo($nuovo, 'consuntivo'))->toBe('consuntivo');
});

it('la sezione senza dati non compare: un titolo sopra una tabella vuota sembra un errore', function () {
    [$condominio, $esercizio] = condominioDelFascicolo();

    // Nessun esercizio successivo: preventivo e rate dell'anno dopo non hanno niente da dire.
    $chiavi = array_column(app(FascicoloBilancioService::class)->costruisci($condominio, $esercizio)['sezioni'], 'chiave');

    expect($chiavi)->not->toContain('preventivo')->not->toContain('rate');
});

it('la nota diventa testo impaginato, e l\'HTML scritto dentro non passa', function () {
    $html = TestoNota::html("Gentili Condòmini,\nseconda riga.\n\n# I. Il conto\n- prima voce\n- **seconda** voce\n\n1. uno\n2. due\n\n<script>alert(1)</script>");

    expect($html)->toContain('<p>Gentili Condòmini, seconda riga.</p>')
        ->toContain('<h3>I. Il conto</h3>')
        ->toContain('<ul><li>prima voce</li><li><b>seconda</b> voce</li></ul>')
        ->toContain('<ol><li>uno</li><li>due</li></ol>')
        ->not->toContain('<script>')
        ->toContain('&lt;script&gt;');
});

it('i colori della copertina seguono l\'aspetto scelto dallo studio', function () {
    $turchese = FascicoloBilancioService::colori('turchese');
    $grafite = FascicoloBilancioService::colori('grafite');

    expect($turchese['primario'])->toBe('#0fb3ad')
        ->and($grafite['primario'])->not->toBe('#171717')   // il quasi nero dello schermo non va su una copertina
        ->and($turchese['testo_copertina'])->not->toBe('#ffffff');
});
