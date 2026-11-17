<?php

/**
 * # L'avviso di convocazione e il foglio delle firme (Web Pro Italia, 17/11/2026)
 *
 * La convocazione parte da un modello dello studio (tipo `convocazione`, quello di serie è seminato dalla migrazione),
 * il programma ne genera il testo dai dati dell'assemblea e il testo poi è libero. Si prova che: i modelli hanno un
 * tipo e un predefinito per tipo; i segnaposto prendono i dati giusti (le due convocazioni, il luogo, l'ordine del
 * giorno, la modalità e il collegamento) e quelli del destinatario restano nel testo e si riempiono alla stampa; il
 * testo scritto non si sovrascrive senza conferma; le stampe (generica, per persona, .zip, foglio delle firme) sono PDF
 * con i nomi giusti; la pagina avvisa se mancano i cinque giorni dell'art. 66 disp. att. c.c.; un agente AI legge,
 * genera, corregge e scarica.
 */

use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\VerbaleModello;
use App\Models\ChiaveAgente;
use App\Models\Gestionale\RegistroInvio;
use App\Services\Assemblee\AventiDiritto;
use App\Services\Assemblee\ConvocazioneService;
use App\Services\Assemblee\DestinatariAssemblea;
use App\Services\Assemblee\ModelliDiSerie;
use App\Services\Assemblee\ModelloCanonico;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;

require_once __DIR__.'/ConvocazioneHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->utente = asmAmministratore();
    $this->actingAs($this->utente);
    Storage::fake('local');
    // Il 12 novembre: alla prima convocazione del 22 mancano dieci giorni.
    $this->travelTo('2026-11-12 10:00:00');
});

it('i modelli hanno un tipo: la migrazione semina i tre di serie, ognuno predefinito del suo tipo, e quello del verbale resta com\'è', function () {
    expect(VerbaleModello::predefinito()->corpo)->toBe(ModelloCanonico::CORPO)
        ->and(VerbaleModello::predefinito()->tipo)->toBe('verbale');

    foreach ([VerbaleModello::CONVOCAZIONE => ModelliDiSerie::CONVOCAZIONE, VerbaleModello::EMAIL_CONVOCAZIONE => ModelliDiSerie::EMAIL_CONVOCAZIONE,
        VerbaleModello::TRASMISSIONE_VERBALE => ModelliDiSerie::TRASMISSIONE_VERBALE] as $tipo => $corpo) {
        $modello = VerbaleModello::predefinito($tipo);
        expect($modello)->not->toBeNull()
            ->and($modello->tipo)->toBe($tipo)
            ->and($modello->predefinito)->toBeTrue()
            ->and($modello->corpo)->toBe($corpo);
    }
    expect(VerbaleModello::where('predefinito', true)->count())->toBe(4);
});

it('un predefinito per tipo: quello nuovo della convocazione non toglie quello del verbale, e un modello di un altro tipo non genera', function () {
    $s = cnvAssemblea();
    $a = $s['assemblea'];
    $verbale = VerbaleModello::predefinito();

    $this->post(route('admin.gestionale.assemblee.modelli.store', ['condominio' => $a->condominio_id]),
        ['tipo' => 'convocazione', 'nome' => 'Convocazione dello studio', 'corpo' => 'Convocati per {{prima.giorno}}. {{inventato}}', 'predefinito' => true])->assertSessionHasNoErrors();

    $nuovo = VerbaleModello::where('nome', 'Convocazione dello studio')->firstOrFail();
    expect($nuovo->tipo)->toBe('convocazione')
        ->and(VerbaleModello::predefinito(VerbaleModello::CONVOCAZIONE)->id)->toBe($nuovo->id)
        ->and(VerbaleModello::where('tipo', 'convocazione')->where('predefinito', true)->count())->toBe(1)
        ->and(VerbaleModello::predefinito()->id)->toBe($verbale->id);

    // La pagina dei modelli porta i quattro tipi, con i segnaposto di ognuno, e segnala lo sconosciuto.
    $this->get(route('admin.gestionale.assemblee.modelli.index', ['condominio' => $a->condominio_id]))->assertOk()
        ->assertInertia(fn ($p) => $p->component('gestionale/assemblee/ModelliVerbale')
            ->has('tipi', 4)
            ->where('tipi.1.tipo', 'convocazione')
            ->has('tipi.1.segnaposti.destinatario')
            ->where('modelli', fn ($modelli) => collect($modelli)->firstWhere('id', $nuovo->id)['sconosciuti'] === ['inventato']));

    // Un modello di verbale non genera una convocazione, e viceversa.
    $this->post(cnvIndirizzo('convocazione.genera', $a), ['modello_id' => $verbale->id])->assertSessionHasErrors('modello_id');
    $this->post(cnvIndirizzo('verbale.genera', $a), ['modello_id' => $nuovo->id])->assertSessionHasErrors('modello_id');

    // Eliminato il predefinito, lo diventa il primo che resta dello stesso tipo.
    $this->delete(route('admin.gestionale.assemblee.modelli.destroy', ['condominio' => $a->condominio_id, 'modello' => $nuovo->id]))->assertSessionHasNoErrors();
    expect(VerbaleModello::predefinito(VerbaleModello::CONVOCAZIONE)->corpo)->toBe(ModelliDiSerie::CONVOCAZIONE)
        ->and(VerbaleModello::predefinito(VerbaleModello::CONVOCAZIONE)->predefinito)->toBeTrue()
        ->and(VerbaleModello::predefinito()->id)->toBe($verbale->id);
});

it('genera il testo dal modello di serie: le due convocazioni, il luogo, l\'ordine del giorno, la modalità, la delega dell\'art. 67 e il modulo in fondo', function () {
    $s = cnvAssemblea();
    $a = $s['assemblea'];

    $this->post(cnvIndirizzo('convocazione.genera', $a))->assertSessionHasNoErrors();
    $testo = $a->fresh()->convocazione_testo;

    expect($testo)->toContain('# AVVISO DI CONVOCAZIONE DELL\'ASSEMBLEA ORDINARIA')
        ->toContain('Ai Signori Condòmini del Condominio '.$s['condominio']->nome)
        ->toContain('in **prima convocazione** il giorno **domenica 22 novembre 2026** alle ore **9.00** presso la sala condominiale, Via Roma 1')
        ->toContain('e, occorrendo, in **seconda convocazione** il giorno **lunedì 23 novembre 2026** alle ore **18.30** presso la sala condominiale, Via Roma 1')
        ->toContain("# Ordine del giorno\n1. Approvazione del rendiconto 2025 — Esame del rendiconto e del riparto\n2. Varie ed eventuali\n")
        ->toContain('L\'assemblea si tiene in presenza.')
        ->toContain('delega scritta (art. 67 delle disposizioni di attuazione del codice civile)')
        ->toContain('12 novembre 2026')
        ->toContain("\n---\n")
        ->toContain('# DELEGA')
        // I segnaposto del destinatario restano nel testo: li riempie la stampa.
        ->toContain('Il/La sottoscritto/a {{destinatario.nome}}, condòmino/a del Condominio '.$s['condominio']->nome.' per l\'unità {{destinatario.unita}}')
        ->not->toContain('Collegamento per partecipare')
        // La regola dei cinque giorni non è una frase del modello.
        ->not->toContain('cinque giorni')->not->toContain('art. 66')
        ->and($a->fresh()->convocazione_modello_id)->toBe(VerbaleModello::predefinito(VerbaleModello::CONVOCAZIONE)->id);

    // Alla stampa: una riga da compilare nell'avviso generico, il nome e le unità in quello della persona.
    $servizio = app(ConvocazioneService::class);
    $anna = collect(app(DestinatariAssemblea::class)->persone($a->fresh()))->firstWhere('nome', 'Anna Alberti');
    expect($servizio->testoPer($a->fresh(), null))->toContain('Il/La sottoscritto/a ______________________________, condòmino/a')->not->toContain('{{')
        ->and($servizio->testoPer($a->fresh(), $anna))->toContain('Il/La sottoscritto/a Anna Alberti, condòmino/a del Condominio '.$s['condominio']->nome.' per l\'unità Int. 1,')->not->toContain('{{');
});

it('senza seconda convocazione la frase sparisce; in videoconferenza il collegamento c\'è; una data che manca è una riga da riempire', function () {
    $s = cnvAssemblea(['data_seconda_convocazione' => null, 'modalita' => 'mista', 'collegamento_videoconferenza' => 'https://riunioni.example/abc']);
    $testo = app(ConvocazioneService::class)->compila($s['assemblea']->fresh());

    expect($testo)->not->toContain('seconda convocazione')
        ->toContain('presso la sala condominiale, Via Roma 1 e in videoconferenza, per discutere')
        ->toContain('All\'assemblea si può partecipare di persona oppure in videoconferenza. Collegamento per partecipare a distanza: https://riunioni.example/abc')
        ->toContain('convocata per il giorno domenica 22 novembre 2026 in prima convocazione, con ogni facoltà');

    $senzaData = cnvAssemblea(['data_prima_convocazione' => null, 'data_seconda_convocazione' => null]);
    expect(app(ConvocazioneService::class)->compila($senzaData['assemblea']->fresh()))->toContain('il giorno **______** alle ore **______**');
});

it('rigenerare sopra un testo scritto chiede la conferma; il testo e il collegamento si salvano; con il verbale approvato non si tocca', function () {
    $s = cnvAssemblea();
    $a = $s['assemblea'];

    $this->post(cnvIndirizzo('convocazione.genera', $a))->assertSessionHasNoErrors();
    $this->put(cnvIndirizzo('convocazione.update', $a), ['testo' => 'La convocazione scritta a mano.', 'collegamento_videoconferenza' => ' https://riunioni.example/x '])->assertSessionHasNoErrors();
    expect($a->fresh()->convocazione_testo)->toBe('La convocazione scritta a mano.')
        ->and($a->fresh()->collegamento_videoconferenza)->toBe('https://riunioni.example/x');

    $this->post(cnvIndirizzo('convocazione.genera', $a))->assertSessionHasErrors('sovrascrivi');
    expect($a->fresh()->convocazione_testo)->toBe('La convocazione scritta a mano.');

    $this->post(cnvIndirizzo('convocazione.genera', $a), ['sovrascrivi' => true])->assertSessionHasNoErrors();
    expect($a->fresh()->convocazione_testo)->toContain('AVVISO DI CONVOCAZIONE');

    // Solo il collegamento: il testo resta.
    $this->put(cnvIndirizzo('convocazione.update', $a), ['collegamento_videoconferenza' => null])->assertSessionHasNoErrors();
    expect($a->fresh()->convocazione_testo)->toContain('AVVISO DI CONVOCAZIONE')->and($a->fresh()->collegamento_videoconferenza)->toBeNull();

    \App\Models\Assemblee\Verbale::create(['assemblea_id' => $a->id, 'stato' => 'approvato', 'versione' => 1]);
    $this->put(cnvIndirizzo('convocazione.update', $a), ['testo' => 'cambiata'])->assertSessionHasErrors('verbale');
    $this->post(cnvIndirizzo('convocazione.genera', $a), ['sovrascrivi' => true])->assertSessionHasErrors('verbale');
});

it('l\'avviso generico è un PDF con il testo e il modulo di delega in bianco, staccato; prima di avere un testo è una bozza', function () {
    $s = cnvAssemblea();
    $a = $s['assemblea'];
    $spia = cnvSpia();

    // Senza un testo salvato: quello che darebbe il modello, con la filigrana.
    $bozza = $this->get(cnvIndirizzo('convocazione.stampa', $a));
    $bozza->assertOk();
    expect($bozza->headers->get('Content-Type'))->toBe('application/pdf')
        ->and(substr($bozza->getContent(), 0, 5))->toBe('%PDF-')
        ->and($spia->stampe[0]['config']['watermarkText'] ?? null)->toBe('BOZZA');

    $this->post(cnvIndirizzo('convocazione.genera', $a))->assertSessionHasNoErrors();
    $risposta = $this->get(cnvIndirizzo('convocazione.stampa', $a));
    $risposta->assertOk();
    $stampa = $spia->stampe[1];

    expect(substr($risposta->getContent(), 0, 5))->toBe('%PDF-')
        ->and($risposta->headers->get('Content-Disposition'))->toContain('convocazione-assemblea')
        ->and($stampa['vista'])->toBe('pdf.gestionale.lettera_assemblea')
        ->and($stampa['dati']['gruppo_carta'])->toBe(\App\Services\PDF\GruppoCarta::RENDICONTO_E_ASSEMBLEA)
        ->and($stampa['config'])->not->toHaveKey('watermarkText')
        ->and($stampa['dati']['fogli'])->toHaveCount(1)
        ->and($stampa['dati']['fogli'][0]['destinatario'])->toBeNull()
        // Due parti: l'avviso, e il modulo di delega dopo la riga di trattini.
        ->and($stampa['dati']['fogli'][0]['parti'])->toHaveCount(2)
        ->and($stampa['dati']['fogli'][0]['parti'][0])->toContain('AVVISO DI CONVOCAZIONE')->not->toContain('---')
        ->and($stampa['dati']['fogli'][0]['parti'][1])->toStartWith('# DELEGA')->toContain('Il/La sottoscritto/a ______________________________,');

    $html = View::make($stampa['vista'], $stampa['dati'])->render();
    expect($html)->toContain('domenica 22 novembre 2026')
        ->toContain('da ritagliare e consegnare')
        ->toContain('<li>Approvazione del rendiconto 2025 — Esame del rendiconto e del riparto</li>')
        ->not->toContain('{{');
});

it('la stampa per persona: un foglio per ogni titolare, con il nome e l\'indirizzo in alto e la sua delega già intestata; anche uno alla volta e in uno .zip', function () {
    $s = cnvAssemblea();
    $a = $s['assemblea'];
    $this->post(cnvIndirizzo('convocazione.genera', $a))->assertSessionHasNoErrors();
    $spia = cnvSpia();

    $risposta = $this->get(cnvIndirizzo('convocazione.per-persona.stampa', $a));
    $risposta->assertOk();
    $fogli = $spia->stampe[0]['dati']['fogli'];

    // Quattro persone: i due comproprietari dell'Int. 2 ricevono ognuno il suo avviso.
    expect(substr($risposta->getContent(), 0, 5))->toBe('%PDF-')
        ->and($risposta->headers->get('Content-Disposition'))->toContain('per-persona')
        ->and(array_map(fn ($f) => $f['destinatario']['nome'], $fogli))->toBe(['Anna Alberti', 'Bruno Bassi', 'Carla Conti', 'Dino Dei'])
        ->and($fogli[0]['destinatario']['indirizzo'])->toBe('Via Roma 1, 03100 Frosinone')
        ->and($fogli[0]['parti'][1])->toContain('Il/La sottoscritto/a Anna Alberti,')->toContain('per l\'unità Int. 1,')
        ->and($fogli[2]['parti'][1])->toContain('Il/La sottoscritto/a Carla Conti,')->toContain('per l\'unità Int. 2,');

    $html = View::make($spia->stampe[0]['vista'], $spia->stampe[0]['dati'])->render();
    expect($html)->toContain('Anna Alberti')->toContain('Via Roma 1, 03100 Frosinone')->toContain('Dino Dei')
        ->and(substr_count($html, '<pagebreak resetpagenum="1" />'))->toBe(3);

    // Una persona sola, e una che non è fra gli aventi diritto.
    $una = $this->get(cnvIndirizzo('convocazione.stampa', $a, ['persona' => $s['persone']['Dino Dei']->id]));
    $una->assertOk();
    expect(substr($una->getContent(), 0, 5))->toBe('%PDF-')
        ->and($una->headers->get('Content-Disposition'))->toContain('dino-dei')
        ->and(array_map(fn ($f) => $f['destinatario']['nome'], $spia->stampe[1]['dati']['fogli']))->toBe(['Dino Dei']);
    $this->get(cnvIndirizzo('convocazione.stampa', $a, ['persona' => 999999]))->assertNotFound();

    // Lo .zip: un PDF per persona e l'elenco degli indirizzi.
    $zip = $this->get(cnvIndirizzo('convocazione.zip', $a));
    $zip->assertOk();
    expect($zip->headers->get('Content-Type'))->toBe('application/zip');
    $archivio = new ZipArchive;
    expect($archivio->open($zip->baseResponse->getFile()->getPathname()))->toBeTrue();
    $nomi = collect(range(0, $archivio->numFiles - 1))->map(fn ($i) => $archivio->getNameIndex($i))->sort()->values()->all();
    $indirizzi = $archivio->getFromName('Indirizzi email.txt');
    $pdfDiAnna = $archivio->getFromName('Anna Alberti.pdf');
    $archivio->close();
    expect($nomi)->toBe(['Anna Alberti.pdf', 'Bruno Bassi.pdf', 'Carla Conti.pdf', 'Dino Dei.pdf', 'Indirizzi email.txt'])
        ->and(substr($pdfDiAnna, 0, 5))->toBe('%PDF-')
        ->and($indirizzi)->toContain('Anna Alberti — 1 avviso — Anna Alberti.pdf')->toContain($s['persone']['Anna Alberti']->email)
        ->toContain("Dino Dei — 1 avviso — Dino Dei.pdf\r\n  nessun indirizzo email");

    // Nessuna stampa scrive nel registro degli invii.
    expect(RegistroInvio::count())->toBe(0);
});

it('il foglio delle firme: una riga per avente diritto con unità e millesimi, i totali, calcolato senza salvare se l\'elenco non è pronto', function () {
    $s = cnvAssemblea();
    $a = $s['assemblea'];
    $spia = cnvSpia();

    $risposta = $this->get(cnvIndirizzo('foglio-firme.stampa', $a));
    $risposta->assertOk();
    $dati = $spia->stampe[0]['dati'];

    expect($risposta->headers->get('Content-Type'))->toBe('application/pdf')
        ->and(substr($risposta->getContent(), 0, 5))->toBe('%PDF-')
        ->and($risposta->headers->get('Content-Disposition'))->toContain('foglio-firme-assemblea')
        ->and($spia->stampe[0]['vista'])->toBe('pdf.gestionale.foglio_firme_assemblea')
        ->and($spia->stampe[0]['config']['orientation'])->toBe('L')
        // Tre teste: i comproprietari dell'Int. 2 sono una riga sola, con chi ha la quota più alta e l'altro accanto.
        ->and(array_map(fn ($r) => [$r['nome'], $r['unita'], $r['millesimi']], $dati['righe']))->toBe([
            ['Anna Alberti', 'Int. 1', '450,00'], ['Bruno Bassi', 'Int. 2', '350,00'], ['Dino Dei', 'Int. 3', '200,00'],
        ])
        ->and($dati['righe'][1]['contitolari'])->toBe(['Carla Conti'])
        ->and($dati['teste'])->toBe(3)
        ->and($dati['millesimi_totali'])->toBe('1.000,00')
        // L'elenco delle presenze non è stato preparato: il foglio lo ha calcolato senza salvarlo.
        ->and(DB::table('assemblea_presenze')->where('assemblea_id', $a->id)->count())->toBe(0);

    $html = View::make($spia->stampe[0]['vista'], $dati)->render();
    expect($html)->toContain('Firma (presente)')->toContain('Delegato a')->toContain('Firma del delegato')
        ->toContain('Anna Alberti')->toContain('anche per Carla Conti')->toContain('Dino Dei')
        ->toContain('3 condòmini per <strong>1.000,00</strong> millesimi')
        ->toContain('Il presidente')->toContain('Il segretario');

    // Con l'elenco preparato vale quello: il rappresentante scelto, e la delega già segnata.
    app(AventiDiritto::class)->prepara($a);
    asmPresenza($a, $s['persone']['Bruno Bassi'])->forceFill(['anagrafica_id' => $s['persone']['Carla Conti']->id, 'tipo' => 'delega', 'delegato_nome' => 'Avv. Rossi'])->save();
    $this->get(cnvIndirizzo('foglio-firme.stampa', $a))->assertOk();
    $righe = $spia->stampe[1]['dati']['righe'];
    expect(array_map(fn ($r) => $r['nome'], $righe))->toBe(['Anna Alberti', 'Carla Conti', 'Dino Dei'])
        ->and($righe[1]['contitolari'])->toBe(['Bruno Bassi'])
        ->and($righe[1]['delegato'])->toBe('Avv. Rossi');
});

it('la pagina avvisa se fra l\'avviso e la prima convocazione ci sono meno di cinque giorni, e non lo scrive nel testo', function () {
    $s = cnvAssemblea();
    $a = $s['assemblea'];
    $codici = fn () => array_column(app(ConvocazioneService::class)->avvisi($a->fresh()), 'codice');

    // Oggi è il 12: alla prima convocazione del 22 mancano dieci giorni.
    expect($codici())->not->toContain('cinque_giorni');

    // Il 17 ne mancano cinque: basta. Il 18 ne mancano quattro: la pagina lo dice.
    $this->travelTo('2026-11-17 09:00:00');
    expect($codici())->not->toContain('cinque_giorni');
    $this->travelTo('2026-11-18 09:00:00');
    $avviso = collect(app(ConvocazioneService::class)->avvisi($a->fresh()))->firstWhere('codice', 'cinque_giorni');
    expect($avviso['tipo'])->toBe('warning')
        ->and($avviso['testo'])->toContain('Se l\'avviso parte oggi (18/11/2026), fino alla prima convocazione (22/11/2026) ci sono 4 giorni')
        ->toContain('art. 66 disp. att. c.c.');

    // Con la data dell'avviso scritta il conto parte da quella, non da oggi.
    $a->forceFill(['data_avviso' => '2026-11-10'])->save();
    expect($codici())->not->toContain('cinque_giorni');
    $a->forceFill(['data_avviso' => '2026-11-19'])->save();
    expect(collect(app(ConvocazioneService::class)->avvisi($a->fresh()))->firstWhere('codice', 'cinque_giorni')['testo'])
        ->toContain('Fra la data dell\'avviso (19/11/2026) e la prima convocazione (22/11/2026) ci sono 3 giorni');

    // La pagina porta gli avvisi, e dice anche che l'elenco è calcolato e che il testo manca.
    $this->get(cnvIndirizzo('convocazione.show', $a))->assertOk()
        ->assertInertia(fn ($p) => $p->component('gestionale/assemblee/ConvocazioneEdit')
            ->where('avvisi', fn ($avvisi) => collect($avvisi)->pluck('codice')->all() === ['cinque_giorni', 'elenco_calcolato', 'senza_testo'])
            ->where('proposta', fn ($testo) => str_contains($testo, 'AVVISO DI CONVOCAZIONE') && ! str_contains($testo, 'cinque giorni'))
            ->has('modelli', 1)
            ->has('segnaposti.destinatario')
            ->where('invio.riepilogo.persone', 4));

    // Senza la data della prima convocazione lo dice, e un'unità senza titolari pure.
    $a->forceFill(['data_prima_convocazione' => null, 'data_avviso' => null])->save();
    DB::table('anagrafica_immobile')->where('immobile_id', $s['unita']['Int. 3']->id)->delete();
    expect($codici())->toContain('senza_data')->toContain('unita_senza_titolari')->not->toContain('cinque_giorni');
});

it('un agente AI legge la convocazione, la genera, la corregge e scarica i PDF; in sola lettura non scrive', function () {
    $s = cnvAssemblea();
    $a = $s['assemblea'];
    [, $chiave] = ChiaveAgente::genera('Assistente della convocazione', $this->utente, false);
    $agente = fn () => $this->withHeader('Authorization', 'Bearer '.$chiave);

    $agente()->postJson(cnvIndirizzo('convocazione.genera', $a))->assertOk();
    expect($a->fresh()->convocazione_testo)->toContain('AVVISO DI CONVOCAZIONE');
    // Sopra un testo che c'è, senza `sovrascrivi`: il rifiuto con il motivo.
    $rifiuto = $agente()->postJson(cnvIndirizzo('convocazione.genera', $a));
    $rifiuto->assertStatus(422);
    expect(json_encode($rifiuto->json(), JSON_UNESCAPED_UNICODE))->toContain('Conferma che vuoi sovrascriverlo');

    $agente()->putJson(cnvIndirizzo('convocazione.update', $a), ['testo' => "# Convocazione\nScritta dall'assistente.\n\n---\n\nDelega di {{destinatario.nome}}"])->assertOk();

    $pagina = $agente()->getJson(cnvIndirizzo('convocazione.show', $a))->assertOk()->json();
    expect($pagina['ok'])->toBeTrue()
        ->and($pagina['dati']['convocazione']['testo'])->toContain('Scritta dall\'assistente.')
        ->and($pagina['dati']['invio']['persone'])->toHaveCount(4)
        ->and($pagina['dati']['segnaposti']['semplici'])->toHaveKey('prima.giorno')
        ->and($pagina['dati']['per_gli_agenti'])->toHaveKey('invii_manda');

    // I PDF passano come file.
    foreach (['convocazione.stampa', 'convocazione.per-persona.stampa', 'foglio-firme.stampa'] as $rotta) {
        $pdf = $agente()->get(cnvIndirizzo($rotta, $a));
        $pdf->assertOk();
        expect($pdf->headers->get('Content-Type'))->toBe('application/pdf')->and(substr($pdf->getContent(), 0, 5))->toBe('%PDF-');
    }

    // Il catalogo le conosce: le stampe come file, le scritture con i loro campi.
    $catalogo = collect($agente()->getJson(route('agenti.catalogo'))->json('dati.rotte'))->keyBy('nome');
    expect($catalogo['admin.gestionale.assemblee.convocazione.stampa']['file'] ?? null)->toBeTrue()
        ->and($catalogo['admin.gestionale.assemblee.foglio-firme.stampa']['file'] ?? null)->toBeTrue()
        ->and($catalogo['admin.gestionale.assemblee.convocazione.zip']['file'] ?? null)->toBeTrue()
        ->and(array_keys($catalogo['admin.gestionale.assemblee.convocazione.update']['campi']))->toBe(['testo', 'collegamento_videoconferenza'])
        ->and(array_keys($catalogo['admin.gestionale.assemblee.invii.invia']['campi']))->toContain('conferma', 'persone');

    // Una chiave di sola lettura legge e stampa, ma non scrive.
    [, $sola] = ChiaveAgente::genera('Solo lettura', $this->utente, true);
    $this->withHeader('Authorization', 'Bearer '.$sola)->getJson(cnvIndirizzo('convocazione.show', $a))->assertOk();
    $this->withHeader('Authorization', 'Bearer '.$sola)->putJson(cnvIndirizzo('convocazione.update', $a), ['testo' => 'no'])->assertStatus(403);
    expect($a->fresh()->convocazione_testo)->toContain('Scritta dall\'assistente.');
});

it('la convocazione di un altro condominio non si apre', function () {
    $s = cnvAssemblea();
    $altro = cnvAssemblea();

    $this->get(route('admin.gestionale.assemblee.convocazione.show', ['condominio' => $altro['condominio']->id, 'assemblea' => $s['assemblea']->id]))->assertNotFound();
    $this->get(route('admin.gestionale.assemblee.foglio-firme.stampa', ['condominio' => $altro['condominio']->id, 'assemblea' => $s['assemblea']->id]))->assertNotFound();
    $this->put(route('admin.gestionale.assemblee.convocazione.update', ['condominio' => $altro['condominio']->id, 'assemblea' => $s['assemblea']->id]), ['testo' => 'x'])->assertNotFound();
    expect($s['assemblea']->fresh()->convocazione_testo)->toBeNull();
});
