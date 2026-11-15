<?php

use App\Models\ChiaveAgente;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Immobile;
use App\Models\ImportBatch;
use App\Models\QuotaTabella;
use App\Models\Saldo;
use App\Models\Tabella;
use App\Models\User;
use App\Services\Import\ImportVerificaService;
use App\Services\Import\ModelloManualeWriter;
use App\Traits\HasEsercizio;
use Database\Seeders\TipologieImmobiliSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Permission;

/**
 * Importare sopra un archivio che ha già qualcosa (Web Pro Italia, 15/11/2026).
 *
 * Tre difetti trovati da uno studio vero, che importa con il suo agente AI:
 *
 * 1. la **prova della conferma** (`X-Kondo-Prova: 1`, o la conferma stessa) rispondeva «ok» anche quando
 *    l'importazione si sarebbe fermata a metà, e l'agente se ne fidava;
 * 2. le **tabelle e i saldi già in archivio** si saltavano e si contavano, senza dire se erano uguali al file
 *    o diversi;
 * 3. un file con un **periodo diverso** apriva un secondo esercizio accanto a quello aperto, con un avviso e
 *    basta, e con due esercizi aperti `getEsercizioCorrente` sceglieva senza un ordine.
 *
 * I file sono il modello compilato a mano, costruito dal generatore vero come in `ImportModelloManualeTest`:
 * è il modo più corto di avere un'importazione completa che si possa rifare cambiando un numero.
 */
function saeUtente(): User
{
    foreach (['Crea condomini', 'Accesso pannello amministratore', 'Importa dati'] as $nome) {
        Permission::firstOrCreate(['name' => $nome, 'guard_name' => 'web']);
    }

    $u = User::factory()->create();
    $u->givePermissionTo('Crea condomini');
    $u->givePermissionTo('Importa dati');
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    return $u;
}

/** La chiave di un agente che lavora per `$utente`. */
function saeChiave(User $utente): string
{
    [, $testo] = ChiaveAgente::genera('Assistente dello studio', $utente, false);

    return $testo;
}

/** @return list<string> i file temporanei del caso, da cancellare alla fine */
function saeTemporanei(?string $aggiungi = null): array
{
    static $percorsi = [];

    if ($aggiungi !== null) {
        $percorsi[] = $aggiungi;

        return [];
    }

    [$tutti, $percorsi] = [$percorsi, []];

    return $tutti;
}

/**
 * Un condominio piccolo: tre unità, quattro titolari, due tabelle, due saldi. Si cambia solo ciò che il caso
 * deve cambiare.
 */
function saeModello(array $sovrascrivi = []): string
{
    $dati = array_merge([
        'copertina' => [
            'condominio' => 'CONDOMINIO SCRITTO A MANO',
            'codice_fiscale' => '97555000188',
            'indirizzo' => 'Via del Modello 10, Roma',
            'esercizio' => '2026',
            'data_inizio' => '01/01/2026',
            'data_fine' => '31/12/2026',
        ],
        'unita' => [
            ['B1/1', '1', 'A', '1', 'T', 'appartamento'],
            ['B1/2', '1', 'A', '2', '1', 'appartamento'],
            ['NEG', '1', 'A', '3', 'T', 'negozio'],
        ],
        'persone' => [
            ['B1/1', 'ROSSI MARIO', 'RSSMRA80A01H501U', 'Via Roma 1, Roma', 'proprietario', 100, '', ''],
            ['B1/2', 'BIANCHI ANNA', '', 'Via Roma 2, Roma', 'proprietario', 60, '', ''],
            ['B1/2', 'VERDI LUCA', '', 'Via Roma 2, Roma', 'proprietario', 40, '', ''],
            ['NEG', 'GIALLI SPA', '', 'Via Roma 3, Roma', 'conduttore', 100, '', ''],
        ],
        'tabelle' => [
            'nomi' => ['PROPRIETA GENERALE', 'SCALE'],
            'righe' => [
                ['B1/1', 400, 500],
                ['B1/2', 400, 500],
                ['NEG', 200, ''],
            ],
            'controllo' => [1000, 1000],
        ],
        'saldi' => [
            ['B1/1', 'ROSSI MARIO', 120.50, 'conguaglio 2024/2025'],
            ['B1/2', '', -45, 'credito a favore dell\'unità'],
        ],
    ], $sovrascrivi);

    $base = tempnam(sys_get_temp_dir(), 'sae_');
    $percorso = $base.'.xlsx';
    @unlink($base);
    saeTemporanei($percorso);

    (new ModelloManualeWriter)->scriviSu($percorso);
    $libro = IOFactory::load($percorso);

    $svuota = function (string $foglio, int $da) use ($libro) {
        $f = $libro->getSheetByName($foglio);

        for ($r = $da; $r <= $da + 40; $r++) {
            foreach (range('A', 'J') as $c) {
                $f->setCellValue($c.$r, null);
            }
        }

        return $f;
    };

    $c = $svuota('0 copertina', 6);
    foreach (['condominio', 'codice_fiscale', 'indirizzo', 'esercizio', 'data_inizio', 'data_fine'] as $i => $campo) {
        $c->setCellValue('A'.(6 + $i), $campo);
        $c->setCellValue('B'.(6 + $i), $dati['copertina'][$campo] ?? '');
    }

    $svuota('1 unita', 6)->fromArray($dati['unita'], null, 'A6');
    $svuota('2 persone', 6)->fromArray($dati['persone'], null, 'A6');
    $svuota('4 saldi', 6)->fromArray($dati['saldi'], null, 'A6');

    $t = $svuota('3 tabelle', 7);
    $t->fromArray(['# tabella', ...$dati['tabelle']['nomi']], null, 'A5');
    $t->fromArray(['unita', ...$dati['tabelle']['nomi']], null, 'A6');
    $t->fromArray($dati['tabelle']['righe'], null, 'A7');
    $t->fromArray(['# TOTALE DI CONTROLLO', ...$dati['tabelle']['controllo']], null, 'A'.(7 + count($dati['tabelle']['righe']) + 1));

    (new Xlsx($libro))->save($percorso);
    $libro->disconnectWorksheets();

    return $percorso;
}

function saeCarica(User $come, string $percorso): ImportBatch
{
    test()->actingAs($come)->post(route('import.store'), [
        'file' => [new UploadedFile($percorso, 'modello.xlsx', null, test: true)],
    ]);

    return ImportBatch::orderByDesc('id')->first();
}

/** Le props dell'anteprima, come le legge la pagina (e un agente). */
function saeAnteprima(User $come, ImportBatch $lotto): array
{
    return test()->actingAs($come)->get(route('import.anteprima', $lotto->uuid))
        ->assertOk()
        ->viewData('page')['props'];
}

/**
 * Risponde a ogni cosa che esiste già. Per le persone e il condominio «lascia com'è»; per l'esercizio la scelta
 * del caso, se il caso ne indica una.
 */
function saeDecidiTutto(User $come, ImportBatch $lotto, ?string $esercizio = null): array
{
    foreach (saeAnteprima($come, $lotto)['anteprima']['collisioni']['voci'] as $voce) {
        $scelta = $voce['cosa'] === 'esercizio' && $esercizio !== null ? $esercizio : 'salta';

        test()->actingAs($come)->put(route('import.decisione', $lotto->uuid), [
            'chiave' => $voce['chiave_decisione'],
            'scelta' => $scelta,
        ])->assertRedirect();
    }

    return saeAnteprima($come, $lotto);
}

/** Il condominio del modello, già in archivio con un esercizio suo. */
function saeCondominioConEsercizio(string $nome, string $dal, string $al, string $stato): array
{
    $condominio = Condominio::create([
        'nome' => 'CONDOMINIO SCRITTO A MANO',
        'codice_fiscale' => '97555000188',
        'indirizzo' => 'Via del Modello 10, Roma',
    ]);

    $esercizio = Esercizio::create([
        'condominio_id' => $condominio->id,
        'nome' => $nome,
        'data_inizio' => $dal,
        'data_fine' => $al,
        'stato' => $stato,
    ]);

    return [$condominio, $esercizio];
}

/** Il testo di tutti gli avvisi della busta dell'agente, in una riga. */
function saeTesto($risposta): string
{
    return collect($risposta->json('avvisi'))->pluck('testo')->implode(' | ');
}

beforeEach(function () {
    test()->seed(TipologieImmobiliSeeder::class);
    Storage::fake('local');
});

afterEach(function () {
    foreach (saeTemporanei() as $percorso) {
        @unlink($percorso);
    }
});

// ── 1. La prova della conferma dice com'è andata davvero ────────────────────────────────────────────────

it('la prova della conferma dice che l\'importazione si fermerebbe, a quale passo e perché, e non scrive niente', function () {
    // Un esercizio chiuso con lo stesso nome del file e un altro periodo: nessuna schermata prima della
    // conferma lo vede (l'anteprima dice «confermabile»), il livello «Esercizi» invece si ferma.
    saeCondominioConEsercizio('2026', '2025-07-01', '2026-06-30', 'chiuso');
    $utente = saeUtente();
    $lotto = saeCarica($utente, saeModello());

    $props = saeDecidiTutto($utente, $lotto);
    expect($props['confermabile'])->toBeTrue();

    $risposta = $this->withToken(saeChiave($utente))->withHeaders(['X-Kondo-Prova' => '1'])
        ->postJson(route('import.conferma', $lotto->uuid));

    $risposta->assertStatus(422)->assertJson(['ok' => false, 'prova' => true]);
    expect(saeTesto($risposta))
        ->toContain('si fermerebbe a «Esercizi»')
        ->toContain('periodo diverso')
        ->toContain('non è entrato niente');

    expect(Esercizio::count())->toBe(1)
        ->and(Immobile::count())->toBe(0)
        ->and($lotto->fresh()->stato)->toBe(ImportBatch::STATO_IN_CORSO);
});

it('la conferma vera che si ferma lo dice con le stesse parole, e l\'agente riceve «non riuscito»', function () {
    saeCondominioConEsercizio('2026', '2025-07-01', '2026-06-30', 'chiuso');
    $utente = saeUtente();
    $lotto = saeCarica($utente, saeModello());
    saeDecidiTutto($utente, $lotto);

    $risposta = $this->withToken(saeChiave($utente))->postJson(route('import.conferma', $lotto->uuid));

    $risposta->assertStatus(422)->assertJson(['ok' => false, 'prova' => false]);
    expect(saeTesto($risposta))
        ->toContain('si è fermata a «Esercizi»')
        ->toContain('periodo diverso')
        ->and($risposta->json('rimanda_a'))->toContain('/esito');

    expect($lotto->fresh()->stato)->toBe(ImportBatch::STATO_PARZIALE);
});

it('la prova di un\'importazione che arriva in fondo lo dice, e non lascia niente in archivio', function () {
    $utente = saeUtente();
    $lotto = saeCarica($utente, saeModello());

    $risposta = $this->withToken(saeChiave($utente))->withHeaders(['X-Kondo-Prova' => '1'])
        ->postJson(route('import.conferma', $lotto->uuid));

    $risposta->assertOk()->assertJson(['ok' => true, 'prova' => true]);
    expect(saeTesto($risposta))
        ->toContain('andrebbe in fondo')
        ->toContain('non è entrato niente')
        // L'esito di una prova non esiste: rimandare lì faceva leggere un lotto mai confermato.
        ->and($risposta->json('rimanda_a'))->toContain('/anteprima');

    expect(Condominio::count())->toBe(0)
        ->and($lotto->fresh()->stato)->toBe(ImportBatch::STATO_IN_CORSO);
});

// ── 2. Tabelle e saldi già in archivio: uguali, diversi, nuovi ──────────────────────────────────────────

it('sopra un condominio esistente confronta tabelle e saldi con il file, dice cosa è diverso e non sovrascrive niente', function () {
    $utente = saeUtente();
    $primo = saeCarica($utente, saeModello());
    $this->actingAs($utente)->post(route('import.conferma', $primo->uuid));
    expect($primo->fresh()->stato)->toBe(ImportBatch::STATO_COMPLETATO);

    // Lo stesso condominio, rifatto con una tabella cambiata, una nuova e un saldo diverso.
    $secondo = saeCarica($utente, saeModello([
        'tabelle' => [
            'nomi' => ['PROPRIETA GENERALE', 'SCALE', 'ASCENSORE'],
            'righe' => [
                ['B1/1', 400, 600, 500],
                ['B1/2', 400, 400, 500],
                ['NEG', 200, '', ''],
            ],
            'controllo' => [1000, 1000, 1000],
        ],
        'saldi' => [
            ['B1/1', 'ROSSI MARIO', 120.50, 'conguaglio 2024/2025'],
            ['B1/2', '', -60, 'credito a favore dell\'unità'],
        ],
    ]));

    $props = saeDecidiTutto($utente, $secondo);
    $tabelle = collect($props['anteprima']['tabelle'])->keyBy('nome');

    expect($tabelle['PROPRIETA GENERALE']['in_archivio'])->toBe('uguale')
        ->and($tabelle['PROPRIETA GENERALE']['differenze'])->toBe([])
        ->and($tabelle['ASCENSORE']['in_archivio'])->toBe('nuova')
        ->and($tabelle['SCALE']['in_archivio'])->toBe('diversa')
        ->and(implode(' ', $tabelle['SCALE']['differenze']))->toContain('B1/1')->toContain('500')->toContain('600');

    $saldi = $props['anteprima']['saldi']['in_archivio'];
    expect($saldi['uguali'])->toBe(1)
        ->and($saldi['diversi'])->toBe(1)
        ->and(implode(' ', $saldi['differenze']))->toContain('€ -45,00')->toContain('€ -60,00');

    // La prova della conferma lo dice anche all'agente, prima di scrivere. Dopo, la conferma vera la fa una
    // persona: le intestazioni dell'agente si tolgono, e il disco finto dei test si rimette (la prova sostituisce
    // i dischi per la sua richiesta e poi li dimentica, e quello rifatto dalla configurazione non ha i file).
    $disco = Storage::disk('local');
    $prova = $this->withToken(saeChiave($utente))->withHeaders(['X-Kondo-Prova' => '1'])
        ->postJson(route('import.conferma', $secondo->uuid));
    $this->flushHeaders();
    Storage::set('local', $disco);
    expect(saeTesto($prova))->toContain('SCALE')->toContain('€ -60,00');

    $this->actingAs($utente)->post(route('import.conferma', $secondo->uuid));

    $condominio = Condominio::first();
    $scale = Tabella::where('condominio_id', $condominio->id)->where('nome', 'SCALE')->first();
    $b11 = Immobile::where('condominio_id', $condominio->id)->where('nome', 'appartamento B1/1')->first();

    // Niente è stato sovrascritto: la tabella e il saldo in archivio restano quelli di prima.
    expect($secondo->fresh()->stato)->toBe(ImportBatch::STATO_COMPLETATO)
        ->and((float) QuotaTabella::where('tabella_id', $scale->id)->where('immobile_id', $b11->id)->value('valore'))->toBe(500.0)
        ->and(Saldo::whereNull('anagrafica_id')->value('saldo_iniziale'))->toBe(-4500)
        ->and(Tabella::where('condominio_id', $condominio->id)->where('nome', 'ASCENSORE')->exists())->toBeTrue();

    // E l'esito lo racconta, con che cosa è diverso.
    $livelli = collect($secondo->fresh()->rapporto['livelli'])->keyBy('livello');
    $avvisiTabelle = collect($livelli['tabelle']['avvisi'])->where('codice', 'tabella.diversa_in_archivio');
    $avvisiSaldi = collect($livelli['saldi']['avvisi'])->where('codice', 'saldi.diversi_in_archivio');

    expect($avvisiTabelle)->toHaveCount(1)
        ->and($avvisiTabelle->first()['messaggio'])->toContain('SCALE')->toContain('600')
        ->and($avvisiSaldi)->toHaveCount(1)
        ->and($avvisiSaldi->first()['messaggio'])->toContain('€ -45,00')->toContain('€ -60,00');
});

it('rifatta identica, l\'importazione salta tabelle e saldi senza segnalare differenze', function () {
    $utente = saeUtente();
    $primo = saeCarica($utente, saeModello());
    $this->actingAs($utente)->post(route('import.conferma', $primo->uuid));

    $secondo = saeCarica($utente, saeModello());
    $props = saeDecidiTutto($utente, $secondo);

    expect(collect($props['anteprima']['tabelle'])->pluck('in_archivio')->unique()->all())->toBe(['uguale'])
        ->and($props['anteprima']['saldi']['in_archivio']['uguali'])->toBe(2)
        ->and($props['anteprima']['saldi']['in_archivio']['diversi'])->toBe(0);

    $this->actingAs($utente)->post(route('import.conferma', $secondo->uuid));

    $codici = collect($secondo->fresh()->rapporto['livelli'])->flatMap(fn ($l) => array_column($l['avvisi'], 'codice'));
    expect($codici)->not->toContain('tabella.diversa_in_archivio')
        ->and($codici)->not->toContain('saldi.diversi_in_archivio')
        ->and(Tabella::count())->toBe(2)
        ->and(Saldo::count())->toBe(2);
});

// ── 3. Un esercizio aperto c'è già: un secondo non si apre da solo ──────────────────────────────────────

it('con un esercizio aperto in archivio e un altro periodo nel file chiede che cosa fare, e finché non si decide non si conferma', function () {
    [, $aperto] = saeCondominioConEsercizio('Esercizio 2025', '2025-01-01', '2025-12-31', 'aperto');
    $utente = saeUtente();
    $lotto = saeCarica($utente, saeModello());

    $voce = collect(saeAnteprima($utente, $lotto)['anteprima']['collisioni']['voci'])->firstWhere('cosa', 'esercizio');

    expect($voce)->not->toBeNull()
        ->and($voce['chiave_decisione'])->toBe('esercizi:2026')
        ->and($voce['esistente'])->toBe('Esercizio 2025')
        ->and($voce['motivo'])->toBe('aperto')
        ->and($voce['scelte'])->toBe(['salta', 'crea_nuovo'])
        ->and($voce['spiegazione'])->toContain('01/01/2025')->toContain('2026');

    // Il condominio deciso, l'esercizio no: la conferma resta chiusa.
    $this->actingAs($utente)->put(route('import.decisione', $lotto->uuid), ['chiave' => 'condominio:97555000188', 'scelta' => 'salta']);
    $props = saeAnteprima($utente, $lotto);

    expect($props['confermabile'])->toBeFalse()
        ->and($props['collisioni_aperte'])->toBe(1);

    $this->actingAs($utente)->post(route('import.conferma', $lotto->uuid))->assertSessionHas('message.type', 'error');

    expect(Esercizio::count())->toBe(1)
        ->and(Esercizio::find($aperto->id)->stato)->toBe('aperto');
});

it('«usa quello che c\'è già» mette i dati nell\'esercizio aperto e non ne apre un secondo', function () {
    [, $aperto] = saeCondominioConEsercizio('Esercizio 2025', '2025-01-01', '2025-12-31', 'aperto');
    $utente = saeUtente();
    $lotto = saeCarica($utente, saeModello());

    expect(saeDecidiTutto($utente, $lotto, 'salta')['confermabile'])->toBeTrue();

    $this->actingAs($utente)->post(route('import.conferma', $lotto->uuid));

    $codici = array_column(collect($lotto->fresh()->rapporto['livelli'])->firstWhere('livello', 'esercizi')['avvisi'], 'codice');

    expect($lotto->fresh()->stato)->toBe(ImportBatch::STATO_COMPLETATO)
        ->and(Esercizio::count())->toBe(1)
        ->and(Saldo::pluck('esercizio_id')->unique()->all())->toBe([$aperto->id])
        ->and($codici)->toContain('esercizi.usato_quello_aperto');
});

it('«crea il nuovo esercizio» lo apre e lo dice, e il gestionale resta sull\'esercizio aperto per primo', function () {
    [$condominio] = saeCondominioConEsercizio('Esercizio 2025', '2025-01-01', '2025-12-31', 'aperto');
    $utente = saeUtente();
    $lotto = saeCarica($utente, saeModello());

    saeDecidiTutto($utente, $lotto, 'crea_nuovo');
    $this->actingAs($utente)->post(route('import.conferma', $lotto->uuid));

    $avvisi = collect(collect($lotto->fresh()->rapporto['livelli'])->firstWhere('livello', 'esercizi')['avvisi'])->keyBy('codice');
    $corrente = new class { use HasEsercizio; public function di($c) { return $this->getEsercizioCorrente($c); } };

    expect($lotto->fresh()->stato)->toBe(ImportBatch::STATO_COMPLETATO)
        ->and(Esercizio::where('stato', 'aperto')->count())->toBe(2)
        ->and($avvisi)->toHaveKey('esercizi.due_aperti')
        ->and($avvisi['esercizi.due_aperti']['messaggio'])->toContain('«2026»')->toContain('«Esercizio 2025», aperto per primo')
        // Aprire l'esercizio del file non sposta in silenzio il lavoro di tutti i giorni.
        ->and($corrente->di($condominio)->nome)->toBe('Esercizio 2025');
});

it('con più esercizi aperti l\'importazione e il gestionale scelgono lo stesso: il primo aperto', function () {
    // Prima `getEsercizioCorrente` faceva `first()` senza ordine e l'importazione prendeva quello che inizia più
    // tardi: qui il gestionale diceva «2025» e l'importazione «2026». Creati in un ordine che non è quello delle date.
    $condominio = Condominio::create(['nome' => 'Due Aperti', 'indirizzo' => 'Via X 1']);

    foreach ([['2025', '2025-01-01'], ['2026', '2026-01-01'], ['2024', '2024-01-01']] as [$nome, $dal]) {
        Esercizio::create(['condominio_id' => $condominio->id, 'nome' => $nome, 'data_inizio' => $dal, 'data_fine' => substr($dal, 0, 4).'-12-31', 'stato' => 'aperto']);
    }

    $corrente = new class { use HasEsercizio; public function di($c) { return $this->getEsercizioCorrente($c); } };

    expect($corrente->di($condominio)->nome)->toBe('2025')
        ->and(ImportVerificaService::esercizioApertoDi($condominio->id)->nome)->toBe('2025');
});
