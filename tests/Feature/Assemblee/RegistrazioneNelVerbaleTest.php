<?php

/*
 * La registrazione di un'assemblea e il suo verbale (Web Pro Italia, 17/11/2026).
 *
 * Le due parti sono nate separate: la registrazione produce una bozza per punti (`bozza_punti`), il verbale ha i suoi campi per punto.
 * Qui si prova il filo che le unisce: l'evento di fine registrazione porta la bozza nei campi ANCORA VUOTI, e in nessun altro; avviare una
 * registrazione dall'assemblea la segna come registrata (il verbale ne scrive l'avviso); la pagina dell'assemblea elenca le sue registrazioni.
 */

use App\Events\Registrazioni\RegistrazioneConclusa;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\AssembleaPunto;
use App\Models\Assemblee\Verbale;
use App\Models\Registrazione;
use App\Services\Assemblee\VerbaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/AssembleeHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->utente = asmAmministratore();
    $this->actingAs($this->utente);
    Storage::fake('local');
});

/** Un'assemblea con due punti e, se chiesta, la sua registrazione pronta con la bozza. */
function rnvScena(array $bozza = []): array
{
    $s = asmCondominio([
        'Int. 1' => ['millesimi' => '600', 'titolari' => ['Anna Alberti' => 'proprietario']],
        'Int. 2' => ['millesimi' => '400', 'titolari' => ['Bruno Bassi' => 'proprietario']],
    ]);
    $a = $s['assemblea'];
    $primo = AssembleaPunto::create(['assemblea_id' => $a->id, 'numero' => 1, 'titolo' => 'Approvazione del rendiconto 2025', 'maggioranza' => 'ordinaria']);
    $secondo = AssembleaPunto::create(['assemblea_id' => $a->id, 'numero' => 2, 'titolo' => 'Nomina dell\'amministratore', 'maggioranza' => 'art1136c4']);

    $registrazione = Registrazione::create([
        'condominio_id' => $s['condominio']->id, 'assemblea_id' => $a->id, 'titolo' => 'Assemblea ordinaria', 'stato' => Registrazione::PRONTA,
        'avviso_presenti_at' => now(), 'iniziata_at' => now()->subHour(), 'finita_at' => now(), 'durata_secondi' => 3600, 'formato' => 'webm',
        'segmenti' => [], 'trascrizione' => 'Persona 1: …', 'riassunto' => 'Si è discusso.', 'bozza_punti' => $bozza, 'created_by' => auth()->id(),
    ]);

    return $s + ['punti' => [$primo, $secondo], 'registrazione' => $registrazione];
}

it('⚠️ la bozza di una registrazione pronta entra nei punti vuoti del verbale, e non tocca quelli già scritti', function () {
    $s = rnvScena([
        ['numero' => 1, 'discussione' => 'Un condòmino chiede due preventivi per le pulizie.', 'delibera' => 'Il rendiconto è approvato.'],
        ['numero' => 2, 'discussione' => 'Si propone di confermare l\'amministratore.', 'delibera' => null],
    ]);
    [$primo, $secondo] = $s['punti'];
    $primo->forceFill(['discussione' => 'Scritta dalla persona.'])->save();

    RegistrazioneConclusa::dispatch($s['registrazione']);

    expect($primo->fresh()->discussione)->toBe('Scritta dalla persona.', 'quello che la persona ha scritto resta')
        ->and($primo->fresh()->delibera)->toBe('Il rendiconto è approvato.')
        ->and($secondo->fresh()->discussione)->toBe('Si propone di confermare l\'amministratore.')
        ->and($secondo->fresh()->delibera)->toBeNull('una decisione non detta chiaramente non si inventa');
});

it('⚠️ la bozza non decide niente: voti ed esiti restano quelli che segna la persona', function () {
    $s = rnvScena([['numero' => 1, 'discussione' => 'Discussione.', 'delibera' => 'Approvato all\'unanimità.']]);

    RegistrazioneConclusa::dispatch($s['registrazione']);

    expect($s['punti'][0]->fresh()->esito)->toBeNull()
        ->and(\App\Models\Assemblee\AssembleaVoto::count())->toBe(0);
});

it('con il verbale già approvato la bozza non entra, e la registrazione non ne risente', function () {
    $s = rnvScena([['numero' => 1, 'discussione' => 'Arrivata tardi.', 'delibera' => 'Arrivata tardi.']]);
    $verbale = app(VerbaleService::class)->verbaleDi($s['assemblea']);
    $verbale->forceFill(['stato' => 'approvato', 'approvato_at' => now(), 'approvato_by' => auth()->id()])->save();

    RegistrazioneConclusa::dispatch($s['registrazione']);

    expect($s['punti'][0]->fresh()->discussione)->toBeNull()
        ->and(Verbale::where('assemblea_id', $s['assemblea']->id)->value('stato'))->toBe('approvato');
});

it('⚠️ una registrazione di un altro condominio non scrive nel verbale di questa assemblea', function () {
    $s = rnvScena([['numero' => 1, 'discussione' => 'Di un altro condominio.', 'delibera' => 'Di un altro condominio.']]);
    $altro = asmCondominio(['Int. 9' => ['millesimi' => '1000', 'titolari' => ['Zeno Zani' => 'proprietario']]]);
    $s['registrazione']->forceFill(['condominio_id' => $altro['condominio']->id])->save();

    RegistrazioneConclusa::dispatch($s['registrazione']->fresh());

    expect($s['punti'][0]->fresh()->discussione)->toBeNull();
});

it('⚠️ avviare una registrazione dall\'assemblea la segna come registrata, e la pagina dell\'assemblea la elenca', function () {
    config(['services.deepgram.key' => 'chiave-di-prova']);
    $s = rnvScena();
    $s['registrazione']->delete();
    $a = $s['assemblea'];
    expect((bool) $a->fresh()->registrata)->toBeFalse();

    $this->postJson(route('admin.gestionale.registrazioni.avvia', $s['condominio']), ['avviso_presenti' => true, 'assemblea_id' => $a->id, 'formato' => 'webm'])
        ->assertCreated();

    expect((bool) $a->fresh()->registrata)->toBeTrue();

    $this->get(route('admin.gestionale.assemblee.show', [$s['condominio'], $a]))
        ->assertInertia(fn ($pagina) => $pagina->component('gestionale/assemblee/AssembleaShow')
            ->has('registrazioni', 1)
            ->where('registrazioni.0.stato', 'in_corso'));
});

it('eliminando l\'assemblea la registrazione resta, senza il legame', function () {
    $s = rnvScena();
    $chiavi = collect(Schema::getForeignKeys('registrazioni'))->filter(fn ($k) => $k['columns'] === ['assemblea_id']);
    expect($chiavi)->toHaveCount(1)->and(strtolower((string) $chiavi->first()['on_delete']))->toBe('set null');

    Assemblea::whereKey($s['assemblea']->id)->delete();

    expect($s['registrazione']->fresh())->not->toBeNull()
        ->and($s['registrazione']->fresh()->assemblea_id)->toBeNull();
});
