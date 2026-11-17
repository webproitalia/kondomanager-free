<?php

/**
 * # Le correzioni al verbale dopo la revisione (Web Pro Italia, 17/11/2026)
 *
 * Il verbale è un atto: chi lo approva deve firmare un testo che corrisponde ai dati dell'assemblea. Qui si prova che
 * - in attesa di approvazione niente dell'assemblea cambia più, nemmeno con la bozza di una registrazione;
 * - l'invio in approvazione rifiuta un testo con parti da compilare, senza le ore, con un esito che non corrisponde ai
 *   voti, o scritto prima che i dati cambiassero;
 * - il voto di chi è assente non conta, e chi è in sala senza un voto conta fra gli intervenuti;
 * - un punto con voti, esito o testo non sparisce dall'ordine del giorno in silenzio;
 * - cambiando tabella i millesimi dell'elenco si ricalcolano, e le stesse unità non finiscono in due righe;
 * - l'ora d'inizio che manca resta da riempire, gli elenchi numerati stampano il numero scritto, il regolamento non è
 *   fra le delibere ordinarie, e le presenze restano anche se l'anagrafica viene eliminata.
 */

use App\Enums\MaggioranzaAssemblea;
use App\Events\Registrazioni\RegistrazioneConclusa;
use App\Models\Anagrafica;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\AssembleaPresenza;
use App\Models\Assemblee\AssembleaPunto;
use App\Models\Assemblee\AssembleaVoto;
use App\Models\Assemblee\Verbale;
use App\Models\ChiaveAgente;
use App\Models\Registrazione;
use App\Models\Tabella;
use App\Services\Assemblee\AventiDiritto;
use App\Services\Assemblee\ConteggiAssemblea;
use App\Services\Assemblee\ControlliVerbale;
use App\Services\Assemblee\VerbaleService;
use App\Services\Fascicolo\TestoNota;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/AssembleeHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->utente = asmAmministratore();
    $this->actingAs($this->utente);
    Storage::fake('local');
});

/**
 * Un'assemblea tenuta e chiusa, pronta per il verbale: tre condòmini presenti (450, 350, 200 millesimi), due punti
 * votati e uno informativo. Il rendiconto passa (2 favorevoli su 3, 800 millesimi), i lavori no (1 su 3).
 */
function corAssemblea(array $assemblea = [], bool $esiti = true): array
{
    $s = asmCondominio([
        'Int. 1' => ['millesimi' => '450', 'titolari' => ['Anna Alberti' => 'proprietario']],
        'Int. 2' => ['millesimi' => '350', 'titolari' => ['Bruno Bassi' => 'proprietario']],
        'Int. 3' => ['millesimi' => '200', 'titolari' => ['Carla Conti' => 'proprietario']],
    ], array_merge([
        'convocazione_tenuta' => 'seconda', 'presidente' => 'Anna Alberti', 'segretario' => 'Mario Studio',
        'ora_inizio' => '18:30', 'ora_fine' => '20:05', 'stato' => 'chiusa',
    ], $assemblea));
    app(AventiDiritto::class)->prepara($s['assemblea']);
    $p = $s['persone'];
    asmSegna($s['assemblea'], array_values($p));

    $rendiconto = AssembleaPunto::create(['assemblea_id' => $s['assemblea']->id, 'numero' => 1, 'titolo' => 'Approvazione del rendiconto 2025', 'maggioranza' => 'ordinaria', 'esito' => $esiti ? 'approvato' : null]);
    $lavori = AssembleaPunto::create(['assemblea_id' => $s['assemblea']->id, 'numero' => 2, 'titolo' => 'Rifacimento della facciata', 'maggioranza' => 'art1136c4', 'esito' => $esiti ? 'respinto' : null]);
    $varie = AssembleaPunto::create(['assemblea_id' => $s['assemblea']->id, 'numero' => 3, 'titolo' => 'Varie ed eventuali', 'maggioranza' => 'informativa']);
    asmVota($rendiconto, [[$p['Anna Alberti'], 'favorevole'], [$p['Bruno Bassi'], 'favorevole'], [$p['Carla Conti'], 'astenuto']]);
    asmVota($lavori, [[$p['Anna Alberti'], 'favorevole'], [$p['Bruno Bassi'], 'contrario'], [$p['Carla Conti'], 'contrario']]);

    return $s + ['punti' => [$rendiconto, $lavori, $varie]];
}

function corUrl(string $nome, Assemblea $a, array $altri = []): string
{
    return route('admin.gestionale.assemblee.'.$nome, ['condominio' => $a->condominio_id, 'assemblea' => $a->id] + $altri);
}

/** Il primo errore di un campo dopo una richiesta rifiutata. */
function corErrore(string $campo): string
{
    return (string) session('errors')?->getBag('default')->first($campo);
}

function corStato(Assemblea $a): string
{
    return (string) Verbale::where('assemblea_id', $a->id)->value('stato');
}

// --- 1. In attesa di approvazione i dati non cambiano più ---------------------------------------------------------

it('⚠️ in attesa di approvazione niente dell\'assemblea cambia: voti, esiti, presenze, elenco, svolgimento, ordine del giorno, bozza dei punti', function () {
    $s = corAssemblea();
    $a = $s['assemblea'];
    [$rendiconto, $lavori] = $s['punti'];
    $bruno = asmPresenza($a, $s['persone']['Bruno Bassi']);

    $this->post(corUrl('verbale.genera', $a))->assertSessionHasNoErrors();
    $this->post(corUrl('verbale.invia', $a))->assertSessionHasNoErrors();
    expect(corStato($a))->toBe(Verbale::DA_APPROVARE);
    $testo = Verbale::where('assemblea_id', $a->id)->value('testo');

    $scritture = [
        'i voti' => fn () => $this->put(corUrl('punti.voti', $a, ['punto' => $lavori->id]), ['tutti' => 'favorevole']),
        'l\'esito' => fn () => $this->put(corUrl('punti.update', $a, ['punto' => $lavori->id]), ['esito' => 'approvato']),
        'la presenza' => fn () => $this->put(corUrl('presenze.update', $a, ['presenza' => $bruno->id]), ['tipo' => 'assente']),
        'l\'elenco' => fn () => $this->post(corUrl('presenze.prepara', $a)),
        'lo svolgimento' => fn () => $this->put(corUrl('svolgimento', $a), ['ora_fine' => '21:00']),
        'l\'ordine del giorno' => fn () => $this->put(corUrl('update', $a), ['titolo' => 'Un altro titolo', 'punti' => [['titolo' => 'Punto nuovo', 'maggioranza' => 'ordinaria']]]),
        'la bozza dei punti' => fn () => $this->post(corUrl('verbale.precompila', $a), ['punti' => [['numero' => 2, 'delibera' => 'Entrata dopo.']]]),
        'il testo' => fn () => $this->put(corUrl('verbale.update', $a), ['testo' => 'Un altro testo.']),
        'la rigenerazione' => fn () => $this->post(corUrl('verbale.genera', $a), ['sovrascrivi' => true]),
    ];
    foreach ($scritture as $che => $richiesta) {
        $richiesta()->assertSessionHasErrors('verbale');
        // Il rifiuto dice perché («in attesa di approvazione») e che cosa fare («ritiralo»).
        expect(str_contains(corErrore('verbale'), 'in attesa di approvazione') && str_contains(corErrore('verbale'), 'ritiralo'))
            ->toBeTrue("{$che}: rifiuto poco chiaro: ".corErrore('verbale'));
    }

    // Niente è cambiato: chi approva firma un testo che corrisponde ancora ai dati.
    $a->refresh();
    expect(asmVotazione($lavori)['favorevoli']['teste'])->toBe(1)
        ->and($lavori->fresh()->esito)->toBe('respinto')
        ->and($lavori->fresh()->delibera)->toBeNull()
        ->and($bruno->fresh()->tipo)->toBe('presente')
        ->and(substr((string) $a->ora_fine, 0, 5))->toBe('20:05')
        ->and($a->titolo)->toBe('Assemblea ordinaria')
        ->and($a->punti()->count())->toBe(3)
        ->and(Verbale::where('assemblea_id', $a->id)->value('testo'))->toBe($testo)
        ->and(app(ControlliVerbale::class)->testoAllineato($a, Verbale::where('assemblea_id', $a->id)->first()))->toBeTrue();

    // Ritirato, si lavora di nuovo.
    $this->post(corUrl('verbale.ritira', $a))->assertSessionHasNoErrors();
    $this->put(corUrl('punti.update', $a, ['punto' => $lavori->id]), ['delibera' => 'L\'assemblea rinvia i lavori.'])->assertSessionHasNoErrors();
    expect($lavori->fresh()->delibera)->toBe('L\'assemblea rinvia i lavori.');
});

it('⚠️ in attesa di approvazione la bozza di una registrazione non entra nei punti, e lo si scrive nel registro', function () {
    $s = corAssemblea();
    $a = $s['assemblea'];
    $this->post(corUrl('verbale.genera', $a))->assertSessionHasNoErrors();
    $this->post(corUrl('verbale.invia', $a))->assertSessionHasNoErrors();

    $registrazione = Registrazione::create([
        'condominio_id' => $a->condominio_id, 'assemblea_id' => $a->id, 'titolo' => 'Assemblea ordinaria', 'stato' => Registrazione::PRONTA,
        'avviso_presenti_at' => now(), 'iniziata_at' => now()->subHour(), 'finita_at' => now(), 'durata_secondi' => 3600, 'formato' => 'webm',
        'segmenti' => [], 'trascrizione' => 'Persona 1: …', 'riassunto' => 'Si è discusso.', 'created_by' => auth()->id(),
        'bozza_punti' => [['numero' => 1, 'discussione' => 'Arrivata mentre il verbale aspetta chi lo approva.', 'delibera' => 'L\'assemblea approva il rendiconto.']],
    ]);
    Log::spy();

    RegistrazioneConclusa::dispatch($registrazione);

    expect($s['punti'][0]->fresh()->discussione)->toBeNull()
        ->and($s['punti'][0]->fresh()->delibera)->toBeNull()
        ->and(corStato($a))->toBe(Verbale::DA_APPROVARE);
    Log::shouldHaveReceived('info')->withArgs(fn ($messaggio, $contesto = []) => str_contains((string) $messaggio, 'non entra nel verbale')
        && str_contains((string) ($contesto['motivo'] ?? ''), 'in attesa di approvazione'))->atLeast()->once();
});

// --- 2. I controlli dell'invio in approvazione --------------------------------------------------------------------

it('⚠️ l\'invio rifiuta un testo con parti ancora da compilare, e dice quali', function () {
    $s = corAssemblea(esiti: false);
    $a = $s['assemblea'];
    [$rendiconto, $lavori] = $s['punti'];

    // Generato prima di confermare gli esiti: il testo dice «Esito da confermare».
    $this->post(corUrl('verbale.genera', $a))->assertSessionHasNoErrors();
    $testo = (string) Verbale::where('assemblea_id', $a->id)->value('testo');
    expect($testo)->toContain('Esito da confermare (secondo i voti: approvato).');

    $this->put(corUrl('punti.update', $a, ['punto' => $rendiconto->id]), ['esito' => 'approvato'])->assertSessionHasNoErrors();
    $this->put(corUrl('punti.update', $a, ['punto' => $lavori->id]), ['esito' => 'respinto'])->assertSessionHasNoErrors();
    // Il testo risalvato com'è (così il rifiuto è solo per le parti da compilare, non per i dati cambiati).
    $this->put(corUrl('verbale.update', $a), ['testo' => $testo])->assertSessionHasNoErrors();

    $this->post(corUrl('verbale.invia', $a))->assertSessionHasErrors('segnaposti')->assertSessionDoesntHaveErrors('dati_riletti');
    expect(corErrore('segnaposti'))->toContain('Esito da confermare (secondo i voti: approvato).')
        ->toContain('l\'esito del punto non è confermato')
        ->toContain('rigenera il testo')
        ->and(corStato($a))->toBe(Verbale::BOZZA);

    // Un segnaposto che nessuno ha sostituito e una riga da riempire; la riga delle firme non è un dato che manca.
    $this->put(corUrl('verbale.update', $a), ['testo' => "Presiede ______; segretario {{segretario_inventato}}.\nIl presidente: ______________________ (Anna Alberti)"])->assertSessionHasNoErrors();
    $this->post(corUrl('verbale.invia', $a))->assertSessionHasErrors('segnaposti');
    expect(corErrore('segnaposti'))->toContain('riga 1')->toContain('un segnaposto non sostituito')->not->toContain('riga 2');

    expect(ControlliVerbale::partiDaCompilare("Presiede ______;\nIl presidente: ______________________ (Anna)\nVedi {{boh}}\nEsito da confermare.\nTutto a posto."))
        ->toBe([
            ['riga' => 1, 'testo' => 'Presiede ______;', 'perche' => 'un dato che manca'],
            ['riga' => 3, 'testo' => 'Vedi {{boh}}', 'perche' => 'un segnaposto non sostituito'],
            ['riga' => 4, 'testo' => 'Esito da confermare.', 'perche' => 'l\'esito del punto non è confermato'],
        ]);

    // Rigenerato con gli esiti confermati, il testo è completo e parte.
    $this->post(corUrl('verbale.genera', $a), ['sovrascrivi' => true])->assertSessionHasNoErrors();
    $this->post(corUrl('verbale.invia', $a))->assertSessionHasNoErrors();
    expect(corStato($a))->toBe(Verbale::DA_APPROVARE);
});

it('⚠️ l\'invio rifiuta un verbale senza l\'ora d\'inizio o di fine, e l\'ora che manca non diventa quella della convocazione', function () {
    $s = corAssemblea(['ora_inizio' => null, 'ora_fine' => null]);
    $a = $s['assemblea'];

    // La seconda convocazione era alle 18.30: il verbale non la scrive come ora d'inizio.
    $this->post(corUrl('verbale.genera', $a))->assertSessionHasNoErrors();
    $testo = (string) Verbale::where('assemblea_id', $a->id)->value('testo');
    expect($testo)->toContain('Il giorno 23 novembre 2026, alle ore ______, presso')
        ->and($testo)->not->toContain('alle ore 18.30');

    $this->post(corUrl('verbale.invia', $a))->assertSessionHasErrors(['ora_inizio', 'ora_fine', 'segnaposti']);
    expect(corErrore('ora_inizio'))->toContain('Manca l\'ora d\'inizio')
        ->and(corErrore('ora_fine'))->toContain('Manca l\'ora di chiusura')
        ->and(corStato($a))->toBe(Verbale::BOZZA);

    $this->put(corUrl('svolgimento', $a), ['ora_inizio' => '18:40', 'ora_fine' => '20:05'])->assertSessionHasNoErrors();
    $this->post(corUrl('verbale.genera', $a), ['sovrascrivi' => true])->assertSessionHasNoErrors();
    expect(Verbale::where('assemblea_id', $a->id)->value('testo'))->toContain('alle ore 18.40, presso');
    $this->post(corUrl('verbale.invia', $a))->assertSessionHasNoErrors();
});

it('⚠️ l\'invio rifiuta un esito «approvato» senza nessun voto, e un esito diverso da quello dei voti se la persona non lo conferma', function () {
    $s = corAssemblea();
    $a = $s['assemblea'];
    [$rendiconto, $lavori] = $s['punti'];

    // Il rendiconto resta «approvato» ma i suoi voti spariscono; i lavori sono scritti «approvato» mentre i voti dicono «respinto».
    $rendiconto->voti()->delete();
    $lavori->forceFill(['esito' => 'approvato'])->save();

    $senzaVoti = asmVotazione($rendiconto->fresh());
    expect($senzaVoti['esito_senza_voti'])->toBeTrue()->and($senzaVoti['esito_diverso'])->toBeFalse()
        ->and(asmVotazione($lavori->fresh())['esito_diverso'])->toBeTrue();
    $avvisi = collect(app(ConteggiAssemblea::class)->avvisi(Assemblea::with(['presenze.anagrafica', 'presenze.delegato', 'punti.voti'])->find($a->id)))->pluck('testo')->implode("\n");
    expect($avvisi)->toContain('Punto 1: l\'esito confermato è «approvato» ma non c\'è nessun voto registrato');

    $this->post(corUrl('verbale.genera', $a))->assertSessionHasNoErrors();
    $this->post(corUrl('verbale.invia', $a))->assertSessionHasErrors(['voti', 'esiti_confermati']);
    expect(corErrore('voti'))->toContain('Il punto 1 ha l\'esito «approvato» ma nessun voto registrato')->toContain('Registra i voti')
        ->and(corErrore('esiti_confermati'))->toContain('Il punto 2 (id '.$lavori->id.') ha l\'esito «approvato», ma i voti registrati danno «respinto»')
        ->and(corErrore('esiti_confermati'))->toContain('favorevoli 1 su 3 intervenuti')
        ->and(corStato($a))->toBe(Verbale::BOZZA);

    // Confermare l'esito diverso non basta a far passare un esito senza voti.
    $this->post(corUrl('verbale.invia', $a), ['esiti_confermati' => [$lavori->id, $rendiconto->id]])
        ->assertSessionHasErrors('voti')->assertSessionDoesntHaveErrors('esiti_confermati');

    // Registrati i voti del rendiconto e rigenerato il testo, l'esito dei lavori passa solo con la conferma esplicita.
    asmVota($rendiconto, [[$s['persone']['Anna Alberti'], 'favorevole'], [$s['persone']['Bruno Bassi'], 'favorevole'], [$s['persone']['Carla Conti'], 'astenuto']]);
    $this->post(corUrl('verbale.genera', $a), ['sovrascrivi' => true])->assertSessionHasNoErrors();
    $this->post(corUrl('verbale.invia', $a))->assertSessionHasErrors('esiti_confermati')->assertSessionDoesntHaveErrors('voti');
    $this->post(corUrl('verbale.invia', $a), ['esiti_confermati' => [$lavori->id]])->assertSessionHasNoErrors();
    expect(corStato($a))->toBe(Verbale::DA_APPROVARE);
});

it('⚠️ l\'invio rifiuta un testo scritto prima che i dati cambiassero: si rigenera, si rilegge e si salva, o si dichiara «dati_riletti»', function () {
    $s = corAssemblea();
    $a = $s['assemblea'];
    [$rendiconto] = $s['punti'];
    $carla = asmPresenza($a, $s['persone']['Carla Conti']);
    $voti = fn (string $voto) => $this->put(corUrl('punti.voti', $a, ['punto' => $rendiconto->id]), ['voti' => [['presenza_id' => $carla->id, 'voto' => $voto]]])->assertSessionHasNoErrors();
    $verbale = fn () => Verbale::where('assemblea_id', $a->id)->first();

    $this->post(corUrl('verbale.genera', $a))->assertSessionHasNoErrors();
    expect($verbale()->impronta_dati)->toHaveLength(64)
        ->and($verbale()->testo)->toContain('Astenuti: 1 condòmino per 200,00 millesimi (Carla Conti (200,00)).');

    // Dopo il testo cambia un voto: il rendiconto resta approvato (2 su 3), ma il testo dice ancora «astenuta».
    $voti('contrario');
    $this->post(corUrl('verbale.invia', $a))->assertSessionHasErrors('dati_riletti');
    expect(corErrore('dati_riletti'))->toContain('sono cambiati dopo')->toContain('Rigenera il testo')->toContain('dati_riletti: true')
        ->and(corStato($a))->toBe(Verbale::BOZZA);

    // Salvare solo i testi dei punti non è rileggere il testo del verbale.
    $this->put(corUrl('verbale.update', $a), ['punti' => [['id' => $rendiconto->id, 'delibera' => 'L\'assemblea approva il rendiconto 2025.']]])->assertSessionHasNoErrors();
    $this->post(corUrl('verbale.invia', $a))->assertSessionHasErrors('dati_riletti');

    // Chi rilegge e salva il testo (corretto a mano) può mandarlo.
    $corretto = str_replace('Astenuti: 1 condòmino per 200,00 millesimi (Carla Conti (200,00)).', 'Astenuti: nessuno.', (string) $verbale()->testo);
    $this->put(corUrl('verbale.update', $a), ['testo' => $corretto])->assertSessionHasNoErrors();
    $this->post(corUrl('verbale.invia', $a))->assertSessionHasNoErrors();
    expect(corStato($a))->toBe(Verbale::DA_APPROVARE);

    // Ritirato e cambiato di nuovo un dato (l'ora di chiusura): rifiutato; con «dati_riletti» passa, e l'impronta è quella dei dati di adesso.
    $this->post(corUrl('verbale.ritira', $a))->assertSessionHasNoErrors();
    $this->put(corUrl('svolgimento', $a), ['ora_fine' => '20:30'])->assertSessionHasNoErrors();
    $this->post(corUrl('verbale.invia', $a))->assertSessionHasErrors('dati_riletti');
    $this->post(corUrl('verbale.invia', $a), ['dati_riletti' => true])->assertSessionHasNoErrors();
    expect(corStato($a))->toBe(Verbale::DA_APPROVARE)
        ->and(app(ControlliVerbale::class)->testoAllineato($a, $verbale()))->toBeTrue();

    // Ritirato, cambiato un voto e rigenerato: il testo nuovo corrisponde, e parte senza conferme.
    $this->post(corUrl('verbale.ritira', $a))->assertSessionHasNoErrors();
    $voti('favorevole');
    $this->post(corUrl('verbale.invia', $a))->assertSessionHasErrors('dati_riletti');
    $this->post(corUrl('verbale.genera', $a), ['sovrascrivi' => true])->assertSessionHasNoErrors();
    $this->post(corUrl('verbale.invia', $a))->assertSessionHasNoErrors();
});

it('un testo senza impronta (scritto prima di questo controllo) chiede una rilettura; un agente AI legge il rifiuto e il campo con cui procedere', function () {
    $s = corAssemblea();
    $a = $s['assemblea'];
    app(VerbaleService::class)->verbaleDi($a)->forceFill(['testo' => "# Verbale\nScritto prima della correzione.", 'impronta_dati' => null])->save();
    [, $chiave] = ChiaveAgente::genera('Assistente del verbale', $this->utente, false);
    $agente = fn () => $this->withHeader('Authorization', 'Bearer '.$chiave);

    $rifiuto = $agente()->postJson(corUrl('verbale.invia', $a));
    $rifiuto->assertStatus(422);
    expect($rifiuto->json('ok'))->toBeFalse()
        ->and($rifiuto->json('errori.dati_riletti.0'))->toContain('dati_riletti: true')
        ->and(corStato($a))->toBe(Verbale::BOZZA);

    $agente()->postJson(corUrl('verbale.invia', $a), ['dati_riletti' => true])->assertOk();
    expect(corStato($a))->toBe(Verbale::DA_APPROVARE);

    // In attesa di approvazione anche l'agente riceve il rifiuto con il motivo.
    $fermo = $agente()->putJson(corUrl('punti.update', $a, ['punto' => $s['punti'][0]->id]), ['delibera' => 'x']);
    $fermo->assertStatus(422);
    expect($fermo->json('errori.verbale.0'))->toContain('in attesa di approvazione');
});

// --- 4. Il voto di chi è assente -----------------------------------------------------------------------------------

it('⚠️ il voto di chi è assente non conta: non si registra, e segnando assente chi ha votato i suoi voti si tolgono e la risposta lo dice', function () {
    $s = corAssemblea(esiti: false);
    $a = $s['assemblea'];
    [$rendiconto, $lavori] = $s['punti'];
    $p = $s['persone'];
    $carla = asmPresenza($a, $p['Carla Conti']);
    $bruno = asmPresenza($a, $p['Bruno Bassi']);

    // Carla ha votato sui due punti: segnata assente, i suoi due voti si tolgono e la risposta lo dice.
    $risposta = $this->put(corUrl('presenze.update', $a, ['presenza' => $carla->id]), ['tipo' => 'assente']);
    $risposta->assertSessionHasNoErrors()->assertSessionHas('message.type', 'warning');
    expect(session('message.message'))->toContain('Carla Conti è segnato assente')->toContain('tolti i suoi 2 voti sui punti 1, 2')
        ->and(AssembleaVoto::where('assemblea_presenza_id', $carla->id)->count())->toBe(0);
    $v = asmVotazione($rendiconto);
    expect($v['astenuti']['teste'])->toBe(0)->and($v['intervenuti']['teste'])->toBe(2)->and($v['esito_proposto'])->toBe('approvato');

    // Per chi è assente non si registra un voto; toglierlo (voto vuoto) si può sempre.
    $this->put(corUrl('punti.voti', $a, ['punto' => $rendiconto->id]), ['voti' => [['presenza_id' => $carla->id, 'voto' => 'contrario']]])
        ->assertSessionHasErrors('voti.0.presenza_id');
    expect(corErrore('voti.0.presenza_id'))->toContain('Carla Conti è segnato assente')->toContain('segnalo prima presente')
        ->and(AssembleaVoto::where('assemblea_presenza_id', $carla->id)->count())->toBe(0);
    $this->put(corUrl('punti.voti', $a, ['punto' => $rendiconto->id]), ['voti' => [['presenza_id' => $carla->id, 'voto' => null]]])->assertSessionHasNoErrors();

    // Chi esce a metà tiene i voti dati prima, e senza messaggi.
    $this->put(corUrl('presenze.update', $a, ['presenza' => $bruno->id]), ['segna' => 'uscita'])->assertSessionHasNoErrors()->assertSessionMissing('message');
    expect(AssembleaVoto::where('assemblea_presenza_id', $bruno->id)->count())->toBe(2)
        ->and(asmVotazione($rendiconto)['favorevoli']['teste'])->toBe(2);

    // Un voto rimasto a chi è assente (dati di prima della correzione) non entra nei conti, e la pagina lo segnala.
    asmPresenza($a, $p['Anna Alberti'])->forceFill(['tipo' => 'assente'])->save();
    $v = asmVotazione($rendiconto);
    expect($v['favorevoli']['teste'])->toBe(1)->and($v['voti_di_assenti'])->toBe(['Anna Alberti']);
    $avvisi = collect(app(ConteggiAssemblea::class)->avvisi(Assemblea::with(['presenze.anagrafica', 'presenze.delegato', 'punti.voti'])->find($a->id)))->pluck('testo')->implode("\n");
    expect($avvisi)->toContain('Punto 1: c\'è un voto registrato per chi è segnato assente (Anna Alberti): non conta.');
});

// --- 5. Gli intervenuti di un punto --------------------------------------------------------------------------------

it('⚠️ chi è in sala senza un voto conta fra gli intervenuti come non votante: pesa come un astenuto, il conteggio lo mostra a parte e la pagina avvisa', function () {
    $s = asmCondominio([
        'A' => ['millesimi' => '300', 'titolari' => ['Anna Alberti' => 'proprietario']],
        'B' => ['millesimi' => '300', 'titolari' => ['Bruno Bassi' => 'proprietario']],
        'C' => ['millesimi' => '200', 'titolari' => ['Carla Conti' => 'proprietario']],
        'D' => ['millesimi' => '200', 'titolari' => ['Dario Dini' => 'proprietario']],
    ], ['convocazione_tenuta' => 'seconda', 'ora_inizio' => '18:30']);
    $a = $s['assemblea'];
    app(AventiDiritto::class)->prepara($a);
    $p = $s['persone'];
    asmSegna($a, array_values($p));
    $punto = AssembleaPunto::create(['assemblea_id' => $a->id, 'numero' => 1, 'titolo' => 'Rendiconto', 'maggioranza' => 'ordinaria']);
    $avvisi = fn () => collect(app(ConteggiAssemblea::class)->avvisi(Assemblea::with(['presenze.anagrafica', 'presenze.delegato', 'punti.voti'])->find($a->id)))->pluck('testo')->implode("\n");

    // Nessun voto ancora: il punto è da votare, nessun esito proposto e nessun avviso sui presenti senza voto.
    $v = asmVotazione($punto);
    expect($v['esito_proposto'])->toBeNull()->and($v['votanti']['teste'])->toBe(0)->and($v['senza_voto'])->toHaveCount(4)
        ->and($avvisi())->not->toContain('senza voto');

    // Anna e Bruno favorevoli (600 millesimi), Carla e Dario in sala senza un voto: 2 su 4 non è più della metà.
    asmVota($punto, [[$p['Anna Alberti'], 'favorevole'], [$p['Bruno Bassi'], 'favorevole']]);
    $v = asmVotazione($punto);
    expect($v['favorevoli']['teste'])->toBe(2)
        ->and($v['votanti']['teste'])->toBe(2)
        ->and($v['non_votanti']['teste'])->toBe(2)
        ->and($v['non_votanti']['millesimi_testo'])->toBe('400,00')
        ->and(array_column($v['non_votanti']['nomi'], 'nome'))->toBe(['Carla Conti', 'Dario Dini'])
        ->and($v['intervenuti']['teste'])->toBe(4)
        ->and($v['intervenuti']['millesimi_testo'])->toBe('1.000,00')
        ->and($v['senza_voto'])->toBe([asmPresenza($a, $p['Carla Conti'])->id, asmPresenza($a, $p['Dario Dini'])->id])
        ->and($v['teste_ok'])->toBeFalse()
        ->and($v['valore_ok'])->toBeTrue()
        ->and($v['esito_proposto'])->toBe('respinto')
        ->and($v['spiegazione'])->toContain('Favorevoli 2 su 4 intervenuti')->toContain('2 presenti senza voto: pesano come astenuti');
    expect($avvisi())->toContain('Punto 1: 2 presenti senza voto (Carla Conti, Dario Dini). Contano fra gli intervenuti come non votanti');

    // Il verbale li scrive, con i nomi.
    $testo = app(VerbaleService::class)->compila(Assemblea::find($a->id));
    expect($testo)->toContain("Astenuti: nessuno.\nPresenti che non hanno espresso il voto: 2 condòmini per 400,00 millesimi (Carla Conti (200,00), Dario Dini (200,00)).");

    // Dario era uscito prima del voto: segnata l'uscita non è più fra gli intervenuti. 2 su 3: approvato.
    asmPresenza($a, $p['Dario Dini'])->forceFill(['ora_uscita' => '19:00'])->save();
    $v = asmVotazione($punto);
    expect($v['intervenuti']['teste'])->toBe(3)->and($v['non_votanti']['teste'])->toBe(1)->and($v['esito_proposto'])->toBe('approvato')
        ->and($v['spiegazione'])->toContain('1 presente senza voto: pesa come un astenuto');

    // Chi è uscito dopo aver votato tiene il suo voto e resta fra gli intervenuti di quel punto.
    asmPresenza($a, $p['Bruno Bassi'])->forceFill(['ora_uscita' => '19:30'])->save();
    $v = asmVotazione($punto);
    expect($v['favorevoli']['teste'])->toBe(2)->and($v['intervenuti']['teste'])->toBe(3);

    // Registrato il voto di Carla, non resta nessuno senza voto e l'avviso sparisce.
    asmVota($punto, [[$p['Carla Conti'], 'astenuto']]);
    expect(asmVotazione($punto)['non_votanti']['teste'])->toBe(0)->and($avvisi())->not->toContain('senza voto');
});

// --- 11. Togliere un punto dall'ordine del giorno -----------------------------------------------------------------

it('⚠️ un punto con voti, un esito o un testo non sparisce dall\'ordine del giorno in silenzio: serve «elimina_punti»', function () {
    $s = corAssemblea(esiti: false);
    $a = $s['assemblea'];
    [$rendiconto, $lavori, $varie] = $s['punti'];
    $lavori->voti()->delete();
    $lavori->forceFill(['delibera' => 'L\'assemblea rinvia i lavori alla primavera.'])->save();
    $riga = fn (AssembleaPunto $p) => ['id' => $p->id, 'titolo' => $p->titolo, 'maggioranza' => $p->maggioranza];
    $modifica = fn (array $dati) => $this->put(corUrl('update', $a), $dati);

    // Mancano i lavori (hanno il testo della delibera) e le varie (non hanno niente): la modifica è rifiutata, e dice quale punto e perché.
    $modifica(['punti' => [$riga($rendiconto)]])->assertSessionHasErrors('punti');
    expect(corErrore('punti'))->toContain('il punto 2 «Rifacimento della facciata» (id '.$lavori->id.') ha il testo della delibera')
        ->toContain('elimina_punti')
        ->not->toContain('Varie ed eventuali')
        ->and($a->punti()->count())->toBe(3)
        ->and($lavori->fresh()->delibera)->toBe('L\'assemblea rinvia i lavori alla primavera.');

    // Un punto con voti e un esito: il rifiuto dice quanti voti e quale esito.
    $rendiconto->forceFill(['esito' => 'approvato'])->save();
    $modifica(['punti' => [$riga($lavori), $riga($varie)]])->assertSessionHasErrors('punti');
    expect(corErrore('punti'))->toContain('il punto 1 «Approvazione del rendiconto 2025» (id '.$rendiconto->id.') ha 3 voti registrati, l\'esito «approvato»')
        ->and(AssembleaVoto::where('assemblea_punto_id', $rendiconto->id)->count())->toBe(3);

    // «elimina_punti» da solo non fa niente, e lo dice; un punto non può stare nell'elenco e fra quelli da eliminare.
    $modifica(['elimina_punti' => [$lavori->id]])->assertSessionHasErrors('elimina_punti');
    $modifica(['punti' => [$riga($rendiconto), $riga($lavori)], 'elimina_punti' => [$lavori->id]])->assertSessionHasErrors('elimina_punti.0');
    $modifica(['punti' => [$riga($rendiconto)], 'elimina_punti' => [999999]])->assertSessionHasErrors('elimina_punti.0');
    expect($a->punti()->count())->toBe(3);

    // Con la conferma esplicita i lavori si tolgono con il loro testo; le varie, che non avevano niente, come sempre.
    $modifica(['punti' => [$riga($rendiconto)], 'elimina_punti' => [$lavori->id]])->assertSessionHasNoErrors();
    expect(AssembleaPunto::find($lavori->id))->toBeNull()
        ->and(AssembleaPunto::find($varie->id))->toBeNull()
        ->and($a->punti()->pluck('titolo')->all())->toBe(['Approvazione del rendiconto 2025']);

    // E così anche un punto con i voti.
    $modifica(['punti' => [['titolo' => 'Punto nuovo', 'maggioranza' => 'ordinaria']], 'elimina_punti' => [$rendiconto->id]])->assertSessionHasNoErrors();
    expect(AssembleaPunto::find($rendiconto->id))->toBeNull()
        ->and(AssembleaVoto::count())->toBe(0)
        ->and($a->punti()->pluck('titolo')->all())->toBe(['Punto nuovo']);
});

// --- 12. Cambiare tabella dopo aver preparato l'elenco ------------------------------------------------------------

it('⚠️ cambiando la tabella dei conteggi con l\'elenco già preparato i millesimi si ricalcolano subito; presenze, deleghe e voti restano', function () {
    $s = corAssemblea(esiti: false);
    $a = $s['assemblea'];
    $p = $s['persone'];
    asmSegna($a, [$p['Carla Conti']], 'delega', 'Lucia Bassi');
    $nuova = Tabella::create(['condominio_id' => $a->condominio_id, 'nome' => 'Tabella scale', 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true]);
    foreach (['Int. 1' => '500', 'Int. 2' => '300', 'Int. 3' => '150'] as $unita => $valore) {
        DB::table('quote_tabella')->insert(['tabella_id' => $nuova->id, 'immobile_id' => $s['unita'][$unita]->id, 'valore' => $valore, 'created_at' => now(), 'updated_at' => now()]);
    }

    $risposta = $this->put(corUrl('update', $a), ['tabella_id' => $nuova->id]);
    $risposta->assertSessionHasNoErrors()->assertSessionHas('message.type', 'warning');
    expect(session('message.message'))->toContain('i millesimi dei 3 aventi diritto sono stati ricalcolati dalla tabella «Tabella scale» (totale 950,00)')
        ->toContain('Ci sono già dei voti');

    $a->refresh();
    $presenze = AssembleaPresenza::where('assemblea_id', $a->id)->get();
    expect($presenze)->toHaveCount(3)
        ->and(asmPresenza($a, $p['Anna Alberti'])->millesimi)->toBe('500.00000')
        ->and(asmPresenza($a, $p['Bruno Bassi'])->millesimi)->toBe('300.00000')
        ->and(asmPresenza($a, $p['Carla Conti'])->millesimi)->toBe('150.00000')
        ->and($a->millesimi_totali)->toBe('950.00000')
        // Quello che era segnato resta: presenze, la delega, i voti.
        ->and($presenze->pluck('tipo')->sort()->values()->all())->toBe(['delega', 'presente', 'presente'])
        ->and(asmPresenza($a, $p['Carla Conti'])->nomeDelegato())->toBe('Lucia Bassi')
        ->and(AssembleaVoto::count())->toBe(6);

    // Il verbale scrive il nome della tabella nuova con i millesimi della tabella nuova (riportati a mille).
    $testo = app(VerbaleService::class)->compila(Assemblea::find($a->id));
    expect($testo)->toContain('(tabella «Tabella scale»)')
        ->and($testo)->toContain('Anna Alberti (526,32)');

    // La tabella si cambia, non si toglie: i millesimi dell'elenco resterebbero quelli di una tabella che il verbale non nomina più.
    $this->put(corUrl('update', $a), ['tabella_id' => null])->assertSessionHasErrors('tabella_id');
    expect($a->fresh()->tabella_id)->toBe($nuova->id);

    // Senza cambiare tabella non si ricalcola niente.
    asmPresenza($a, $p['Anna Alberti'])->forceFill(['millesimi' => '501'])->save();
    $this->put(corUrl('update', $a), ['tabella_id' => $nuova->id, 'luogo' => 'Sala nuova'])->assertSessionHasNoErrors()->assertSessionHas('message.message', 'Assemblea salvata.');
    expect(asmPresenza($a, $p['Anna Alberti'])->millesimi)->toBe('501.00000');
});

it('⚠️ preparando di nuovo l\'elenco le stesse unità non finiscono in due righe: la riga con i voti resta ferma, con i millesimi di adesso', function () {
    $s = asmCondominio([
        'Appartamento 1' => ['millesimi' => '200', 'titolari' => ['Rossi Mario' => 'proprietario']],
        'Box 1' => ['millesimi' => '30', 'titolari' => ['Rossi Mario' => 'proprietario']],
        'Appartamento 2' => ['millesimi' => '770', 'titolari' => ['Neri Luca' => 'proprietario']],
    ]);
    $a = $s['assemblea'];
    $p = $s['persone'];
    $servizio = app(AventiDiritto::class);
    $servizio->prepara($a);
    asmSegna($a, [$p['Rossi Mario']]);
    $punto = AssembleaPunto::create(['assemblea_id' => $a->id, 'numero' => 1, 'titolo' => 'Rendiconto', 'maggioranza' => 'ordinaria']);
    asmVota($punto, [[$p['Rossi Mario'], 'favorevole']]);
    $unitaPerRiga = fn () => AssembleaPresenza::where('assemblea_id', $a->id)->orderBy('id')->get()->map(fn ($r) => $r->idUnita())->all();
    [$app1, $box, $app2] = [$s['unita']['Appartamento 1']->id, $s['unita']['Box 1']->id, $s['unita']['Appartamento 2']->id];

    // In archivio il box passa a Neri, e la tabella cambia i millesimi del box: i gruppi di adesso sarebbero Rossi (app. 1) e Neri (box, app. 2).
    DB::table('anagrafica_immobile')->where('immobile_id', $box)->update(['anagrafica_id' => $p['Neri Luca']->id]);
    DB::table('quote_tabella')->where('immobile_id', $box)->update(['valore' => '40']);

    $esito = $servizio->prepara($a->fresh());

    // La riga di Rossi ha un voto: resta com'è (app. 1 e box), e nessuna unità compare in due righe.
    expect($esito['aggiunte'])->toBe(0)->and($esito['tolte'])->toBe(0)->and($esito['doppie'])->toBe([])
        ->and($esito['rimaste'])->toEqualCanonicalizing([asmPresenza($a, $p['Rossi Mario'])->id, asmPresenza($a, $p['Neri Luca'])->id])
        ->and($unitaPerRiga())->toEqualCanonicalizing([[$app1, $box], [$app2]])
        // I millesimi della riga ferma sono quelli della tabella di adesso sulle sue unità: 200 + 40.
        ->and(asmPresenza($a, $p['Rossi Mario'])->millesimi)->toBe('240.00000')
        ->and(asmPresenza($a, $p['Rossi Mario'])->tipo)->toBe('presente')
        ->and(AssembleaVoto::count())->toBe(1);

    // La pagina lo dice, con i nomi e che cosa fare.
    $this->post(corUrl('presenze.prepara', $a))->assertSessionHasNoErrors()->assertSessionHas('message.type', 'warning');
    expect(session('message.message'))->toContain('non corrispondono più ai titolari di adesso')->toContain('Rossi Mario')->toContain('azzera i voti di quelle righe');

    // Azzerati i voti, l'elenco si riallinea ai titolari di adesso: due righe, ogni unità in una sola.
    AssembleaVoto::query()->delete();
    $esito = $servizio->prepara($a->fresh());
    expect($esito['rimaste'])->toBe([])->and($esito['doppie'])->toBe([])
        ->and($unitaPerRiga())->toEqualCanonicalizing([[$app1], [$box, $app2]])
        ->and($servizio->unitaInPiuRighe($a))->toBe([]);

    // Un elenco di prima della correzione, con la stessa unità in due righe: la pagina lo segnala fra gli avvisi.
    AssembleaPresenza::create(['assemblea_id' => $a->id, 'anagrafica_id' => $p['Rossi Mario']->id, 'tipo' => 'presente', 'millesimi' => '40', 'unita' => [$box]]);
    $avvisi = collect(app(ConteggiAssemblea::class)->avvisi(Assemblea::with(['presenze.anagrafica', 'presenze.delegato', 'punti.voti'])->find($a->id)))->pluck('testo')->implode("\n");
    expect($servizio->unitaInPiuRighe($a))->toBe([$box])
        ->and($avvisi)->toContain('Un\'unità compare in più di una riga dell\'elenco');
});

// --- 13. Gli elenchi numerati nella stampa ------------------------------------------------------------------------

it('⚠️ negli elenchi numerati il numero stampato è quello scritto', function () {
    // Come prima: un elenco che parte da 1 e prosegue.
    expect(TestoNota::html("1. uno\n2. due"))->toBe('<ol><li>uno</li><li>due</li></ol>')
        // Una delibera a più capi, con una riga vuota fra un capo e l'altro: il secondo resta «2.».
        ->and(TestoNota::html("1. di approvare il rendiconto;\n\n2. di approvare il riparto."))->toBe('<ol><li>di approvare il rendiconto;</li></ol><ol start="2"><li>di approvare il riparto.</li></ol>')
        // Un paragrafo in mezzo.
        ->and(TestoNota::html("1. uno\nTesto in mezzo.\n2. due", true))->toBe('<ol><li>uno</li></ol><p>Testo in mezzo.</p><ol start="2"><li>due</li></ol>')
        // Un elenco che comincia da 3, e un salto.
        ->and(TestoNota::html("3. tre\n4. quattro"))->toBe('<ol start="3"><li>tre</li><li>quattro</li></ol>')
        ->and(TestoNota::html("1. uno\n3. tre"))->toBe('<ol><li>uno</li></ol><ol start="3"><li>tre</li></ol>')
        // I trattini non c'entrano.
        ->and(TestoNota::html("- a\n- b\n2. due"))->toBe('<ul><li>a</li><li>b</li></ul><ol start="2"><li>due</li></ol>')
        // «0.» non si può stampare come numero d'elenco: resta testo, com'è scritto.
        ->and(TestoNota::html('0. zero'))->toBe('<p>0. zero</p>');
});

// --- 6. Il regolamento ---------------------------------------------------------------------------------------------

it('⚠️ il regolamento non è fra le delibere ordinarie: sta con la maggioranza degli intervenuti e metà del valore, in ogni convocazione', function () {
    expect(mb_strtolower(MaggioranzaAssemblea::ORDINARIA->aCheServe()))->not->toContain('regolamento')
        ->and(MaggioranzaAssemblea::ART1136C4->aCheServe())->toContain('approvazione e modifica del regolamento (art. 1138 c.c.)')
        // I quorum non cambiano.
        ->and(MaggioranzaAssemblea::ORDINARIA->quotaDelValore('seconda'))->toBe([1, 3])
        ->and(MaggioranzaAssemblea::ART1136C4->quotaDelValore('prima'))->toBe([1, 2])
        ->and(MaggioranzaAssemblea::ART1136C4->quotaDelValore('seconda'))->toBe([1, 2]);

    $guida = file_get_contents(resource_path('js/components/gestionale/assemblee/AssembleeGuide.vue'));
    expect($guida)->toContain('approvazione e modifica del regolamento (art. 1138 c.c.)')
        ->and($guida)->not->toContain('regolamento che non tocca i diritti dei singoli');
});

// --- 15. Le presenze restano anche senza l'anagrafica -------------------------------------------------------------

it('⚠️ eliminando un\'anagrafica le presenze e i voti dell\'assemblea restano, con il nome fotografato; e così il delegato', function () {
    $s = corAssemblea();
    $a = $s['assemblea'];
    $p = $s['persone'];
    $carla = asmPresenza($a, $p['Carla Conti']);
    $lucia = Anagrafica::forceCreate(['nome' => 'Lucia Verdi', 'email' => 'lucia@prova.it', 'indirizzo' => 'Via Roma 2', 'codice_fiscale' => 'VRDLCU80A41H501X']);

    $chiavi = collect(Schema::getForeignKeys('assemblea_presenze'))->mapWithKeys(fn ($k) => [implode(',', $k['columns']) => strtolower((string) $k['on_delete'])])->all();
    expect($chiavi)->toEqual(['assemblea_id' => 'cascade', 'anagrafica_id' => 'set null', 'delegato_anagrafica_id' => 'set null'])
        // L'elenco preparato fotografa il nome nella riga.
        ->and($carla->nome)->toBe('Carla Conti');

    // Carla per delega a Lucia, scelta in rubrica: anche il nome del delegato resta nella riga.
    $this->put(corUrl('presenze.update', $a, ['presenza' => $carla->id]), ['delegato_anagrafica_id' => $lucia->id])->assertSessionHasNoErrors();
    expect($carla->fresh()->delegato_nome)->toBe('Lucia Verdi')->and($carla->fresh()->tipo)->toBe('delega');

    // Le due anagrafiche vengono eliminate.
    DB::table('anagrafiche')->whereIn('id', [$p['Carla Conti']->id, $lucia->id])->delete();

    $carla = AssembleaPresenza::find($carla->id);
    expect($carla)->not->toBeNull('la presenza non deve sparire con l\'anagrafica')
        ->and($carla->anagrafica_id)->toBeNull()
        ->and($carla->delegato_anagrafica_id)->toBeNull()
        ->and($carla->nomePersona())->toBe('Carla Conti')
        ->and($carla->nomeDelegato())->toBe('Lucia Verdi')
        ->and(AssembleaVoto::where('assemblea_presenza_id', $carla->id)->count())->toBe(2);

    // I conteggi e il verbale la nominano ancora, e la pagina si apre.
    $v = asmVotazione($s['punti'][0]);
    expect($v['astenuti']['nomi'][0]['nome'])->toBe('Carla Conti')->and($v['astenuti']['nomi'][0]['delegato'])->toBe('Lucia Verdi');
    expect(app(VerbaleService::class)->compila(Assemblea::find($a->id)))->toContain('Carla Conti per delega a Lucia Verdi (200,00)');
    $this->get(corUrl('show', $a))->assertOk()
        ->assertInertia(fn ($pagina) => $pagina->where('fotografia.presenze.2.nome', 'Carla Conti')->where('fotografia.presenze.2.anagrafica_id', null)->where('fotografia.presenze.2.delegato', 'Lucia Verdi'));

    // Togliere il delegato scelto in rubrica toglie anche il suo nome dalla riga.
    $bruno = asmPresenza($a, $p['Bruno Bassi']);
    $this->put(corUrl('presenze.update', $a, ['presenza' => $bruno->id]), ['delegato_anagrafica_id' => $p['Anna Alberti']->id])->assertSessionHasNoErrors();
    expect($bruno->fresh()->delegato_nome)->toBe('Anna Alberti');
    $this->put(corUrl('presenze.update', $a, ['presenza' => $bruno->id]), ['delegato_anagrafica_id' => null])->assertSessionHasNoErrors();
    expect($bruno->fresh()->delegato_nome)->toBeNull()->and($bruno->fresh()->nomeDelegato())->toBeNull();
});

it('all\'approvazione i nomi di adesso restano scritti nelle righe delle presenze', function () {
    $s = corAssemblea();
    $a = $s['assemblea'];
    $p = $s['persone'];
    // Dopo aver preparato l'elenco, un nome viene corretto in rubrica.
    $p['Bruno Bassi']->forceFill(['nome' => 'Bruno Bassi Rinaldi'])->save();
    expect(asmPresenza($a, $p['Bruno Bassi'])->nome)->toBe('Bruno Bassi');

    $this->post(corUrl('verbale.genera', $a))->assertSessionHasNoErrors();
    $this->post(corUrl('verbale.invia', $a))->assertSessionHasNoErrors();
    $this->post(corUrl('verbale.approva', $a))->assertSessionHasNoErrors();

    expect(corStato($a))->toBe(Verbale::APPROVATO)
        ->and(asmPresenza($a, $p['Bruno Bassi'])->nome)->toBe('Bruno Bassi Rinaldi');
});

it('la migrazione delle correzioni interrotta a metà si riprende: la chiave torna SET NULL, il nome si riempie, e una seconda passata non cambia niente', function () {
    $s = corAssemblea();
    $carla = asmPresenza($s['assemblea'], $s['persone']['Carla Conti']);
    $migrazione = require database_path('migrations/2026_11_17_130000_correzioni_verbale_assemblee.php');
    $chiavi = fn () => collect(Schema::getForeignKeys('assemblea_presenze'))
        ->map(fn ($k) => implode(',', $k['columns']).'>'.$k['foreign_table'].' '.strtolower((string) $k['on_delete']))->sort()->values()->all();
    $piene = ['anagrafica_id>anagrafiche set null', 'assemblea_id>assemblee cascade', 'delegato_anagrafica_id>anagrafiche set null'];
    expect($chiavi())->toBe($piene)
        ->and(Schema::hasColumn('verbali', 'impronta_dati'))->toBeTrue();

    // Lo stato a metà: la chiave vecchia è già stata tolta e quella nuova non c'è ancora; un nome manca.
    Schema::table('assemblea_presenze', fn (Blueprint $table) => $table->dropForeign(['anagrafica_id']));
    DB::table('assemblea_presenze')->where('id', $carla->id)->update(['nome' => null]);
    expect($chiavi())->toBe(['assemblea_id>assemblee cascade', 'delegato_anagrafica_id>anagrafiche set null']);

    $migrazione->up();

    // (I voti qui non si contano: il test gira dentro una transazione, dove SQLite non spegne le chiavi esterne mentre
    // ricostruisce la tabella. La migrazione vera non gira in una transazione, e su MySQL e MariaDB non ricostruisce niente.)
    expect($chiavi())->toBe($piene)
        ->and($carla->fresh()->nome)->toBe('Carla Conti')
        ->and(AssembleaPresenza::where('assemblea_id', $s['assemblea']->id)->count())->toBe(3);

    $migrazione->up();
    expect($chiavi())->toBe($piene);
});
