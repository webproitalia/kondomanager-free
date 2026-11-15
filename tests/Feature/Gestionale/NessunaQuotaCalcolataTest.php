<?php

/**
 * «Nessuna quota calcolata» dice che cosa manca e dove; il cancello delle quote scoperte arriva a un agente con le parole e senza il
 * rimando alla home (Web Pro Italia, 15/11/2026).
 *
 * La generazione si ferma, giustamente, quando una voce non ha una tabella, una tabella non ha millesimi o un'unità non ha un titolare per
 * il ruolo richiesto. Ma il messaggio elencava sempre le stesse tre cause possibili, senza dire quale né dove. E un agente AI che creava il
 * piano senza la presa d'atto degli scoperti riceveva `ok: true`, il rimando a «/» e un avviso senza testo: credeva il piano creato, e non
 * lo era (riprodotto qui sotto, con la chiave di un agente, come lo chiama il ponte `kondo-mcp`).
 *
 * Il condominio è quello di `Beta52TotaliScenari`: «Spese generali» € 1.200,00 sulla tabella «Proprietà» (Interno A di Ugo, Interno C di
 * Bice, 500 millesimi ciascuno), «Proventi antenna» sulla tabella «Box».
 *
 * I test con ⚠️ falliscono senza la correzione; le controprove sono verdi prima e dopo.
 *
 * Cosa resta scoperto: le cause più rare (coefficienti o ripartizioni sotto il cento, titolari fuori dal periodo), che seguono la stessa
 * strada e hanno il loro testo in `MancanzeDelRiparto`; il piano straordinario, che senza cause registrate tiene il testo di prima.
 */

use App\Models\ChiaveAgente;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoRate;
use App\Models\Immobile;
use App\Models\Tabella;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/GestionaleTestHelpers.php';
require_once __DIR__.'/Beta52TotaliScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = b52tAdmin();
});

/** Il corpo di un piano con le voci date (la voce intera), generato subito. */
function nqcCorpo(array $s, array $voci, array $extra = []): array
{
    return array_merge([
        'tipo' => 'ordinario', 'gestione_id' => $s['g']->id, 'nome' => 'Preventivo 2026', 'metodo_distribuzione' => 'prima_rata',
        'numero_rate' => 4, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-01-05', 'genera_subito' => true,
        'capitoli_config' => array_map(fn (Conto $v) => ['id' => $v->id, 'importo' => ''], $voci),
    ], $extra);
}

/** La presa d'atto degli scoperti, come la manda il riquadro della pagina. */
const NQC_PRESA_D_ATTO = ['accetta_scoperti' => true, 'nota_scoperti' => 'Procedo, sistemo dopo i dati'];

function nqcCrea($test, array $s, array $voci, array $extra = [])
{
    return $test->actingAs($test->user)->from('/crea')->post(route('admin.gestionale.esercizi.piani-rate.store', [$s['c'], $s['e']]), nqcCorpo($s, $voci, $extra));
}

function nqcChiave($test): string
{
    return ChiaveAgente::genera('Assistente di prova', $test->user, false)[1];
}

it('⚠️ una voce senza tabella: il rifiuto la nomina e dice dove si collega la tabella', function () {
    $s = b52tScenario($this);
    DB::table('conto_tabella_millesimale')->where('conto_id', $s['spesa']->id)->delete();

    $r = nqcCrea($this, $s, [$s['spesa']], NQC_PRESA_D_ATTO);

    $r->assertRedirect('/crea')->assertSessionHasErrors('quote.voci_senza_tabella');
    expect(PianoRate::count())->toBe(0)
        ->and(session('message')['message'])->toBe('Impossibile generare il piano rate «Preventivo 2026»: nessuna quota calcolata, perché nessuna spesa del piano ha qualcuno a cui andare. 1 voce senza tabella millesimale: «Spese generali», € 1.200,00. Collega una tabella alla voce dalla sua scheda, nel piano dei conti. Poi genera di nuovo il piano.')
        ->and(session('errors')->get('quote.voci_senza_tabella'))->toBe(['«Spese generali», € 1.200,00: collega una tabella alla voce dalla sua scheda, nel piano dei conti.']);
});

it('⚠️ una tabella senza millesimi: il rifiuto nomina la tabella e la voce che la usa', function () {
    $s = b52tScenario($this);
    DB::table('quote_tabella')->where('tabella_id', $s['tabella']->id)->update(['valore' => null]);

    $r = nqcCrea($this, $s, [$s['spesa']], NQC_PRESA_D_ATTO);

    expect(session('message')['message'])->toContain('1 tabella senza millesimi: «Proprietà» (2 unità, nessun millesimo compilato), voce «Spese generali». Inserisci i millesimi delle unità nella tabella (Tabelle millesimali).');
    $r->assertSessionHasErrors('quote.tabelle_senza_millesimi');

    // La stessa tabella senza nessuna unità.
    DB::table('quote_tabella')->where('tabella_id', $s['tabella']->id)->delete();
    nqcCrea($this, $s, [$s['spesa']], NQC_PRESA_D_ATTO);
    expect(session('message')['message'])->toContain('1 tabella senza millesimi: «Proprietà» (nessuna unità), voce «Spese generali».');
});

it('⚠️ le unità senza titolare: il rifiuto ne nomina dieci, con il ruolo richiesto, e dice quante sono in tutto; gli errori le danno tutte', function () {
    $s = b52tScenario($this);
    $grande = Tabella::create(['condominio_id' => $s['c']->id, 'nome' => 'Facciata', 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true]);
    foreach (range(1, 12) as $n) {
        $u = Immobile::create(['condominio_id' => $s['c']->id, 'tipo' => 'appartamento', 'codice_immobile' => "NQC-{$n}", 'nome' => "Scala B {$n}", 'interno' => "B{$n}"]);
        DB::table('quote_tabella')->insert(['tabella_id' => $grande->id, 'immobile_id' => $u->id, 'valore' => 10, 'created_at' => now(), 'updated_at' => now()]);
    }
    $facciata = b52tVoce($this, $s, 'Facciata', 'spesa', '600,00', $grande);

    $r = nqcCrea($this, $s, [$facciata], NQC_PRESA_D_ATTO);

    expect(session('message')['message'])->toContain('12 unità senza un titolare per il ruolo richiesto: Scala B 1 (proprietario), voce «Facciata»; Scala B 2 (proprietario), voce «Facciata»;')
        ->toContain('Scala B 10 (proprietario), voce «Facciata»; e altri 2 casi. Aggiungi un titolare attivo all\'unità dalla sua scheda (Anagrafiche), con il ruolo che la voce chiede.')
        ->not->toContain('Scala B 11');
    expect(session('errors')->get('quote.unita_senza_titolare'))->toHaveCount(12);
});

it('⚠️ il piano senza voci, con tutte le voci già in un altro piano dello stesso esercizio, lo dice', function () {
    $s = b52tScenario($this);
    nqcCrea($this, $s, [$s['spesa'], $s['entrata']], ['accetta_destinatari' => true, 'nota_destinatari' => 'Piano di prova'])->assertSessionHasNoErrors();

    $r = nqcCrea($this, $s, [], ['nome' => 'Integrativo', 'capitoli_config' => []]);

    expect(session('message')['message'])->toBe('Impossibile generare il piano rate «Integrativo»: nessuna quota calcolata, perché nessuna spesa del piano ha qualcuno a cui andare. Il piano non ha voci, perché tutte le voci della gestione sono già in un altro piano attivo: «Preventivo 2026». Scegli le voci del piano, ciascuna con la sua parte, oppure togli le voci dall\'altro piano. Poi genera di nuovo il piano.');
    $r->assertSessionHasErrors('quote.piano_senza_voci');
});

it('⚠️ un agente che crea il piano senza la presa d\'atto riceve il rifiuto con le parole, non un «ok» con il rimando alla home', function () {
    $s = b52tScenario($this);
    DB::table('conto_tabella_millesimale')->where('conto_id', $s['spesa']->id)->delete();

    $busta = $this->withToken(nqcChiave($this))->postJson(route('admin.gestionale.esercizi.piani-rate.store', [$s['c'], $s['e']]), nqcCorpo($s, [$s['spesa']]))->json();

    expect($busta['ok'])->toBeFalse()
        ->and($busta['stato'])->toBe(422)
        ->and($busta['rimanda_a'])->toBe("/admin/gestionale/{$s['c']->id}/esercizi/{$s['e']->id}/piani-rate/create")
        ->and($busta['avvisi'][0]['tipo'])->toBe('scoperti_warning')
        ->and($busta['avvisi'][0]['testo'])->toBe('Niente è stato scritto: alcune quote non hanno a chi andare. 1 voce senza tabella millesimale: «Spese generali», € 1.200,00. Collega una tabella alla voce dalla sua scheda, nel piano dei conti. Correggi questi dati e ripeti la richiesta; oppure, se va bene così, ripetila con la presa d\'atto delle quote scoperte (accetta_scoperti e una motivazione di almeno 10 caratteri in nota_scoperti): quelle quote restano non assegnate e il piano lo registra.')
        ->and($busta['avvisi'][0]['dati'][0]['conto_nome'])->toBe('Spese generali')
        ->and(PianoRate::count())->toBe(0)
        // Il registro degli agenti ha la frase del rifiuto, come per gli altri rifiuti.
        ->and(\App\Models\AzioneAgente::latest('id')->value('motivo'))->toStartWith('Niente è stato scritto: alcune quote non hanno a chi andare.');
});

it('⚠️ un agente che procede con la presa d\'atto e non ottiene nessuna quota riceve che cosa manca, caso per caso, e il rimando al modulo', function () {
    $s = b52tScenario($this);
    DB::table('conto_tabella_millesimale')->where('conto_id', $s['spesa']->id)->delete();

    $busta = $this->withToken(nqcChiave($this))->postJson(route('admin.gestionale.esercizi.piani-rate.store', [$s['c'], $s['e']]), nqcCorpo($s, [$s['spesa']], NQC_PRESA_D_ATTO))->json();

    expect($busta['ok'])->toBeFalse()
        ->and($busta['rimanda_a'])->toBe("/admin/gestionale/{$s['c']->id}/esercizi/{$s['e']->id}/piani-rate/create")
        ->and($busta['avvisi'][0]['testo'])->toContain('1 voce senza tabella millesimale: «Spese generali», € 1.200,00.')
        ->and($busta['errori'])->toBe(['quote.voci_senza_tabella' => ['«Spese generali», € 1.200,00: collega una tabella alla voce dalla sua scheda, nel piano dei conti.']]);
});

it('⚠️ il ricalcolo di un agente che si ferma rimanda alla pagina del piano, non alla home', function () {
    $s = b52tScenario($this);
    $piano = b52tPiano($this, $s);
    DB::table('conto_tabella_millesimale')->where('conto_id', $s['spesa']->id)->delete();

    $busta = $this->withToken(nqcChiave($this))->postJson(route('admin.gestionale.esercizi.piani-rate.regenerate', [$s['c'], $s['e'], $piano]))->json();

    expect($busta['ok'])->toBeFalse()
        ->and($busta['rimanda_a'])->toBe("/admin/gestionale/{$s['c']->id}/esercizi/{$s['e']->id}/piani-rate/{$piano->id}")
        ->and($busta['avvisi'][0]['tipo'])->toBe('scoperti_warning')
        ->and($busta['avvisi'][0]['testo'])->toContain('«Spese generali», € 1.200,00');
});

it('controprova — dal browser il cancello degli scoperti resta il riquadro di sempre, senza un messaggio in più', function () {
    $s = b52tScenario($this);
    DB::table('conto_tabella_millesimale')->where('conto_id', $s['spesa']->id)->delete();

    nqcCrea($this, $s, [$s['spesa']])->assertRedirect('/crea')->assertSessionHas('scoperti_warning');
    expect(session('message'))->toBeNull();
});
