<?php

/**
 * La banca dello stato patrimoniale e quella del registro di contabilità, quando si accantona in un fondo (Web Pro Italia, 15/11/2026).
 *
 * Un giroconto banca → fondo scrive AVERE sul conto della banca e DARE su quello del fondo: lo stato patrimoniale e il mastrino del conto
 * corrente leggono i conti, e la banca scende; il registro di contabilità lascia fuori la riga della banca di un giroconto verso un fondo
 * (è la banca che parla con sé stessa), e lì il conto resta intero, come nell'estratto conto. Il calcolo è voluto e non cambia: la riga
 * della banca dice quanto è nei fondi e quanto c'è sul conto intero, a schermo e in stampa.
 *
 * I test con ⚠️ falliscono senza la correzione; la controprova è verde prima e dopo.
 *
 * Cosa resta scoperto: il mastrino del conto corrente, che resta com'era (è il dettaglio della riga dello stato patrimoniale, e la riga
 * ora lo spiega); un giroconto da un fondo verso una banca diversa da quella da cui era partito, che la riga dice ma qui non si prova.
 */

use App\Actions\Gestionale\Movimenti\RegistraGirocontoAction;
use App\Models\Gestionale\Cassa;
use App\Services\Gestionale\RegistroContabilitaService;
use App\Services\Gestionale\StatoPatrimonialePaginaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;

require_once __DIR__.'/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-06-30 12:00:00');
    $this->actingAs(\App\Models\User::factory()->create());
});

afterEach(function () {
    Carbon::setTestNow();
});

/** Una cassa con il suo conto di liquidità, come la crea il programma (ogni cassa, fondo compreso, è un conto figlio della liquidità). */
function bfsCassa(int $condominioId, string $tipo, string $nome): Cassa
{
    $contoId = DB::table('conti_contabili')->insertGetId(['condominio_id' => $condominioId, 'ruolo' => $tipo === 'fondo' ? 'fondo_riserva' : 'conto_bancario',
        'codice' => '1010.'.$nome, 'nome' => $nome, 'tipo' => 'attivo', 'categoria' => 'liquidita', 'created_at' => now(), 'updated_at' => now()]);

    return Cassa::create(['condominio_id' => $condominioId, 'nome' => $nome, 'tipo' => $tipo, 'conto_contabile_id' => $contoId, 'saldo_iniziale' => 0,
        'attiva' => true, 'sottotipo_fondo' => $tipo === 'fondo' ? 'vincolato_lavori' : null]);
}

/** € 10.000,00 sul conto all'apertura dell'esercizio, a giornale; poi € 2.000,00 accantonati nel fondo lavori con un giroconto. */
function bfsScenario(): array
{
    $ctx = setupContabile();
    [$condominio, $esercizio, $gestione] = $ctx;
    $banca = bfsCassa($condominio->id, 'banca', 'Conto corrente BPER');
    $fondo = bfsCassa($condominio->id, 'fondo', 'Fondo lavori facciata');
    $altra = bfsCassa($condominio->id, 'banca', 'Conto postale');

    $passate = DB::table('conti_contabili')->where('condominio_id', $condominio->id)->where('ruolo', 'passate_gestioni')->value('id');
    foreach ([[$banca, 1000000], [$altra, 50000]] as [$cassa, $importo]) {
        $sc = DB::table('scritture_contabili')->insertGetId(['condominio_id' => $condominio->id, 'esercizio_id' => $esercizio->id, 'gestione_id' => $gestione->id,
            'data_registrazione' => '2026-01-01', 'data_competenza' => '2026-01-01', 'numero_protocollo' => 'AP-'.$cassa->id, 'causale' => 'Apertura '.$cassa->nome,
            'tipo_movimento' => 'apertura', 'stato' => 'registrata', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('righe_scritture')->insert([
            ['scrittura_id' => $sc, 'conto_contabile_id' => $cassa->conto_contabile_id, 'cassa_id' => $cassa->id, 'tipo_riga' => 'dare', 'importo' => $importo, 'created_at' => now(), 'updated_at' => now()],
            ['scrittura_id' => $sc, 'conto_contabile_id' => $passate, 'cassa_id' => null, 'tipo_riga' => 'avere', 'importo' => $importo, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    app(RegistraGirocontoAction::class)->execute([
        'cassa_origine_id' => $banca->id, 'cassa_destinazione_id' => $fondo->id, 'importo' => 2000, 'data_operazione' => '2026-03-10',
        'causale' => 'Accantonamento per la facciata', 'gestione_id' => $gestione->id, 'esercizio_id' => $esercizio->id,
    ], $condominio, $esercizio);

    return compact('condominio', 'esercizio', 'banca', 'fondo', 'altra');
}

function bfsVoce(array $pagina, int $contoId): array
{
    return collect($pagina['situazione']['attivo']['gruppi'])->flatMap(fn ($g) => $g['voci'])->firstWhere('id', $contoId);
}

const BFS_NOTA = 'Sul conto ci sono € 10.000,00, di cui € 2.000,00 accantonati nel fondo «Fondo lavori facciata»: questa riga è la parte libera, e il fondo ha la sua riga fra la liquidità. L\'estratto conto della banca e il registro di contabilità, che non conta un accantonamento come uscita, mostrano il conto intero.';

it('controprova — il calcolo non cambia: la banca dello stato patrimoniale scende dell\'accantonamento, il registro no', function () {
    $s = bfsScenario();

    $pagina = app(StatoPatrimonialePaginaService::class)->costruisci($s['condominio'], $s['esercizio']);
    $registro = collect(app(RegistroContabilitaService::class)->registroConRiepilogo($s['esercizio'])['saldi_per_cassa'])->keyBy('cassa');

    expect(bfsVoce($pagina, $s['banca']->conto_contabile_id)['saldo'])->toBe(800000)
        ->and(bfsVoce($pagina, $s['fondo']->conto_contabile_id)['saldo'])->toBe(200000)
        ->and($registro['Conto corrente BPER']['saldo'])->toBe(1000000)
        ->and($pagina['situazione']['quadra'])->toBeTrue();
});

it('⚠️ la riga della banca dice quanto è accantonato nel fondo e quanto c\'è sul conto intero, che è il saldo del registro', function () {
    $s = bfsScenario();

    $pagina = app(StatoPatrimonialePaginaService::class)->costruisci($s['condominio'], $s['esercizio']);
    $banca = bfsVoce($pagina, $s['banca']->conto_contabile_id);
    $registro = collect(app(RegistroContabilitaService::class)->registroConRiepilogo($s['esercizio'])['saldi_per_cassa'])->keyBy('cassa');

    expect($banca['accantonato'])->toBe([
        'totale' => 200000,
        'fondi' => [['fondo' => 'Fondo lavori facciata', 'tipo' => 'fondo', 'importo' => 200000]],
        'sul_conto' => 1000000,
        'nota' => BFS_NOTA,
    ])
        ->and($banca['accantonato']['sul_conto'])->toBe($registro['Conto corrente BPER']['saldo'])
        // Il fondo e la banca senza accantonamenti non hanno la riga.
        ->and(bfsVoce($pagina, $s['fondo']->conto_contabile_id)['accantonato'])->toBeNull()
        ->and(bfsVoce($pagina, $s['altra']->conto_contabile_id)['accantonato'])->toBeNull();
});

it('⚠️ la pagina e la stampa dello stato patrimoniale portano la riga sotto la banca', function () {
    $s = bfsScenario();
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $ruolo = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'amministratore', 'guard_name' => 'web']);
    $ruolo->givePermissionTo(\Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']));
    $utente = \App\Models\User::factory()->create();
    $utente->assignRole($ruolo);

    $props = $this->actingAs($utente)->get(route('admin.gestionale.esercizi.stato-patrimoniale.index', [$s['condominio'], $s['esercizio']]))
        ->assertOk()->viewData('page')['props'];
    expect(bfsVoce($props['pagina'], $s['banca']->conto_contabile_id)['accantonato']['nota'])->toBe(BFS_NOTA);

    $catturati = [];
    View::composer('pdf.gestionale.stato_patrimoniale', function ($view) use (&$catturati) {
        $catturati = $view->getData();
    });
    $this->actingAs($utente)->get(route('admin.gestionale.esercizi.stato-patrimoniale.print', [$s['condominio'], $s['esercizio']]))->assertOk();
    expect(view('pdf.gestionale.stato_patrimoniale', $catturati)->render())->toContain(e(BFS_NOTA));
});

it('⚠️ con due fondi la riga li nomina tutti e due, ciascuno con la sua cifra', function () {
    $s = bfsScenario();
    $tetto = bfsCassa($s['condominio']->id, 'fondo', 'Fondo tetto');
    app(RegistraGirocontoAction::class)->execute([
        'cassa_origine_id' => $s['banca']->id, 'cassa_destinazione_id' => $tetto->id, 'importo' => 500, 'data_operazione' => '2026-04-10',
        'causale' => 'Accantonamento per il tetto', 'gestione_id' => DB::table('gestioni')->where('condominio_id', $s['condominio']->id)->value('id'), 'esercizio_id' => $s['esercizio']->id,
    ], $s['condominio'], $s['esercizio']);

    $banca = bfsVoce(app(StatoPatrimonialePaginaService::class)->costruisci($s['condominio'], $s['esercizio']), $s['banca']->conto_contabile_id);

    expect($banca['accantonato']['nota'])->toBe('Sul conto ci sono € 10.000,00, di cui € 2.500,00 accantonati nel fondo «Fondo lavori facciata» (€ 2.000,00) e nel fondo «Fondo tetto» (€ 500,00): questa riga è la parte libera, e ognuno ha la sua riga fra la liquidità. L\'estratto conto della banca e il registro di contabilità, che non conta un accantonamento come uscita, mostrano il conto intero.');
});
