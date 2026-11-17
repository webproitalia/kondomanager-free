<?php

/**
 * # Il verbale dell'assemblea (Web Pro Italia, 17/11/2026)
 *
 * Il verbale parte da un modello dello studio (quello di serie è seminato dalla migrazione e predefinito), il programma
 * ne genera il testo dai dati e il testo poi è libero. Si prova che: i segnaposto prendono i dati giusti (conteggi,
 * nomi dei contrari, la frase della registrazione); uno sconosciuto resta visibile; il testo scritto non si sovrascrive
 * senza conferma; la bozza dei punti riempie solo i campi vuoti; gli stati vanno bozza → da approvare → approvato; il
 * PDF approvato entra nell'archivio; dopo l'approvazione niente si modifica; un agente AI non può approvare.
 */

use App\Models\AzioneAgente;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\AssembleaPunto;
use App\Models\Assemblee\Verbale;
use App\Models\Assemblee\VerbaleModello;
use App\Models\CategoriaDocumento;
use App\Models\ChiaveAgente;
use App\Models\Documento;
use App\Services\Assemblee\AventiDiritto;
use App\Services\Assemblee\CompilatoreModello;
use App\Services\Assemblee\ModelloCanonico;
use App\Services\Assemblee\VerbaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/AssembleeHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->utente = asmAmministratore();
    $this->actingAs($this->utente);
    Storage::fake('local');
});

/** Un'assemblea tenuta: tre condòmini, due punti votati e uno informativo. */
function asmAssembleaTenuta(array $assemblea = []): array
{
    $s = asmCondominio([
        'Int. 1' => ['millesimi' => '450', 'titolari' => ['Anna Alberti' => 'proprietario']],
        'Int. 2' => ['millesimi' => '350', 'titolari' => ['Bruno Bassi' => 'proprietario']],
        'Int. 3' => ['millesimi' => '200', 'titolari' => ['Carla Conti' => 'proprietario']],
    ], array_merge([
        'convocazione_tenuta' => 'seconda', 'presidente' => 'Anna Alberti', 'segretario' => 'Mario Studio',
        'ora_inizio' => '18:30', 'ora_fine' => '20:05', 'stato' => 'chiusa', 'registrata' => true,
    ], $assemblea));
    app(AventiDiritto::class)->prepara($s['assemblea']);
    $p = $s['persone'];
    asmSegna($s['assemblea'], [$p['Anna Alberti'], $p['Bruno Bassi']]);
    asmSegna($s['assemblea'], [$p['Carla Conti']], 'delega', 'Bruno Bassi');

    $rendiconto = AssembleaPunto::create(['assemblea_id' => $s['assemblea']->id, 'numero' => 1, 'titolo' => 'Approvazione del rendiconto 2025', 'maggioranza' => 'ordinaria']);
    $lavori = AssembleaPunto::create(['assemblea_id' => $s['assemblea']->id, 'numero' => 2, 'titolo' => 'Rifacimento della facciata', 'maggioranza' => 'art1136c4']);
    $varie = AssembleaPunto::create(['assemblea_id' => $s['assemblea']->id, 'numero' => 3, 'titolo' => 'Varie ed eventuali', 'maggioranza' => 'informativa']);
    asmVota($rendiconto, [[$p['Anna Alberti'], 'favorevole'], [$p['Bruno Bassi'], 'favorevole'], [$p['Carla Conti'], 'astenuto']]);
    asmVota($lavori, [[$p['Anna Alberti'], 'favorevole'], [$p['Bruno Bassi'], 'contrario'], [$p['Carla Conti'], 'contrario']]);

    return $s + ['punti' => [$rendiconto, $lavori, $varie]];
}

function asmIndirizzo(string $nome, Assemblea $a, array $altri = []): string
{
    return route('admin.gestionale.assemblee.'.$nome, ['condominio' => $a->condominio_id, 'assemblea' => $a->id] + $altri);
}

it('la migrazione semina il modello di serie, predefinito, e la categoria «Verbali di assemblea»', function () {
    $modello = VerbaleModello::predefinito();

    expect($modello)->not->toBeNull()
        ->and($modello->predefinito)->toBeTrue()
        ->and($modello->corpo)->toBe(ModelloCanonico::CORPO)
        ->and(CategoriaDocumento::perVerbaliAssemblea()?->name)->toBe('Verbali di assemblea');
});

it('genera il testo dal modello di serie con i dati dell\'assemblea: costituzione, registrazione, voti con i nomi, esito', function () {
    $s = asmAssembleaTenuta();
    [$rendiconto, $lavori] = $s['punti'];
    $rendiconto->forceFill(['esito' => 'approvato', 'discussione' => 'L\'amministratore illustra le voci di spesa.'])->save();

    $verbale = app(VerbaleService::class)->genera($s['assemblea']->fresh(), null, false);
    $testo = $verbale->testo;

    expect($testo)->toContain('# VERBALE DI ASSEMBLEA ORDINARIA DEI CONDÒMINI')
        ->and($testo)->toContain('**Condominio '.$s['condominio']->nome.'**')
        ->and($testo)->toContain('Il giorno 23 novembre 2026, alle ore 18.30, presso la sala condominiale, Via Roma 1, si è riunita in seconda convocazione')
        ->and($testo)->toContain('sono intervenuti, di persona o per delega, 3 condòmini su 3, che rappresentano 1.000,00 millesimi su 1.000')
        ->and($testo)->toContain('L\'assemblea, in seconda convocazione, è validamente costituita')
        ->and($testo)->toContain('La riunione viene registrata al solo fine di redigere il verbale, e i presenti ne sono informati.')
        ->and($testo)->toContain('**Discussione.** L\'amministratore illustra le voci di spesa.')
        ->and($testo)->toContain('Astenuti: 1 condòmino per 200,00 millesimi (Carla Conti per delega a Bruno Bassi (200,00)).')
        ->and($testo)->toContain('Contrari: 2 condòmini per 550,00 millesimi (Bruno Bassi (350,00), Carla Conti per delega a Bruno Bassi (200,00)).')
        ->and($testo)->toContain('**L\'assemblea approva.**')
        // Il punto 2 non ha un esito confermato: il verbale lo dice, con la proposta dei voti.
        ->and($testo)->toContain('Esito da confermare (secondo i voti: respinto).')
        ->and($testo)->toContain('Punto informativo: nessuna votazione.')
        ->and($testo)->toContain('dichiara chiusa l\'assemblea alle ore 20.05')
        ->and($testo)->toContain('Il presidente: ______________________ (Anna Alberti)')
        ->and($testo)->not->toContain('{{')
        ->and($verbale->modello_id)->toBe(VerbaleModello::predefinito()->id);
});

it('senza registrazione la frase non c\'è; un dato che manca esce come una riga da riempire', function () {
    $s = asmAssembleaTenuta(['registrata' => false, 'presidente' => null]);

    $testo = app(VerbaleService::class)->compila($s['assemblea']->fresh());

    expect($testo)->not->toContain('viene registrata')
        ->and($testo)->toContain('Assume la presidenza dell\'assemblea ______;');
});

it('il sostituto: un segnaposto sconosciuto resta visibile, un blocco vuoto sparisce, le graffe dei valori restano testo', function () {
    $testo = CompilatoreModello::compila(
        "Ciao {{presidente}} {{inventato}}\n{{#vuoto}}non si vede{{/vuoto}}{{#punti}}- {{numero}}: {{delibera}}\n{{/punti}}",
        ['presidente' => 'Anna', 'vuoto' => ''],
        ['punti' => [['numero' => '1', 'delibera' => 'cita {{presidente}}'], ['numero' => '2', 'delibera' => 'ok']]],
    );

    expect($testo)->toBe("Ciao Anna {{inventato}}\n- 1: cita {{presidente}}\n- 2: ok\n")
        ->and(CompilatoreModello::sconosciuti('{{presidente}} {{#punti}}{{numero}}{{/punti}} {{nonce}}', ['presidente', 'numero'], ['punti' => true]))->toBe(['nonce']);
});

it('rigenerare dal modello sopra un testo scritto chiede la conferma', function () {
    $s = asmAssembleaTenuta();
    $a = $s['assemblea'];

    $this->post(asmIndirizzo('verbale.genera', $a))->assertSessionHasNoErrors();
    $this->put(asmIndirizzo('verbale.update', $a), ['testo' => 'Il verbale scritto a mano.'])->assertSessionHasNoErrors();

    $this->post(asmIndirizzo('verbale.genera', $a))->assertSessionHasErrors('sovrascrivi');
    expect(Verbale::where('assemblea_id', $a->id)->value('testo'))->toBe('Il verbale scritto a mano.');

    $this->post(asmIndirizzo('verbale.genera', $a), ['sovrascrivi' => true])->assertSessionHasNoErrors();
    expect(Verbale::where('assemblea_id', $a->id)->value('testo'))->toContain('VERBALE DI ASSEMBLEA');
});

it('precompilaDaBozza riempie solo i campi vuoti e dice quanti', function () {
    $s = asmAssembleaTenuta();
    [$rendiconto, $lavori] = $s['punti'];
    $rendiconto->forceFill(['discussione' => 'Scritta dalla persona.'])->save();

    $riempiti = app(VerbaleService::class)->precompilaDaBozza($s['assemblea'], [
        ['numero' => 1, 'discussione' => 'Dalla registrazione.', 'delibera' => 'Si approva il rendiconto.'],
        ['numero' => 2, 'discussione' => 'Si discute della facciata.'],
        ['numero' => 9, 'discussione' => 'Un punto che non c\'è.'],
        ['numero' => 2, 'delibera' => '   '],
    ], 'registrazione');

    expect($riempiti)->toBe(2)
        ->and($rendiconto->fresh()->discussione)->toBe('Scritta dalla persona.')
        ->and($rendiconto->fresh()->delibera)->toBe('Si approva il rendiconto.')
        ->and($lavori->fresh()->discussione)->toBe('Si discute della facciata.')
        ->and($lavori->fresh()->delibera)->toBeNull();

    // La seconda volta non c'è più niente di vuoto da riempire con quella bozza.
    expect(app(VerbaleService::class)->precompilaDaBozza($s['assemblea'], [['numero' => 1, 'discussione' => 'Altro.', 'delibera' => 'Altro.']], 'registrazione'))->toBe(0);
});

it('gli stati: bozza → da approvare (con i controlli) → approvato, con il PDF nell\'archivio; poi niente si modifica', function () {
    $s = asmAssembleaTenuta(['segretario' => null]);
    $a = $s['assemblea'];
    [$rendiconto, $lavori, $varie] = $s['punti'];

    // Mancano il testo, il segretario e gli esiti dei punti votati.
    $this->post(asmIndirizzo('verbale.invia', $a))->assertSessionHasErrors(['testo', 'segretario', 'punti']);

    $this->put(route('admin.gestionale.assemblee.svolgimento', ['condominio' => $a->condominio_id, 'assemblea' => $a->id]), ['segretario' => 'Mario Studio'])->assertSessionHasNoErrors();
    $this->put(asmIndirizzo('punti.update', $a, ['punto' => $rendiconto->id]), ['esito' => 'approvato', 'delibera' => 'Il rendiconto è approvato.'])->assertSessionHasNoErrors();
    $this->put(asmIndirizzo('punti.update', $a, ['punto' => $lavori->id]), ['esito' => 'respinto'])->assertSessionHasNoErrors();
    $this->post(asmIndirizzo('verbale.genera', $a))->assertSessionHasNoErrors();

    $this->post(asmIndirizzo('verbale.invia', $a))->assertSessionHasNoErrors();
    $verbale = Verbale::where('assemblea_id', $a->id)->firstOrFail();
    expect($verbale->stato)->toBe(Verbale::DA_APPROVARE)
        ->and($varie->fresh()->esito)->toBe('informativa');

    // In attesa di approvazione il testo non si cambia.
    $this->put(asmIndirizzo('verbale.update', $a), ['testo' => 'altro'])->assertSessionHasErrors('verbale');

    $this->post(asmIndirizzo('verbale.approva', $a))->assertSessionHasNoErrors();
    $verbale->refresh();
    expect($verbale->stato)->toBe(Verbale::APPROVATO)
        ->and($verbale->approvato_by)->toBe($this->utente->id)
        ->and($verbale->documento_id)->not->toBeNull();

    $documento = Documento::with(['categorie', 'condomini'])->findOrFail($verbale->documento_id);
    expect($documento->mime_type)->toBe('application/pdf')
        ->and($documento->documentable_type)->toBeNull()
        ->and($documento->is_published)->toBeFalsy()
        ->and($documento->condomini->pluck('id')->all())->toBe([$a->condominio_id])
        ->and($documento->categorie->pluck('name')->all())->toBe(['Verbali di assemblea'])
        ->and(substr((string) Storage::disk('local')->get($documento->path), 0, 5))->toBe('%PDF-');

    // Approvato: niente si modifica più.
    $this->put(asmIndirizzo('verbale.update', $a), ['testo' => 'cambiato'])->assertSessionHasErrors('verbale');
    $this->put(asmIndirizzo('punti.update', $a, ['punto' => $lavori->id]), ['delibera' => 'cambiata'])->assertSessionHasErrors('verbale');
    $this->put(route('admin.gestionale.assemblee.svolgimento', ['condominio' => $a->condominio_id, 'assemblea' => $a->id]), ['presidente' => 'Altro'])->assertSessionHasErrors('verbale');
    $this->put(asmIndirizzo('punti.voti', $a, ['punto' => $lavori->id]), ['tutti' => 'favorevole'])->assertSessionHasErrors('verbale');
    expect(fn () => app(VerbaleService::class)->precompilaDaBozza($a->fresh(), [['numero' => 2, 'delibera' => 'x']], 'registrazione'))
        ->toThrow(\Illuminate\Validation\ValidationException::class);

    // La stampa del verbale approvato è il PDF dell'archivio.
    $stampa = $this->get(asmIndirizzo('verbale.stampa', $a));
    $stampa->assertOk();
    expect($stampa->getContent())->toBe(Storage::disk('local')->get($documento->path));

    // Una nuova versione si apre esplicitamente; il PDF approvato resta nell'archivio.
    $this->post(asmIndirizzo('verbale.riapri', $a))->assertSessionHasNoErrors();
    $verbale->refresh();
    expect($verbale->stato)->toBe(Verbale::BOZZA)
        ->and($verbale->versione)->toBe(2)
        ->and(Documento::find($documento->id))->not->toBeNull();
    $this->put(asmIndirizzo('verbale.update', $a), ['testo' => 'Versione corretta.'])->assertSessionHasNoErrors();
});

it('la bozza si stampa con la filigrana, anche prima di generare il testo', function () {
    $s = asmAssembleaTenuta();

    $risposta = $this->get(asmIndirizzo('verbale.stampa', $s['assemblea']));

    $risposta->assertOk();
    expect($risposta->headers->get('Content-Type'))->toBe('application/pdf')
        ->and(substr($risposta->getContent(), 0, 5))->toBe('%PDF-')
        ->and($risposta->headers->get('Content-Disposition'))->toContain('bozza-verbale-assemblea');
});

it('la pagina del verbale si apre con il testo proposto dal modello e l\'elenco dei segnaposto', function () {
    $s = asmAssembleaTenuta();

    $this->get(asmIndirizzo('verbale.show', $s['assemblea']))
        ->assertOk()
        ->assertInertia(fn ($pagina) => $pagina
            ->component('gestionale/assemblee/VerbaleEdit')
            ->has('modelli', 1)
            ->where('fotografia.verbale.stato', 'bozza')
            ->where('proposta', fn ($testo) => str_contains($testo, 'VERBALE DI ASSEMBLEA'))
            ->has('segnaposti.semplici.costituzione'));
});

it('un agente AI legge tutto in una risposta, scrive il testo e lo manda in approvazione, ma non può approvarlo', function () {
    $s = asmAssembleaTenuta();
    $a = $s['assemblea'];
    [$rendiconto, $lavori] = $s['punti'];
    [, $chiave] = ChiaveAgente::genera('Assistente del verbale', $this->utente, false);
    $agente = fn () => $this->withHeader('Authorization', 'Bearer '.$chiave);

    $dati = $agente()->getJson(asmIndirizzo('dati', $a))->assertOk()->json();
    expect($dati['ok'])->toBeTrue()
        ->and($dati['dati']['assemblea']['id'])->toBe($a->id)
        ->and($dati['dati']['punti'])->toHaveCount(3)
        ->and($dati['dati']['punti'][1]['votazione']['esito_proposto'])->toBe('respinto')
        ->and($dati['dati']['presenze'])->toHaveCount(3)
        ->and($dati['dati']['conteggi']['costituzione']['valida'])->toBeTrue()
        ->and($dati['dati']['verbale']['stato'])->toBe('bozza')
        ->and($dati['dati']['segnaposti']['semplici'])->toHaveKey('presenze.tabella');

    $agente()->putJson(asmIndirizzo('punti.update', $a, ['punto' => $rendiconto->id]), ['esito' => 'approvato', 'delibera' => 'Approvato.'])->assertOk();
    $agente()->putJson(asmIndirizzo('punti.update', $a, ['punto' => $lavori->id]), ['esito' => 'respinto'])->assertOk();
    $agente()->putJson(asmIndirizzo('verbale.update', $a), ['testo' => "# Verbale\nScritto dall'assistente seguendo il verbale dello scorso anno."])->assertOk();
    $agente()->postJson(asmIndirizzo('verbale.invia', $a))->assertOk();
    expect(Verbale::where('assemblea_id', $a->id)->value('stato'))->toBe(Verbale::DA_APPROVARE);

    $rifiuto = $agente()->postJson(asmIndirizzo('verbale.approva', $a));
    $rifiuto->assertStatus(403);
    expect($rifiuto->json('messaggio'))->toContain('non è disponibile agli agenti')
        ->and(Verbale::where('assemblea_id', $a->id)->value('stato'))->toBe(Verbale::DA_APPROVARE)
        ->and(AzioneAgente::where('rotta', 'admin.gestionale.assemblee.verbale.approva')->value('stato'))->toBe(403);

    // Nemmeno nel catalogo delle funzioni.
    $catalogo = $agente()->getJson(route('agenti.catalogo'))->json();
    $nomi = array_column($catalogo['dati']['rotte'] ?? [], 'nome');
    expect($nomi)->toContain('admin.gestionale.assemblee.verbale.invia')
        ->and($nomi)->not->toContain('admin.gestionale.assemblee.verbale.approva');
});
