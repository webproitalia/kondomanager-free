<?php

/**
 * Due piani rate sulla stessa voce non la fanno pagare due volte (Web Pro Italia, 15/11/2026).
 *
 * Lo studio divide le voci del preventivo fra due piani quando le scadenze sono irregolari (un acconto e un saldo): ogni piano prende un
 * pezzo di ogni voce. Fino a oggi ogni piano guardava solo sé stesso: un secondo piano poteva chiedere di nuovo la voce intera — anche
 * dalla «Selezione rapida», che a una voce già coperta copiava l'importo intero — e i condòmini la pagavano due volte. La regola: per
 * ogni voce, la somma di tutti i piani validi della stessa gestione e dello stesso esercizio non supera il fabbisogno (`VociFraPiuPiani`).
 *
 * Il condominio è quello di `Beta52TotaliScenari`: «Spese generali» € 1.200,00 sulla tabella «Proprietà» (Ugo e Bice, 500 millesimi
 * ciascuno) e «Proventi antenna» sulla tabella «Box». I piani si creano dalla rotta della pagina, come li crea lo studio.
 *
 * I test con ⚠️ falliscono senza la correzione; le controprove sono verdi prima e dopo, e dicono che cosa la regola non deve bloccare.
 *
 * Cosa resta scoperto: «Sposta spesa» fra due piani (lo spostamento resta dentro un piano, e non si controlla); i piani straordinari, che
 * non hanno voci ma fatture; un piano nuovo accanto a un piano già emesso (sopra la 1.12, 15/11/2026: la 1.12 ci passa per rifare un piano
 * emesso e per una spesa in più sulla stessa voce, e il programma non sa distinguerli da un saldo scritto per intero accanto a un acconto
 * emesso; non si ferma, e la pagina del piano lo dice in rosso).
 */

use App\Models\Esercizio;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/GestionaleTestHelpers.php';
require_once __DIR__.'/Beta52TotaliScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = b52tAdmin();
});

/** Un piano dalla rotta di creazione, con le voci e gli importi dati (vuoto = la voce intera). Torna la risposta. */
function vfpCrea($test, array $s, string $nome, array $voci, ?Esercizio $esercizio = null, array $extra = [])
{
    return $test->actingAs($test->user)->from('/crea')->post(route('admin.gestionale.esercizi.piani-rate.store', [$s['c'], $esercizio ?? $s['e']]), array_merge([
        'tipo' => 'ordinario', 'gestione_id' => $s['g']->id, 'nome' => $nome, 'metodo_distribuzione' => 'prima_rata',
        'numero_rate' => 4, 'giorno_scadenza' => 5, 'data_prima_scadenza' => ($esercizio ?? $s['e'])->data_inizio->format('Y').'-01-05', 'genera_subito' => true,
        'capitoli_ids' => [], 'capitoli_config' => array_map(fn ($v) => ['id' => $v[0]->id, 'importo' => $v[1]], $voci),
        'competenze_capitoli' => [], 'accetta_destinatari' => true, 'nota_destinatari' => 'Piano di prova',
    ], $extra));
}

function vfpMessaggio($risposta): ?string
{
    return session('message')['message'] ?? null;
}

/** Quanto le quote del piano chiedono in tutto, escluse quelle della rata dei saldi. */
function vfpChiesto(PianoRate $piano): int
{
    return b52tQuoteDelMotore($piano);
}

it('controprova — acconto e saldo che insieme fanno il preventivo si creano tutti e due, e ognuno chiede la sua parte', function () {
    $s = b52tScenario($this);

    vfpCrea($this, $s, 'Acconto 2026', [[$s['spesa'], '700,00']])->assertSessionHasNoErrors();
    vfpCrea($this, $s, 'Saldo 2026', [[$s['spesa'], '500,00']])->assertSessionHasNoErrors();

    $acconto = PianoRate::where('nome', 'Acconto 2026')->firstOrFail();
    $saldo = PianoRate::where('nome', 'Saldo 2026')->firstOrFail();
    expect(vfpChiesto($acconto))->toBe(70000)->and(vfpChiesto($saldo))->toBe(50000);
});

it('⚠️ un secondo piano che con il primo supera il preventivo della voce non si crea, e il rifiuto dice voce, preventivo, l\'altro piano e quanto chiede questo', function () {
    $s = b52tScenario($this);
    vfpCrea($this, $s, 'Acconto 2026', [[$s['spesa'], '700,00']])->assertSessionHasNoErrors();

    $r = vfpCrea($this, $s, 'Saldo 2026', [[$s['spesa'], '600,00']]);

    $r->assertRedirect('/crea')->assertSessionHasErrors('capitoli.'.$s['spesa']->id);
    expect(PianoRate::where('nome', 'Saldo 2026')->exists())->toBeFalse('il piano non doveva nascere')
        ->and(vfpMessaggio($r))->toBe('Il piano «Saldo 2026» non si crea: una sua voce, insieme agli altri piani, supererebbe il fabbisogno, e ai condòmini si chiederebbe due volte la stessa spesa (contano i piani validi della stessa gestione nell\'esercizio «Esercizio 2026», in bozza o approvati, non ancora emessi). «Spese generali»: preventivo € 1.200,00; un altro piano le dà già € 700,00 («Acconto 2026» € 700,00); questo piano chiede € 600,00: in tutto € 1.300,00, € 100,00 oltre. Qui al massimo € 500,00. Per dividere una voce fra più piani scrivi in ognuno solo la sua parte; se invece la voce costa di più, alza prima il suo preventivo nel piano dei conti. Poi rimanda il modulo.')
        ->and(session('errors')->get('capitoli.'.$s['spesa']->id)[0])->toStartWith('«Spese generali»: preventivo € 1.200,00; un altro piano le dà già € 700,00');
});

it('⚠️ la «Selezione rapida» di una voce che un altro piano copre già tutta non le copia di nuovo l\'importo intero', function () {
    $s = b52tScenario($this);
    vfpCrea($this, $s, 'Preventivo 2026', [[$s['spesa'], '']])->assertSessionHasNoErrors();

    $r = vfpCrea($this, $s, 'Rata integrativa', [], extra: ['capitoli_ids' => [$s['spesa']->id], 'capitoli_config' => []]);

    $r->assertSessionHasErrors('capitoli.'.$s['spesa']->id);
    expect(PianoRate::where('nome', 'Rata integrativa')->exists())->toBeFalse()
        ->and(vfpMessaggio($r))->toContain('«Spese generali»: preventivo € 1.200,00; un altro piano le dà già € 1.200,00 («Preventivo 2026» € 1.200,00); questo piano chiede € 1.200,00: in tutto € 2.400,00, € 1.200,00 oltre. Qui niente: gli altri piani la coprono già tutta.');
});

it('⚠️ un secondo piano con l\'importo vuoto («tutta la voce») accanto a un acconto non si crea', function () {
    $s = b52tScenario($this);
    vfpCrea($this, $s, 'Acconto 2026', [[$s['spesa'], '300,00']])->assertSessionHasNoErrors();

    $r = vfpCrea($this, $s, 'Saldo 2026', [[$s['spesa'], '']]);

    $r->assertSessionHasErrors('capitoli.'.$s['spesa']->id);
    expect(vfpMessaggio($r))->toContain('questo piano chiede € 1.200,00: in tutto € 1.500,00, € 300,00 oltre. Qui al massimo € 900,00.');
});

it('⚠️ un capitolo in un piano e un suo sottoconto in un altro sono la stessa spesa', function () {
    $s = b52tScenario($this);
    $padre = b52tVoce($this, $s, 'Manutenzioni', 'spesa', '0,00', $s['tabella'], null, ['isCapitolo' => true, 'tabella_millesimale_id' => null, 'percentuale_proprietario' => null, 'percentuale_inquilino' => null, 'percentuale_usufruttuario' => null]);
    $ascensore = b52tVoce($this, $s, 'Ascensore', 'spesa', '600,00', $s['tabella'], $padre->id, ['isSottoConto' => true]);
    b52tVoce($this, $s, 'Caldaia', 'spesa', '400,00', $s['tabella'], $padre->id, ['isSottoConto' => true]);
    vfpCrea($this, $s, 'Manutenzioni intere', [[$padre, '']])->assertSessionHasNoErrors();

    $r = vfpCrea($this, $s, 'Ascensore a parte', [[$ascensore, '200,00']]);

    $r->assertSessionHasErrors('capitoli.'.$ascensore->id);
    expect(vfpMessaggio($r))->toContain('«Manutenzioni > Ascensore»: preventivo € 600,00; un altro piano le dà già € 600,00 («Manutenzioni intere» € 600,00); questo piano chiede € 200,00');
});

it('⚠️ un piano da solo che chiede a una voce più del preventivo si crea come prima; un secondo piano su quella voce no', function () {
    $s = b52tScenario($this);

    // Il programma lo permette da sempre e la pagina del piano lo dice («Parziale»: il piano chiede … più del preventivo).
    vfpCrea($this, $s, 'Preventivo largo', [[$s['spesa'], '1.500,00']])->assertSessionHasNoErrors();
    expect(vfpChiesto(PianoRate::where('nome', 'Preventivo largo')->firstOrFail()))->toBe(150000);

    $r = vfpCrea($this, $s, 'Rata in più', [[$s['spesa'], '100,00']]);
    $r->assertSessionHasErrors('capitoli.'.$s['spesa']->id);
    expect(vfpMessaggio($r))->toContain('«Spese generali»: preventivo € 1.200,00; un altro piano le dà già € 1.500,00 («Preventivo largo» € 1.500,00); questo piano chiede € 100,00: in tutto € 1.600,00, € 400,00 oltre. Qui niente: gli altri piani la coprono già tutta.');
});

it('⚠️ le fatture oltre il preventivo alzano il tetto: il piano integrativo per lo sforo si crea, un centesimo in più no', function () {
    $s = b52tScenario($this);
    vfpCrea($this, $s, 'Preventivo 2026', [[$s['spesa'], '']])->assertSessionHasNoErrors();
    // € 1.500,00 di spesa registrata sulla voce, € 300,00 oltre il preventivo: lo «sforo da recuperare» del modulo di creazione.
    $sc = DB::table('scritture_contabili')->insertGetId(['condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'gestione_id' => $s['g']->id,
        'data_registrazione' => '2026-03-01', 'data_competenza' => '2026-03-01', 'numero_protocollo' => 'VFP-1', 'causale' => 'Fattura', 'tipo_movimento' => 'fattura',
        'stato' => 'registrata', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('righe_scritture')->insert(['scrittura_id' => $sc, 'conto_contabile_id' => $s['cassa']->conto_contabile_id, 'voce_spesa_id' => $s['spesa']->id,
        'tipo_riga' => 'dare', 'importo' => 150000, 'created_at' => now(), 'updated_at' => now()]);

    vfpCrea($this, $s, 'Integrativo sforo', [[$s['spesa'], '300,00']])->assertSessionHasNoErrors();
    expect(vfpCrea($this, $s, 'Integrativo di troppo', [[$s['spesa'], '0,01']])->getSession()->get('message')['message'] ?? '')
        ->toContain('«Spese generali»: fabbisogno € 1.500,00 (preventivo € 1.200,00, fatture registrate € 1.500,00)');
});

it('controprova — il piano dell\'esercizio dopo, sulla stessa gestione, chiede di nuovo la voce intera', function () {
    $s = b52tScenario($this);
    vfpCrea($this, $s, 'Preventivo 2026', [[$s['spesa'], '']])->assertSessionHasNoErrors();
    $e27 = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2027', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31', 'stato' => 'aperto']);
    app(\App\Services\CondominioService::class)->createDefaultGestione($s['c'], $e27);

    vfpCrea($this, $s, 'Preventivo 2027', [[$s['spesa'], '']], $e27)->assertSessionHasNoErrors();

    expect(PianoRate::where('nome', 'Preventivo 2027')->exists())->toBeTrue();
});

it('controprova — un piano non attivo non tiene la voce', function () {
    $s = b52tScenario($this);
    vfpCrea($this, $s, 'Vecchio', [[$s['spesa'], '']])->assertSessionHasNoErrors();
    PianoRate::where('nome', 'Vecchio')->update(['attivo' => false]);

    vfpCrea($this, $s, 'Nuovo', [[$s['spesa'], '']])->assertSessionHasNoErrors();
});

it('controprova — accanto a un piano già emesso un piano nuovo sulla stessa voce si crea, e la sua pagina dice in rosso la somma; fra i piani non emessi la regola resta', function () {
    $s = b52tScenario($this);
    b52tSqliteJson();
    vfpCrea($this, $s, 'Preventivo 2026', [[$s['spesa'], '']])->assertSessionHasNoErrors();
    $preventivo = PianoRate::where('nome', 'Preventivo 2026')->firstOrFail();
    // Approvato ed emesso: la 1.12 non lo ricalcola più né gli toglie la voce, e per cambiarlo, o per una spesa in più sulla voce, gli mette
    // accanto un piano nuovo (`RiepilogoPerCompetenzaTest`, «Pulizie straordinarie dell'ordinaria» in `DeliberatoRicostruitoTest`).
    $this->actingAs($this->user)->put(route('admin.gestionale.piani-rate.update-stato', [$s['c'], $s['e'], $preventivo]), ['approvato' => true, 'data_delibera_assemblea' => '2026-01-02'])
        ->assertSessionHasNoErrors();
    $this->actingAs($this->user)->post(route('admin.gestionale.piani-rate.emetti', [$s['c'], $preventivo]), [
        'rate_ids' => $preventivo->rate()->pluck('id')->all(), 'data_emissione' => '2026-01-31', 'invia_notifiche' => false])->assertSessionHasNoErrors();
    expect($preventivo->fresh()->haRateEmesse())->toBeTrue();

    vfpCrea($this, $s, 'Spese generali in più', [[$s['spesa'], '1.200,00']])->assertSessionHasNoErrors();

    $nuovo = PianoRate::where('nome', 'Spese generali in più')->firstOrFail();
    $pagina = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.piani-rate.show', [$s['c'], $s['e'], $nuovo]))->assertOk()->viewData('page')['props'];
    expect(collect($pagina['pianoRate']['capitoli'])->firstWhere('id', $s['spesa']->id)['copertura']['frase'])
        ->toBe('Anche nel piano «Preventivo 2026» (€ 1.200,00). In tutto € 2.400,00 su un preventivo di € 1.200,00: € 1.200,00 oltre, cioè chiesti due volte ai condòmini.');

    // Un terzo piano accanto al secondo, che non è emesso: la somma dei due supera il preventivo, e si ferma.
    $r = vfpCrea($this, $s, 'Rata in più', [[$s['spesa'], '100,00']]);
    $r->assertSessionHasErrors('capitoli.'.$s['spesa']->id);
    expect(vfpMessaggio($r))->toContain('«Spese generali»: preventivo € 1.200,00; un altro piano le dà già € 1.200,00 («Spese generali in più» € 1.200,00); questo piano chiede € 100,00');
});

it('⚠️ il ricalcolo di due piani che si sovrappongono già si ferma e dice come uscirne; togliere la voce da uno dei due resta possibile', function () {
    $s = b52tScenario($this);
    vfpCrea($this, $s, 'Acconto 2026', [[$s['spesa'], '700,00'], [$s['entrata'], '']])->assertSessionHasNoErrors();
    vfpCrea($this, $s, 'Saldo 2026', [[$s['spesa'], '500,00']])->assertSessionHasNoErrors();
    $acconto = PianoRate::where('nome', 'Acconto 2026')->firstOrFail();
    $saldo = PianoRate::where('nome', 'Saldo 2026')->firstOrFail();
    // Il dato di prima della correzione: il saldo che chiede la voce intera.
    DB::table('piano_rate_capitoli')->where('piano_rate_id', $saldo->id)->where('conto_id', $s['spesa']->id)->update(['importo' => null]);
    $rate = $acconto->rate()->pluck('id')->all();

    $r = $this->actingAs($this->user)->from('/piano')->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$s['c'], $s['e'], $acconto]), [
        'accetta_destinatari' => true, 'nota_destinatari' => 'Piano di prova']);

    $r->assertRedirect('/piano')->assertSessionHasErrors('capitoli.'.$s['spesa']->id);
    expect(vfpMessaggio($r))->toContain('Il piano «Acconto 2026» non si ricalcola: una sua voce, insieme agli altri piani, supererebbe il fabbisogno')
        ->toContain('«Spese generali»: preventivo € 1.200,00; un altro piano le dà già € 1.200,00 («Saldo 2026» € 1.200,00); questo piano chiede € 700,00: in tutto € 1.900,00, € 700,00 oltre.')
        ->toContain('Togli la voce dal piano che non la deve chiedere (il cestino accanto alla voce, nella pagina del piano)')
        ->and($acconto->rate()->pluck('id')->all())->toBe($rate, 'il ricalcolo rifiutato non tocca le rate');

    $this->actingAs($this->user)->from('/piano')->delete(route('admin.gestionale.piani-rate.capitoli.detach', [$s['c'], $s['e'], $acconto, $s['spesa']->id]), [
        'accetta_destinatari' => true, 'nota_destinatari' => 'Piano di prova'])->assertSessionHasNoErrors();
    expect(session('message')['type'] ?? null)->toBe('success');
    $this->actingAs($this->user)->from('/piano')->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$s['c'], $s['e'], $saldo]), [
        'accetta_destinatari' => true, 'nota_destinatari' => 'Piano di prova'])->assertSessionHasNoErrors();
});

it('controprova — il ricalcolo di un piano solo tiene l\'importo che ha copiato anche se il preventivo è sceso dopo', function () {
    $s = b52tScenario($this);
    vfpCrea($this, $s, 'Preventivo 2026', [[$s['spesa'], '1.200,00']])->assertSessionHasNoErrors();
    $piano = PianoRate::where('nome', 'Preventivo 2026')->firstOrFail();
    Conto::whereKey($s['spesa']->id)->update(['importo' => 100000]);

    $this->actingAs($this->user)->from('/piano')->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$s['c'], $s['e'], $piano]), [
        'accetta_destinatari' => true, 'nota_destinatari' => 'Piano di prova'])->assertSessionHasNoErrors();
    expect(vfpChiesto($piano->fresh()))->toBe(120000);
});

it('controprova — «Sposta spesa» dentro un piano diviso non ferma il ricalcolo', function () {
    $s = b52tScenario($this);
    $pulizie = b52tVoce($this, $s, 'Pulizie', 'spesa', '400,00', $s['tabella']);
    vfpCrea($this, $s, 'Acconto 2026', [[$s['spesa'], '600,00'], [$pulizie, '200,00']])->assertSessionHasNoErrors();
    vfpCrea($this, $s, 'Saldo 2026', [[$s['spesa'], '600,00'], [$pulizie, '200,00']])->assertSessionHasNoErrors();
    $saldo = PianoRate::where('nome', 'Saldo 2026')->firstOrFail();

    // € 100,00 dalle spese generali alle pulizie, nel saldo: le pulizie ricevono più del loro preventivo, il piano chiede lo stesso totale.
    $this->actingAs($this->user)->post(route('admin.gestionale.piani-rate.move-budget', [$s['c'], $saldo]), [
        'source_id' => $s['spesa']->id, 'destination_id' => $pulizie->id, 'amount' => 100, 'reason' => 'Pulizie straordinarie dopo i lavori',
    ])->assertSessionHasNoErrors();

    $this->actingAs($this->user)->from('/piano')->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$s['c'], $s['e'], $saldo]), [
        'accetta_destinatari' => true, 'nota_destinatari' => 'Piano di prova'])->assertSessionHasNoErrors();
    expect(vfpChiesto($saldo->fresh()))->toBe(80000);
});

it('⚠️ la pagina del piano dice, sotto la voce divisa, gli altri piani, la somma e se torna', function () {
    $s = b52tScenario($this);
    b52tSqliteJson();
    vfpCrea($this, $s, 'Acconto 2026', [[$s['spesa'], '700,00']])->assertSessionHasNoErrors();
    vfpCrea($this, $s, 'Saldo 2026', [[$s['spesa'], '500,00']])->assertSessionHasNoErrors();
    $saldo = PianoRate::where('nome', 'Saldo 2026')->firstOrFail();

    $pagina = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.piani-rate.show', [$s['c'], $s['e'], $saldo]))
        ->assertOk()->viewData('page')['props'];
    $voce = collect($pagina['pianoRate']['capitoli'])->firstWhere('id', $s['spesa']->id);

    expect($voce['copertura']['frase'])->toBe('Anche nel piano «Acconto 2026» (€ 700,00). In tutto € 1.200,00 su un preventivo di € 1.200,00: la somma torna.')
        ->and($voce['copertura']['esito'])->toBe('torna')
        ->and($voce['copertura']['altri_piani'])->toBe([['id' => PianoRate::where('nome', 'Acconto 2026')->value('id'), 'nome' => 'Acconto 2026', 'stato' => 'bozza', 'importo' => 70000]]);

    // Un dato di prima della correzione: l'acconto che chiede la voce intera. La riga lo dice in rosso.
    DB::table('piano_rate_capitoli')->where('conto_id', $s['spesa']->id)->where('piano_rate_id', '!=', $saldo->id)->update(['importo' => null]);
    $pagina = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.piani-rate.show', [$s['c'], $s['e'], $saldo]))->viewData('page')['props'];
    $voce = collect($pagina['pianoRate']['capitoli'])->firstWhere('id', $s['spesa']->id);
    expect($voce['copertura']['frase'])->toBe('Anche nel piano «Acconto 2026» (€ 1.200,00). In tutto € 1.700,00 su un preventivo di € 1.200,00: € 500,00 oltre, cioè chiesti due volte ai condòmini.')
        ->and(collect($pagina['pianoRate']['capitoli'])->firstWhere('id', $s['entrata']->id)['copertura'] ?? null)->toBeNull();
});
