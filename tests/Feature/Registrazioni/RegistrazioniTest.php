<?php

/*
 * Le registrazioni delle riunioni del condominio (Web Pro Italia, 17/11/2026).
 *
 * Il percorso intero come lo fa il browser: l'avvio con la conferma di aver avvisato i presenti, i pezzi da 25 secondi (anche fuori ordine e
 * due volte), la chiusura che mette in coda l'elaborazione (qui la coda è sincrona), l'unione dell'audio, la passata con le voci, il
 * riassunto e la bozza per punto dell'ordine del giorno. Deepgram e Gemini sono finti (`Http::fake`), ffmpeg pure: al suo posto un finto
 * che attacca i byte dei pezzi, e dice in che ordine li ha ricevuti.
 */

use App\Contracts\Registrazioni\UnisceAudio;
use App\Events\Registrazioni\RegistrazioneConclusa;
use App\Jobs\Registrazioni\ElaboraRegistrazione;
use App\Models\Anagrafica;
use App\Models\ChiaveAgente;
use App\Models\Condominio;
use App\Models\Registrazione;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as RichiestaHttp;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Pagina;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/** Il finto ffmpeg: attacca i byte dei pezzi nel file di destinazione e si ricorda l'ordine. */
class UnioneFinta implements UnisceAudio
{
    public array $chiamate = [];

    public bool $fallisci = false;

    /** I pezzi da dare per illeggibili, come farebbe ffprobe. */
    public array $illeggibili = [];

    public function unisci(array $pezzi, string $destinazione): array
    {
        $this->chiamate[] = array_map('basename', $pezzi);

        if ($this->fallisci) {
            throw new RuntimeException('pezzi illeggibili');
        }

        $buoni = array_filter($pezzi, fn ($p) => ! in_array(basename($p), $this->illeggibili, true));
        file_put_contents($destinazione, implode('', array_map('file_get_contents', $buoni)));

        return array_values(array_intersect(array_map('basename', $pezzi), $this->illeggibili));
    }
}

const RIASSUNTO_FINTO = "TITOLO: Rendiconto e lavori al tetto\n\n**In breve**\nL'assemblea ha approvato il rendiconto 2026.\n\n**Decisioni**\n- Rendiconto approvato all'unanimità.\n\n**Rimasto in sospeso**\nNessuno.";

beforeEach(function () {
    Storage::fake('local');

    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo(Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']));
    // Le registrazioni hanno i permessi delle assemblee (17/11/2026): leggere chiede «Visualizza assemblee», il resto «Gestisci assemblee».
    $ruolo->givePermissionTo(Permission::firstOrCreate(['name' => 'Visualizza assemblee', 'guard_name' => 'web']));
    $ruolo->givePermissionTo(Permission::firstOrCreate(['name' => 'Gestisci assemblee', 'guard_name' => 'web']));
    $this->utente = User::factory()->create(['email_verified_at' => now()]);
    $this->utente->assignRole($ruolo);
    $this->actingAs($this->utente);

    config(['services.deepgram.key' => 'dg-prova', 'services.gemini.key' => 'gm-prova']);

    $this->unione = new UnioneFinta;
    app()->instance(UnisceAudio::class, $this->unione);

    $this->condominio = Condominio::factory()->create(['nome' => 'Condominio Le Querce']);

    // I servizi finti. Le risposte stanno in `$this->risposte` e si leggono a ogni chiamata, così una prova le cambia a metà (un secondo
    // `Http::fake()` non sostituirebbe il primo: vince la prima regola che risponde). Deepgram: il pezzo in diretta risponde «pezzo N» (N è
    // il contenuto del file), la passata finale con le voci risponde due persone; Gemini il riassunto, o la bozza se gli si chiede un JSON.
    $this->finaleBuona = fn () => Http::response([
        'metadata' => ['duration' => 61.4],
        'results' => ['channels' => [['alternatives' => [[
            'transcript' => 'tutto',
            'paragraphs' => ['paragraphs' => [
                ['speaker' => 0, 'start' => 0.4, 'sentences' => [['text' => 'Buonasera a tutti, apriamo l\'assemblea del condominio Le Querce.'], ['text' => 'Primo punto: il rendiconto 2026.']]],
                ['speaker' => 0, 'start' => 9.0, 'sentences' => [['text' => 'Il totale delle spese è di 18.400 euro.']]],
                ['speaker' => 1, 'start' => 75.0, 'sentences' => [['text' => 'Per me va bene, approviamo il rendiconto così com\'è.']]],
            ]],
        ]]]]],
    ]);
    $this->risposte = [
        'pezzo' => fn (RichiestaHttp $r) => Http::response(['metadata' => ['duration' => 25.0], 'results' => ['channels' => [['alternatives' => [['transcript' => 'pezzo '.trim($r->body())]]]]]]),
        'finale' => $this->finaleBuona,
        'gemini' => fn (RichiestaHttp $r) => Http::response(['candidates' => [[
            'content' => ['parts' => [['text' => isset($r->data()['generationConfig']['responseMimeType'])
                ? json_encode([
                    ['numero' => 1, 'discussione' => 'Si presenta il rendiconto 2026: spese per 18.400 euro.', 'delibera' => 'Rendiconto approvato.'],
                    ['numero' => 2, 'discussione' => '', 'delibera' => 'null'],
                    ['numero' => 9, 'discussione' => 'Un punto che non c\'è.', 'delibera' => 'Inventata.'],
                ])
                : RIASSUNTO_FINTO]]],
            'finishReason' => 'STOP',
        ]]]),
    ];
    Http::fake([
        'api.deepgram.com/*' => fn (RichiestaHttp $r) => str_contains($r->url(), 'diarize=true') ? ($this->risposte['finale'])($r) : ($this->risposte['pezzo'])($r),
        'generativelanguage.googleapis.com/*' => fn (RichiestaHttp $r) => ($this->risposte['gemini'])($r),
    ]);
});

function regAvvia($test, array $dati = []): \Illuminate\Testing\TestResponse
{
    return $test->postJson(route('admin.gestionale.registrazioni.avvia', $test->condominio), $dati + ['avviso_presenti' => true, 'formato' => 'webm']);
}

function regPezzo($test, int $id, int $numero, ?string $contenuto = null, ?Condominio $condominio = null): \Illuminate\Testing\TestResponse
{
    return $test->post(route('admin.gestionale.registrazioni.segmento', [$condominio ?? $test->condominio, $id]), [
        'numero' => $numero,
        'audio' => UploadedFile::fake()->createWithContent('pezzo.webm', $contenuto ?? (string) $numero),
    ], ['Accept' => 'application/json']);
}

function regTermina($test, int $id): \Illuminate\Testing\TestResponse
{
    return $test->postJson(route('admin.gestionale.registrazioni.termina', [$test->condominio, $id]));
}

/** Una registrazione con due pezzi già saliti, pronta da chiudere. */
function regConDuePezzi($test, array $dati = []): Registrazione
{
    $id = regAvvia($test, $dati)->assertCreated()->json('id');
    regPezzo($test, $id, 1)->assertOk();
    regPezzo($test, $id, 2)->assertOk();

    return Registrazione::findOrFail($id);
}

/** Le tabelle dell'assemblea, come le creerà l'altro lavoro (qui solo le colonne che servono). */
function regAssemblea(Condominio $condominio, array $punti = ['Approvazione del rendiconto 2026', 'Lavori al tetto']): int
{
    if (! Schema::hasTable('assemblee')) {
        Schema::create('assemblee', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('condominio_id');
            $t->string('titolo')->nullable();
            $t->timestamps();
        });
        Schema::create('assemblea_punti', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('assemblea_id');
            $t->unsignedInteger('numero');
            $t->string('titolo');
            $t->timestamps();
        });
    }

    $id = DB::table('assemblee')->insertGetId(['condominio_id' => $condominio->id, 'titolo' => 'Assemblea ordinaria']);
    foreach ($punti as $i => $titolo) {
        DB::table('assemblea_punti')->insert(['assemblea_id' => $id, 'numero' => $i + 1, 'titolo' => $titolo]);
    }

    return $id;
}

it('senza la conferma di aver avvisato i presenti non si parte', function () {
    $this->postJson(route('admin.gestionale.registrazioni.avvia', $this->condominio), ['formato' => 'webm'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['avviso_presenti' => 'avvisato i presenti']);
    $this->postJson(route('admin.gestionale.registrazioni.avvia', $this->condominio), ['avviso_presenti' => false])
        ->assertJsonValidationErrors('avviso_presenti');

    expect(Registrazione::count())->toBe(0);
});

it('con la conferma parte una registrazione «in corso», con l\'ora della conferma e un titolo di serie', function () {
    $this->travelTo('2026-11-23 20:05:00');
    $risposta = regAvvia($this)->assertCreated()->assertJsonPath('secondi_segmento', 25);

    $r = Registrazione::findOrFail($risposta->json('id'));
    expect($r->stato)->toBe(Registrazione::IN_CORSO)
        ->and($r->condominio_id)->toBe($this->condominio->id)
        ->and($r->avviso_presenti_at?->toDateTimeString())->toBe('2026-11-23 20:05:00')
        ->and($r->titolo)->toBe('Registrazione del 23/11/2026 alle 21:05')
        ->and($r->formato)->toBe('webm')
        ->and($r->created_by)->toBe($this->utente->id);
});

it('senza la chiave di Deepgram non si registra, e la pagina lo sa', function () {
    config(['services.deepgram.key' => null]);

    regAvvia($this)->assertUnprocessable()->assertJsonValidationErrors(['servizio' => 'KONDO_DEEPGRAM_KEY']);
    $this->getJson(route('admin.gestionale.registrazioni.servizio', $this->condominio))->assertJson(['trascrizione' => false, 'riassunto' => true, 'giorni_audio' => 30]);
    $this->get(route('admin.gestionale.registrazioni.index', $this->condominio))
        ->assertInertia(fn (Pagina $p) => $p->component('gestionale/registrazioni/Registrazioni')->where('servizio.trascrizione', false));

    expect(Registrazione::count())->toBe(0);
});

it('i pezzi arrivano fuori ordine e due volte: ognuno va al suo posto, il doppio non si ritrascrive', function () {
    $id = regAvvia($this)->json('id');

    regPezzo($this, $id, 2)->assertOk()->assertJson(['numero' => 2, 'testo' => 'pezzo 2', 'doppio' => false]);
    regPezzo($this, $id, 1)->assertOk()->assertJson(['numero' => 1, 'testo' => 'pezzo 1', 'doppio' => false]);
    regPezzo($this, $id, 2)->assertOk()->assertJson(['numero' => 2, 'testo' => 'pezzo 2', 'doppio' => true]);

    $r = Registrazione::findOrFail($id);
    expect($r->trascrizione_live)->toBe('pezzo 1 pezzo 2')
        ->and(array_keys($r->segmenti))->toBe([1, 2])
        ->and($r->secondi())->toBe(50);
    Storage::disk('local')->assertExists(["registrazioni/{$id}/seg-0001.webm", "registrazioni/{$id}/seg-0002.webm"]);
    Http::assertSentCount(2);
});

it('un pezzo che Deepgram non trascrive resta salvato e la registrazione continua', function () {
    $this->risposte['pezzo'] = fn () => Http::response('guasto', 503);
    $id = regAvvia($this)->json('id');

    regPezzo($this, $id, 1)->assertOk()->assertJsonPath('testo', '')->assertJsonPath('errore', fn ($e) => str_contains($e, '503'));

    Storage::disk('local')->assertExists("registrazioni/{$id}/seg-0001.webm");
    expect(Registrazione::find($id)->stato)->toBe(Registrazione::IN_CORSO);
});

it('il vocabolario va a Deepgram: le parole delle assemblee e i nomi dei condòmini', function () {
    $persona = Anagrafica::factory()->create(['nome' => 'Ermenegilda Scaccabarozzi']);
    $this->condominio->anagrafiche()->attach($persona->id);
    $id = regAvvia($this)->json('id');

    regPezzo($this, $id, 1)->assertOk();

    Http::assertSent(fn (RichiestaHttp $r) => str_contains($r->url(), 'model=nova-3') && str_contains($r->url(), 'language=it')
        && str_contains($r->url(), 'keyterm=millesimi') && str_contains($r->url(), 'keyterm=ordine%20del%20giorno')
        && str_contains($r->url(), 'keyterm=Ermenegilda%20Scaccabarozzi') && $r->header('Authorization') === ['Token dg-prova']);
});

it('se Deepgram rifiuta il vocabolario si riprova senza', function () {
    $this->risposte['pezzo'] = fn (RichiestaHttp $r) => str_contains($r->url(), 'keyterm=')
        ? Http::response(['err_msg' => 'keyterm non supportato'], 400)
        : Http::response(['metadata' => ['duration' => 25], 'results' => ['channels' => [['alternatives' => [['transcript' => 'senza vocabolario']]]]]]);
    $id = regAvvia($this)->json('id');

    regPezzo($this, $id, 1)->assertOk()->assertJsonPath('testo', 'senza vocabolario');
    Http::assertSentCount(2);
});

it('chiusa, la registrazione passa dalla coda: audio unito in ordine, voci, riassunto, titolo proposto, evento', function () {
    Event::fake([RegistrazioneConclusa::class]);
    $id = regAvvia($this)->json('id');
    regPezzo($this, $id, 2)->assertOk();
    regPezzo($this, $id, 1)->assertOk();

    regTermina($this, $id)->assertOk()->assertJsonPath('message', fn ($m) => str_contains($m, 'riassunto'));

    $r = Registrazione::findOrFail($id);
    expect($r->stato)->toBe(Registrazione::PRONTA)
        ->and($this->unione->chiamate)->toBe([['seg-0001.webm', 'seg-0002.webm']])
        ->and($r->trascrizione)->toBe("[00:00] Persona 1: Buonasera a tutti, apriamo l'assemblea del condominio Le Querce. Primo punto: il rendiconto 2026. Il totale delle spese è di 18.400 euro.\n\n[01:15] Persona 2: Per me va bene, approviamo il rendiconto così com'è.")
        ->and($r->riassunto)->toBe("**In breve**\nL'assemblea ha approvato il rendiconto 2026.\n\n**Decisioni**\n- Rendiconto approvato all'unanimità.")
        ->and($r->titolo)->toBe('Rendiconto e lavori al tetto')
        ->and($r->durata_secondi)->toBe(61)
        ->and($r->finita_at)->not->toBeNull()
        ->and($r->bozza_punti)->toBeNull()
        ->and($r->errore)->toBeNull();

    // L'audio unito vive solo durante l'elaborazione; i pezzi restano fino alla cancellazione.
    Storage::disk('local')->assertMissing("registrazioni/{$id}/completo.webm");
    Storage::disk('local')->assertExists("registrazioni/{$id}/seg-0001.webm");

    // La passata finale con le voci, il body in streaming dal file unito; Gemini senza «pensiero» e con la chiave nell'intestazione.
    Http::assertSent(fn (RichiestaHttp $r) => str_contains($r->url(), 'diarize=true') && $r->body() === '12');
    Http::assertSent(fn (RichiestaHttp $r) => str_contains($r->url(), 'gemini-2.5-flash:generateContent') && ! str_contains($r->url(), 'key=')
        && $r->header('x-goog-api-key') === ['gm-prova'] && $r->data()['generationConfig']['thinkingConfig']['thinkingBudget'] === 0
        && str_contains($r->data()['contents'][0]['parts'][0]['text'], 'Persona 2: Per me va bene'));

    Event::assertDispatched(RegistrazioneConclusa::class, fn ($e) => $e->registrazione->id === $id);
});

it('un titolo dato da chi registra resta: quello proposto vale solo per il titolo di serie', function () {
    $r = regConDuePezzi($this, ['titolo' => 'Riunione dei consiglieri']);

    regTermina($this, $r->id)->assertOk();

    expect($r->fresh()->titolo)->toBe('Riunione dei consiglieri');
});

it('con un\'assemblea e il suo ordine del giorno arriva la bozza per punto, senza punti inventati e senza delibere vuote', function () {
    Event::fake([RegistrazioneConclusa::class]);
    $assemblea = regAssemblea($this->condominio);
    $r = regConDuePezzi($this, ['assemblea_id' => $assemblea]);

    regTermina($this, $r->id)->assertOk();

    expect($r->fresh()->bozza_punti)->toBe([
        ['numero' => 1, 'discussione' => 'Si presenta il rendiconto 2026: spese per 18.400 euro.', 'delibera' => 'Rendiconto approvato.'],
        ['numero' => 2, 'discussione' => '', 'delibera' => null],
    ]);
    // La richiesta della bozza porta l'ordine del giorno e chiede un JSON; anche il riassunto sa l'ordine del giorno.
    Http::assertSent(fn (RichiestaHttp $req) => ($req->data()['generationConfig']['responseMimeType'] ?? null) === 'application/json'
        && str_contains($req->data()['contents'][0]['parts'][0]['text'], "1. Approvazione del rendiconto 2026\n2. Lavori al tetto")
        // La delibera è il testo della decisione: l'esito del voto non si prende dall'audio (lo scrive il programma dai voti registrati).
        && str_contains($req->data()['contents'][0]['parts'][0]['text'], 'NON scrivere MAI l\'esito del voto')
        && str_contains($req->data()['contents'][0]['parts'][0]['text'], 'niente «all\'unanimità»')
        && ! str_contains($req->data()['contents'][0]['parts'][0]['text'], 'con l\'esito del voto solo se è stato detto'));
    Event::assertDispatched(RegistrazioneConclusa::class, fn ($e) => $e->registrazione->assemblea_id === $assemblea && count($e->registrazione->bozza_punti) === 2);
});

it('un\'assemblea di un altro condominio non si collega', function () {
    $altra = regAssemblea(Condominio::factory()->create());

    regAvvia($this, ['assemblea_id' => $altra])->assertJsonValidationErrors('assemblea_id');
    $r = regConDuePezzi($this);
    $this->putJson(route('admin.gestionale.registrazioni.assemblea', [$this->condominio, $r->id]), ['assemblea_id' => $altra])->assertJsonValidationErrors('assemblea_id');

    expect($r->fresh()->assemblea_id)->toBeNull();
});

it('collegata dopo a un\'assemblea, una registrazione pronta riceve la bozza per punti e l\'evento riparte', function () {
    $r = regConDuePezzi($this);
    regTermina($this, $r->id)->assertOk();
    Event::fake([RegistrazioneConclusa::class]);
    $assemblea = regAssemblea($this->condominio);

    $this->putJson(route('admin.gestionale.registrazioni.assemblea', [$this->condominio, $r->id]), ['assemblea_id' => $assemblea])
        ->assertOk()->assertJsonPath('message', fn ($m) => str_contains($m, 'bozza'));

    expect($r->fresh()->assemblea_id)->toBe($assemblea)
        ->and($r->fresh()->bozza_punti)->toHaveCount(2);
    Event::assertDispatched(RegistrazioneConclusa::class);

    // Scollegata: la bozza di quell'assemblea non vale più.
    $this->putJson(route('admin.gestionale.registrazioni.assemblea', [$this->condominio, $r->id]), ['assemblea_id' => null])->assertOk();
    expect($r->fresh()->assemblea_id)->toBeNull()->and($r->fresh()->bozza_punti)->toBeNull();
});

it('se la passata finale di Deepgram non riesce la registrazione va in errore, i pezzi restano, e «Riprova» la porta a termine', function () {
    Event::fake([RegistrazioneConclusa::class]);
    $r = regConDuePezzi($this);
    $this->risposte['finale'] = fn () => Http::response(['err_msg' => 'servizio non disponibile'], 503);

    regTermina($this, $r->id)->assertOk();

    $r->refresh();
    expect($r->stato)->toBe(Registrazione::ERRORE)
        ->and($r->errore)->toContain('Deepgram ha risposto 503')
        ->and($r->trascrizione)->toBeNull()
        ->and($r->trascrizione_live)->toBe('pezzo 1 pezzo 2');
    Storage::disk('local')->assertExists(["registrazioni/{$r->id}/seg-0001.webm", "registrazioni/{$r->id}/seg-0002.webm"]);
    Storage::disk('local')->assertMissing("registrazioni/{$r->id}/completo.webm");
    Event::assertNotDispatched(RegistrazioneConclusa::class);

    $this->risposte['finale'] = $this->finaleBuona;
    regTermina($this, $r->id)->assertOk();

    expect($r->fresh()->stato)->toBe(Registrazione::PRONTA)->and($r->fresh()->errore)->toBeNull();
    Event::assertDispatched(RegistrazioneConclusa::class);
});

it('se ffmpeg non unisce l\'audio la registrazione va in errore e i pezzi restano', function () {
    $r = regConDuePezzi($this);
    $this->unione->fallisci = true;

    regTermina($this, $r->id)->assertOk();

    expect($r->fresh()->stato)->toBe(Registrazione::ERRORE)->and($r->fresh()->errore)->toContain('pezzi illeggibili');
    Storage::disk('local')->assertExists("registrazioni/{$r->id}/seg-0002.webm");
});

it('un pezzo illeggibile si salta: la registrazione è pronta con il resto, e una nota dice che cosa manca', function () {
    $r = regConDuePezzi($this);
    $this->unione->illeggibili = ['seg-0001.webm'];

    regTermina($this, $r->id)->assertOk();

    expect($r->fresh()->stato)->toBe(Registrazione::PRONTA)
        ->and($r->fresh()->errore)->toBe('Un pezzo di audio non era leggibile e manca dalla trascrizione con le voci (circa 25 secondi): seg-0001.webm.');
    Http::assertSent(fn (RichiestaHttp $req) => str_contains($req->url(), 'diarize=true') && $req->body() === '2');
});

it('se Gemini non risponde la trascrizione esce lo stesso, senza riassunto', function () {
    Event::fake([RegistrazioneConclusa::class]);
    $assemblea = regAssemblea($this->condominio);
    $r = regConDuePezzi($this, ['assemblea_id' => $assemblea]);
    $this->risposte['gemini'] = fn () => Http::response('sovraccarico', 503);

    regTermina($this, $r->id)->assertOk();

    $r->refresh();
    expect($r->stato)->toBe(Registrazione::PRONTA)
        ->and($r->trascrizione)->toContain('Persona 2')
        ->and($r->riassunto)->toBeNull()
        ->and($r->bozza_punti)->toBeNull()
        ->and($r->titolo)->toStartWith('Registrazione del ');
    Event::assertDispatched(RegistrazioneConclusa::class);
});

it('senza la chiave di Gemini non si chiede niente a Gemini, e la trascrizione esce', function () {
    $r = regConDuePezzi($this);
    config(['services.gemini.key' => '']);

    regTermina($this, $r->id)->assertOk()->assertJsonPath('message', fn ($m) => ! str_contains($m, 'riassunto'));

    expect($r->fresh()->stato)->toBe(Registrazione::PRONTA)->and($r->fresh()->riassunto)->toBeNull();
    Http::assertNotSent(fn (RichiestaHttp $req) => str_contains($req->url(), 'generativelanguage'));
});

it('il browser si è chiuso: la registrazione resta aperta con i suoi pezzi e si chiude dall\'elenco', function () {
    $r = regConDuePezzi($this);
    $elenco = route('admin.gestionale.registrazioni.index', $this->condominio);

    $this->get($elenco)->assertInertia(fn (Pagina $p) => $p->where('registrazioni.0.id', $r->id)->where('registrazioni.0.stato', 'in_corso')->where('registrazioni.0.pezzi', 2));

    // Il pulsante «Chiudi e trascrivi» dell'elenco è una visita Inertia: torna alla pagina con l'avviso.
    $this->from($elenco)->post(route('admin.gestionale.registrazioni.termina', [$this->condominio, $r->id]), [], ['X-Inertia' => 'true'])
        ->assertRedirect($elenco)->assertSessionHas('message.type', 'success');

    expect($r->fresh()->stato)->toBe(Registrazione::PRONTA);
});

it('due chiusure insieme mettono in coda un\'elaborazione sola; a registrazione chiusa i pezzi non entrano più', function () {
    \Illuminate\Support\Facades\Queue::fake();
    $r = regConDuePezzi($this);

    regTermina($this, $r->id)->assertOk();
    regTermina($this, $r->id)->assertOk()->assertJsonPath('message', 'La trascrizione con le voci è già in preparazione.');

    \Illuminate\Support\Facades\Queue::assertPushed(ElaboraRegistrazione::class, 1);
    regPezzo($this, $r->id, 3)->assertStatus(409);
    Storage::disk('local')->assertMissing("registrazioni/{$r->id}/seg-0003.webm");
});

it('l\'elaborazione ha un tempo suo, sotto il retry_after della coda, e una seconda copia non lavora', function () {
    $lavoro = new ElaboraRegistrazione(1);

    expect($lavoro->timeout)->toBe(1800)
        ->and($lavoro->timeout)->toBeLessThan((int) config('queue.connections.database.retry_after'))
        ->and($lavoro->tries)->toBe(2);

    $r = regConDuePezzi($this);
    Registrazione::whereKey($r->id)->update(['stato' => Registrazione::IN_ELABORAZIONE]);
    $lucchetto = \Illuminate\Support\Facades\Cache::lock('registrazioni:elabora:'.$r->id, 60);
    $lucchetto->get();

    dispatch_sync(new ElaboraRegistrazione($r->id));

    expect($r->fresh()->stato)->toBe(Registrazione::IN_ELABORAZIONE)->and($this->unione->chiamate)->toBe([]);
    $lucchetto->release();
});

it('chiusa senza nessun pezzo, la registrazione dice che non c\'è niente da trascrivere', function () {
    $id = regAvvia($this)->json('id');

    regTermina($this, $id)->assertUnprocessable()->assertJsonPath('message', fn ($m) => str_contains($m, 'nessun pezzo'));

    expect(Registrazione::find($id)->stato)->toBe(Registrazione::ERRORE);
});

it('«Cancella l\'audio»: non mentre si registra; dopo, via i file e restano i testi', function () {
    $r = regConDuePezzi($this);
    $audio = route('admin.gestionale.registrazioni.cancella-audio', [$this->condominio, $r->id]);

    $this->deleteJson($audio)->assertStatus(409);
    Storage::disk('local')->assertExists("registrazioni/{$r->id}/seg-0001.webm");

    regTermina($this, $r->id)->assertOk();
    $this->deleteJson($audio)->assertOk();

    $r->refresh();
    expect($r->audio_cancellato_at)->not->toBeNull()->and($r->trascrizione)->toContain('Persona 1')->and($r->riassunto)->not->toBeNull();
    expect(Storage::disk('local')->files("registrazioni/{$r->id}"))->toBe([]);

    // Senza audio non si rielabora: la registrazione lo dice.
    $r->forceFill(['stato' => Registrazione::ERRORE])->save();
    regTermina($this, $r->id)->assertUnprocessable()->assertJsonPath('message', fn ($m) => str_contains($m, 'cancellato'));
});

it('la pulizia di ogni notte toglie l\'audio oltre i giorni impostati e lascia le trascrizioni', function () {
    config(['registrazioni.giorni_audio' => 30]);
    $vecchia = regConDuePezzi($this);
    regTermina($this, $vecchia->id);
    $recente = regConDuePezzi($this);
    regTermina($this, $recente->id);
    $abbandonata = regConDuePezzi($this);
    Registrazione::whereKey($vecchia->id)->update(['finita_at' => now()->subDays(31)]);
    Registrazione::whereKey($recente->id)->update(['finita_at' => now()->subDays(5)]);
    Registrazione::whereKey($abbandonata->id)->update(['updated_at' => now()->subDays(40)]);

    Artisan::call('registrazioni:pulisci-audio');

    expect(Storage::disk('local')->files("registrazioni/{$vecchia->id}"))->toBe([])
        ->and(Storage::disk('local')->files("registrazioni/{$abbandonata->id}"))->toBe([])
        ->and(Storage::disk('local')->files("registrazioni/{$recente->id}"))->toHaveCount(2)
        ->and($vecchia->fresh()->audio_cancellato_at)->not->toBeNull()
        ->and($vecchia->fresh()->trascrizione)->toContain('Persona 1')
        ->and($recente->fresh()->audio_cancellato_at)->toBeNull();

    $voce = collect(app(Schedule::class)->events())->first(fn ($e) => $e->description === 'pulizia-audio-registrazioni');
    expect($voce)->not->toBeNull()->and($voce->command)->toContain('registrazioni:pulisci-audio');
});

it('l\'elenco e il dettaglio: riassunto, trascrizione, bozza, e quando l\'audio si cancella da solo', function () {
    $assemblea = regAssemblea($this->condominio);
    $r = regConDuePezzi($this, ['assemblea_id' => $assemblea]);
    regTermina($this, $r->id);

    $this->get(route('admin.gestionale.registrazioni.show', [$this->condominio, $r->id]))
        ->assertInertia(fn (Pagina $p) => $p->component('gestionale/registrazioni/RegistrazioneShow')
            ->where('registrazione.stato', 'pronta')
            ->where('registrazione.riassunto', fn ($t) => str_contains($t, 'In breve'))
            ->where('registrazione.trascrizione', fn ($t) => str_contains($t, 'Persona 2'))
            ->where('registrazione.bozza_punti.0.delibera', 'Rendiconto approvato.')
            ->where('registrazione.audio', true)
            ->where('registrazione.avviso_presenti_quando', fn ($q) => preg_match('#^\d{2}/\d{2}/\d{4} alle \d{2}:\d{2}$#', $q) === 1)
            ->where('registrazione.audio_scade_il', now()->addDays(30)->timezone('Europe/Rome')->format('d/m/Y'))
            ->where('punti.1.titolo', 'Lavori al tetto')
            ->where('assemblee.0.id', $assemblea));
});

it('«Elimina» toglie la registrazione con l\'audio; un condominio eliminato porta via anche le cartelle', function () {
    $r = regConDuePezzi($this);
    $this->deleteJson(route('admin.gestionale.registrazioni.destroy', [$this->condominio, $r->id]))->assertOk();

    expect(Registrazione::find($r->id))->toBeNull();
    Storage::disk('local')->assertMissing("registrazioni/{$r->id}/seg-0001.webm");

    $altra = regConDuePezzi($this);
    $this->condominio->delete();

    expect(Registrazione::find($altra->id))->toBeNull();
    Storage::disk('local')->assertMissing("registrazioni/{$altra->id}/seg-0001.webm");
});

it('la registrazione di un altro condominio non si apre, non si alimenta, non si chiude e non si cancella', function () {
    $r = regConDuePezzi($this);
    $altro = Condominio::factory()->create();

    $this->get(route('admin.gestionale.registrazioni.show', [$altro, $r->id]))->assertNotFound();
    regPezzo($this, $r->id, 3, null, $altro)->assertNotFound();
    $this->postJson(route('admin.gestionale.registrazioni.termina', [$altro, $r->id]))->assertNotFound();
    $this->putJson(route('admin.gestionale.registrazioni.assemblea', [$altro, $r->id]), ['assemblea_id' => null])->assertNotFound();
    $this->deleteJson(route('admin.gestionale.registrazioni.cancella-audio', [$altro, $r->id]))->assertNotFound();
    $this->deleteJson(route('admin.gestionale.registrazioni.destroy', [$altro, $r->id]))->assertNotFound();

    expect($r->fresh()->stato)->toBe(Registrazione::IN_CORSO);
    Storage::disk('local')->assertExists("registrazioni/{$r->id}/seg-0002.webm");
});

it('chi non ha accesso al pannello non vede né avvia le registrazioni', function () {
    $r = regConDuePezzi($this);
    $this->actingAs(User::factory()->create(['email_verified_at' => now()]));

    $this->get(route('admin.gestionale.registrazioni.index', $this->condominio))->assertForbidden();
    $this->get(route('admin.gestionale.registrazioni.show', [$this->condominio, $r->id]))->assertForbidden();
    regAvvia($this)->assertForbidden();
    regPezzo($this, $r->id, 3)->assertForbidden();
});

it('un agente AI legge elenco e dettaglio e collega l\'assemblea, ma non avvia né manda audio', function () {
    $assemblea = regAssemblea($this->condominio);
    $r = regConDuePezzi($this);
    regTermina($this, $r->id);
    [, $testo] = ChiaveAgente::genera('Assistente di prova', $this->utente, false);
    \Illuminate\Support\Facades\Auth::logout();
    $chiave = ['Authorization' => 'Bearer '.$testo];

    $this->getJson(route('admin.gestionale.registrazioni.index', $this->condominio), $chiave)
        ->assertOk()->assertJsonPath('dati.registrazioni.0.id', $r->id)->assertJsonPath('dati.registrazioni.0.stato', 'pronta');
    $this->getJson(route('admin.gestionale.registrazioni.show', [$this->condominio, $r->id]), $chiave)
        ->assertOk()
        ->assertJsonPath('dati.registrazione.trascrizione', fn ($t) => str_contains($t, 'Persona 1'))
        ->assertJsonPath('dati.registrazione.riassunto', fn ($t) => str_contains($t, 'In breve'))
        ->assertJsonPath('dati.registrazione.bozza_punti', null);

    $this->putJson(route('admin.gestionale.registrazioni.assemblea', [$this->condominio, $r->id]), ['assemblea_id' => $assemblea], $chiave)->assertOk()->assertJsonPath('ok', true);
    expect($r->fresh()->assemblea_id)->toBe($assemblea)->and($r->fresh()->bozza_punti)->toHaveCount(2);

    $this->postJson(route('admin.gestionale.registrazioni.avvia', $this->condominio), ['avviso_presenti' => true], $chiave)->assertForbidden();
    $this->post(route('admin.gestionale.registrazioni.segmento', [$this->condominio, $r->id]), ['numero' => 9, 'audio' => UploadedFile::fake()->createWithContent('p.webm', 'x')], $chiave + ['Accept' => 'application/json'])->assertForbidden();
    expect(Registrazione::count())->toBe(1);

    // E il catalogo non le propone.
    $nomi = collect($this->getJson(route('agenti.catalogo'), $chiave)->json('dati.rotte'))->pluck('nome');
    expect($nomi)->toContain('admin.gestionale.registrazioni.index', 'admin.gestionale.registrazioni.show', 'admin.gestionale.registrazioni.assemblea')
        ->not->toContain('admin.gestionale.registrazioni.avvia', 'admin.gestionale.registrazioni.segmento');
});

// --- Le correzioni dopo la revisione (Web Pro Italia, 17/11/2026) ----------------------------------------------------------------------

it('⚠️ le registrazioni hanno i permessi delle assemblee: senza «Visualizza assemblee» non si leggono, senza «Gestisci assemblee» non si toccano', function () {
    $assemblea = regAssemblea($this->condominio);
    $r = regConDuePezzi($this);
    regTermina($this, $r->id)->assertOk();
    $aperta = regConDuePezzi($this);
    $rotta = fn (string $nome, ?Registrazione $x = null) => route('admin.gestionale.registrazioni.'.$nome, $x ? [$this->condominio, $x->id] : [$this->condominio]);

    // Chi entra nel pannello ma non ha i permessi delle assemblee: non legge le trascrizioni e non scrive niente.
    $soloPannello = Role::firstOrCreate(['name' => 'contabile-di-prova', 'guard_name' => 'web']);
    $soloPannello->givePermissionTo(Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']));
    $utente = User::factory()->create(['email_verified_at' => now()]);
    $utente->assignRole($soloPannello);
    $this->actingAs($utente);

    $this->get($rotta('index'))->assertForbidden();
    $this->get($rotta('show', $r))->assertForbidden();
    $this->getJson($rotta('servizio'))->assertForbidden();
    $this->postJson($rotta('termina', $aperta))->assertForbidden();
    $this->putJson($rotta('assemblea', $r), ['assemblea_id' => $assemblea])->assertForbidden();
    $this->deleteJson($rotta('cancella-audio', $r))->assertForbidden();
    $this->deleteJson($rotta('destroy', $r))->assertForbidden();
    regAvvia($this)->assertForbidden();
    regPezzo($this, $aperta->id, 3)->assertForbidden();

    // Con «Visualizza assemblee» si legge (elenco, dettaglio con la trascrizione, stato del servizio) e basta.
    $soloPannello->givePermissionTo(Permission::firstOrCreate(['name' => 'Visualizza assemblee', 'guard_name' => 'web']));
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

    $this->get($rotta('index'))->assertOk()->assertInertia(fn (Pagina $p) => $p->where('puo.gestire', false));
    $this->get($rotta('show', $r))->assertOk()->assertInertia(fn (Pagina $p) => $p->where('registrazione.trascrizione', fn ($t) => str_contains($t, 'Persona 1'))->where('puo.gestire', false));
    $this->getJson($rotta('servizio'))->assertOk();
    $this->postJson($rotta('termina', $aperta))->assertForbidden();
    $this->putJson($rotta('assemblea', $r), ['assemblea_id' => $assemblea])->assertForbidden();
    $this->deleteJson($rotta('cancella-audio', $r))->assertForbidden();
    $this->deleteJson($rotta('destroy', $r))->assertForbidden();
    regAvvia($this)->assertForbidden();
    regPezzo($this, $aperta->id, 3)->assertForbidden();

    // Niente è stato toccato.
    expect(Registrazione::count())->toBe(2)
        ->and($r->fresh()->assemblea_id)->toBeNull()
        ->and($aperta->fresh()->stato)->toBe(Registrazione::IN_CORSO);
    Storage::disk('local')->assertExists("registrazioni/{$r->id}/seg-0001.webm");

    // Con «Gestisci assemblee» si lavora.
    $soloPannello->givePermissionTo(Permission::firstOrCreate(['name' => 'Gestisci assemblee', 'guard_name' => 'web']));
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $this->get($rotta('index'))->assertOk()->assertInertia(fn (Pagina $p) => $p->where('puo.gestire', true));
    $this->putJson($rotta('assemblea', $r), ['assemblea_id' => $assemblea])->assertOk();
    expect($r->fresh()->assemblea_id)->toBe($assemblea);
});

it('⚠️ un agente AI non tronca una riunione che si sta registrando: chiude solo una registrazione rimasta aperta da più di cinque minuti', function () {
    $r = regConDuePezzi($this);
    [, $testo] = ChiaveAgente::genera('Assistente di prova', $this->utente, false);
    $chiave = ['Authorization' => 'Bearer '.$testo];
    $termina = route('admin.gestionale.registrazioni.termina', [$this->condominio, $r->id]);

    // L'ultimo pezzo è appena arrivato: l'agente è rifiutato, con la spiegazione, e la registrazione resta aperta.
    \Illuminate\Support\Facades\Auth::logout();
    $rifiuto = $this->postJson($termina, [], $chiave);
    $rifiuto->assertStatus(422)->assertJsonPath('ok', false);
    expect($rifiuto->json('avvisi.0.testo'))->toContain('ancora in corso')->toContain('troncherebbe la riunione')->toContain('più di 5 minuti')
        ->and($r->fresh()->stato)->toBe(Registrazione::IN_CORSO);

    // Chi registra continua: il pezzo successivo entra.
    $this->flushHeaders();
    $this->actingAs($this->utente);
    regPezzo($this, $r->id, 3)->assertOk();
    expect($r->fresh()->stato)->toBe(Registrazione::IN_CORSO)->and(array_keys($r->fresh()->segmenti))->toBe([1, 2, 3]);

    // Quattro minuti dopo l'ultimo pezzo è ancora presto.
    \Illuminate\Support\Facades\Auth::logout();
    $this->travel(4)->minutes();
    $this->postJson($termina, [], $chiave)->assertStatus(422);
    expect($r->fresh()->stato)->toBe(Registrazione::IN_CORSO);

    // Dopo più di cinque minuti senza audio la registrazione è rimasta aperta (il browser si è chiuso): l'agente la può chiudere.
    $this->travel(2)->minutes();
    $this->postJson($termina, [], $chiave)->assertOk()->assertJsonPath('ok', true);
    expect($r->fresh()->stato)->toBe(Registrazione::PRONTA);
});

it('la persona nel browser chiude la registrazione quando vuole, anche appena arrivato l\'ultimo pezzo', function () {
    $r = regConDuePezzi($this);

    regTermina($this, $r->id)->assertOk();

    expect($r->fresh()->stato)->toBe(Registrazione::PRONTA);
});

it('⚠️ con il verbale in attesa di approvazione o approvato, collegare o avviare una registrazione non accende il segno «registrata»', function () {
    $fermo = function (string $stato) {
        $id = regAssemblea($this->condominio);
        DB::table('verbali')->insert(['assemblea_id' => $id, 'stato' => $stato, 'versione' => 1, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    };
    $registrata = fn (int $id) => (bool) DB::table('assemblee')->where('id', $id)->value('registrata');
    $r = regConDuePezzi($this);
    regTermina($this, $r->id)->assertOk();
    $collega = fn (?int $id) => $this->putJson(route('admin.gestionale.registrazioni.assemblea', [$this->condominio, $r->id]), ['assemblea_id' => $id]);

    // In attesa di approvazione: la registrazione si collega, l'assemblea non cambia, e la risposta lo dice.
    $inAttesa = $fermo('da_approvare');
    $collega($inAttesa)->assertOk()->assertJsonPath('message', fn ($m) => str_contains($m, 'in attesa di approvazione') && str_contains($m, 'l\'assemblea non cambia'));
    expect($r->fresh()->assemblea_id)->toBe($inAttesa)->and($registrata($inAttesa))->toBeFalse();

    // Approvato: lo stesso, anche avviando una registrazione nuova da quell'assemblea.
    $approvato = $fermo('approvato');
    $collega($approvato)->assertOk();
    regAvvia($this, ['assemblea_id' => $approvato])->assertCreated();
    expect($registrata($approvato))->toBeFalse();

    // Con il verbale in bozza (o senza verbale) il segno si accende, come prima.
    $inBozza = $fermo('bozza');
    $collega($inBozza)->assertOk()->assertJsonPath('message', fn ($m) => ! str_contains($m, 'non cambia'));
    expect($registrata($inBozza))->toBeTrue();
});
