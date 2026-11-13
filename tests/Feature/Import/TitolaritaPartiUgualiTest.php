<?php

/**
 * Più titolari dello stesso ruolo senza quota nel file: parti uguali che sommano 100 (Web Pro Italia, 13/11/2026).
 *
 * ## Il difetto
 *
 * Il file di Danea non porta la quota di comproprietà, e nel modello Excel la colonna `quota_pct` può restare vuota:
 * `LivelloTitolarita` scriveva allora il default dello schema, 100, **a ciascuno**. Due comproprietari sulla stessa
 * unità entravano a 100 e 100, somma 200. Il riparto non ne soffriva — il motore divide per `quota / somma_quote` — ma
 * la guardia sulle quote («la somma per ruolo non supera 100») da lì in poi rifiutava ogni correzione sulla scheda
 * dell'unità.
 *
 * ## La regola
 *
 * **Solo dove il file non dice nessuna quota** per quell'unità e quel ruolo, i titolari entrano in parti uguali che
 * sommano 100: 50 e 50; 33,34, 33,33 e 33,33 (i centesimi che avanzano ai primi del file). Se anche una sola riga del
 * gruppo dichiara la quota, il gruppo si scrive come prima: ciò che il file dice, e 100 dove tace.
 *
 * ## Cosa questo file NON copre
 *
 * La quota scritta a parole nelle note (resta l'avviso del parser), e la decisione «dividi in due» su un nome doppio,
 * che la quota la porta già (`ImportVerificaService`).
 */

use App\Enums\RuoloAnagraficaImmobile;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Immobile;
use App\Models\ImportBatch;
use App\Services\Import\Canonical\CanonicalTitolarita;
use App\Services\Import\EsitoCommit;
use App\Services\Import\ImportContext;
use App\Services\Import\Livelli\LivelloEsercizi;
use App\Services\Import\Livelli\LivelloSoggetti;
use App\Services\Import\Livelli\LivelloTitolarita;
use App\Services\Import\Livelli\LivelloUnita;
use App\Services\Subentro\GuardieTitolarita;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/** `Immobile` non ha una factory: si crea come negli altri test dell'importatore. */
function tpuUnita(Condominio $condominio, string $interno = '1'): Immobile
{
    return Immobile::create([
        'condominio_id' => $condominio->id,
        'nome' => 'Unità '.$interno,
        'descrizione' => 'Unità '.$interno,
        'interno' => $interno,
    ]);
}

/**
 * Esegue il livello con le righe indicate: ognuna è `[unità, persona, ruolo, quota del file o null]`.
 *
 * @param  list<array{0: Immobile, 1: Anagrafica, 2: RuoloAnagraficaImmobile, 3: ?float}>  $righe
 */
function tpuEsegui(Condominio $condominio, array $righe): EsitoCommit
{
    $esercizio = Esercizio::factory()->create(['condominio_id' => $condominio->id]);

    $ctx = new ImportContext(ImportBatch::create(['sorgente' => 'danea']));
    $ctx->risolvi(LivelloEsercizi::CHIAVE, $esercizio);
    $ctx->risolviMolti(LivelloUnita::CHIAVE, collect($righe)->mapWithKeys(fn ($r) => ['u'.$r[0]->getKey() => $r[0]])->all());
    $ctx->risolviMolti(LivelloSoggetti::CHIAVE, collect($righe)->mapWithKeys(fn ($r) => ['s'.$r[1]->getKey() => $r[1]])->all());
    $ctx->conCanonico(LivelloTitolarita::CHIAVE, collect($righe)->map(fn ($r, $i) => new CanonicalTitolarita(
        immobileRef: 'u'.$r[0]->getKey(),
        soggettoRef: 's'.$r[1]->getKey(),
        ruolo: $r[2],
        quota: $r[3],
        rigaSorgente: $i + 2,
    ))->values()->all());

    return (new LivelloTitolarita)->commit($ctx);
}

/** Le quote scritte sull'unità per quel ruolo, nell'ordine in cui sono entrate. */
function tpuQuote(Immobile $immobile, RuoloAnagraficaImmobile $ruolo = RuoloAnagraficaImmobile::PROPRIETARIO): array
{
    return DB::table('anagrafica_immobile')
        ->where('immobile_id', $immobile->getKey())
        ->where('tipologia', $ruolo->value)
        ->orderBy('id')
        ->pluck('quota')
        ->map(fn ($q) => (float) $q)
        ->all();
}

it('due comproprietari senza quota nel file entrano al 50 ciascuno, non al 100', function () {
    $condominio = Condominio::factory()->create();
    $unita = tpuUnita($condominio);
    $pr = RuoloAnagraficaImmobile::PROPRIETARIO;

    $esito = tpuEsegui($condominio, [
        [$unita, Anagrafica::factory()->create(), $pr, null],
        [$unita, Anagrafica::factory()->create(), $pr, null],
    ]);

    expect($esito->creati)->toBe(2)
        ->and(tpuQuote($unita))->toBe([50.0, 50.0]);
});

it('tre comproprietari senza quota sommano 100 esatti: il centesimo che avanza va al primo del file', function () {
    $condominio = Condominio::factory()->create();
    $unita = tpuUnita($condominio);
    $pr = RuoloAnagraficaImmobile::PROPRIETARIO;

    tpuEsegui($condominio, [
        [$unita, Anagrafica::factory()->create(), $pr, null],
        [$unita, Anagrafica::factory()->create(), $pr, null],
        [$unita, Anagrafica::factory()->create(), $pr, null],
    ]);

    expect(tpuQuote($unita))->toBe([33.34, 33.33, 33.33])
        ->and(round(array_sum(tpuQuote($unita)), 2))->toBe(100.0);
});

it('un titolare solo resta al 100, e ruoli diversi non si dividono fra loro', function () {
    $condominio = Condominio::factory()->create();
    $solo = tpuUnita($condominio, '1');
    $mista = tpuUnita($condominio, '2');

    tpuEsegui($condominio, [
        [$solo, Anagrafica::factory()->create(), RuoloAnagraficaImmobile::PROPRIETARIO, null],
        // Proprietario e inquilino sulla stessa unità: ognuno è solo nel suo ruolo.
        [$mista, Anagrafica::factory()->create(), RuoloAnagraficaImmobile::PROPRIETARIO, null],
        [$mista, Anagrafica::factory()->create(), RuoloAnagraficaImmobile::INQUILINO, null],
        // Usufrutto e nuda proprietà: due ruoli, 100 e 100.
        [$mista, Anagrafica::factory()->create(), RuoloAnagraficaImmobile::USUFRUTTUARIO, null],
        [$mista, Anagrafica::factory()->create(), RuoloAnagraficaImmobile::NUDA_PROPRIETA, null],
    ]);

    expect(tpuQuote($solo))->toBe([100.0])
        ->and(tpuQuote($mista))->toBe([100.0])
        ->and(tpuQuote($mista, RuoloAnagraficaImmobile::INQUILINO))->toBe([100.0])
        ->and(tpuQuote($mista, RuoloAnagraficaImmobile::USUFRUTTUARIO))->toBe([100.0])
        ->and(tpuQuote($mista, RuoloAnagraficaImmobile::NUDA_PROPRIETA))->toBe([100.0]);
});

it('ogni unità si divide per conto suo', function () {
    $condominio = Condominio::factory()->create();
    $a = tpuUnita($condominio, '1');
    $b = tpuUnita($condominio, '2');
    $pr = RuoloAnagraficaImmobile::PROPRIETARIO;
    $stessa = Anagrafica::factory()->create();

    tpuEsegui($condominio, [
        [$a, $stessa, $pr, null],
        [$a, Anagrafica::factory()->create(), $pr, null],
        // La stessa persona è da sola sull'altra unità: lì resta al 100.
        [$b, $stessa, $pr, null],
    ]);

    expect(tpuQuote($a))->toBe([50.0, 50.0])
        ->and(tpuQuote($b))->toBe([100.0]);
});

it('se il file porta la quota, si scrive quella: le parti uguali valgono solo dove il file tace del tutto', function () {
    $condominio = Condominio::factory()->create();
    $dichiarate = tpuUnita($condominio, '1');
    $aMeta = tpuUnita($condominio, '2');
    $pr = RuoloAnagraficaImmobile::PROPRIETARIO;

    tpuEsegui($condominio, [
        [$dichiarate, Anagrafica::factory()->create(), $pr, 70.0],
        [$dichiarate, Anagrafica::factory()->create(), $pr, 30.0],
        // Una riga con la quota e una senza: il gruppo resta com'era prima (ciò che il file dice, 100 dove tace).
        [$aMeta, Anagrafica::factory()->create(), $pr, 60.0],
        [$aMeta, Anagrafica::factory()->create(), $pr, null],
    ]);

    expect(tpuQuote($dichiarate))->toBe([70.0, 30.0])
        ->and(tpuQuote($aMeta))->toBe([60.0, 100.0]);
});

it('la stessa persona scritta due volte sulla stessa unità conta una, e resta al 100', function () {
    $condominio = Condominio::factory()->create();
    $unita = tpuUnita($condominio);
    $pr = RuoloAnagraficaImmobile::PROPRIETARIO;
    $stessa = Anagrafica::factory()->create();

    $esito = tpuEsegui($condominio, [
        [$unita, $stessa, $pr, null],
        [$unita, $stessa, $pr, null],
    ]);

    expect($esito->creati)->toBe(1)
        ->and(tpuQuote($unita))->toBe([100.0]);
});

it('dopo l\'importazione la scheda dell\'unità non è bloccata: la guardia sulle quote lascia correggere una riga', function () {
    // È la ragione della correzione: con 100 e 100 la guardia rifiutava da subito qualunque quota diversa.
    $condominio = Condominio::factory()->create();
    $unita = tpuUnita($condominio);
    $pr = RuoloAnagraficaImmobile::PROPRIETARIO;
    $primo = Anagrafica::factory()->create();

    tpuEsegui($condominio, [
        [$unita, $primo, $pr, null],
        [$unita, Anagrafica::factory()->create(), $pr, null],
    ]);

    $riga = $unita->titolarita()->where('anagrafica_id', $primo->getKey())->firstOrFail();
    $nuova = [
        'anagrafica_id' => $primo->getKey(), 'tipologia' => $pr->value, 'quota' => 40.0,
        'data_inizio' => $riga->data_inizio, 'data_fine' => null,
    ];

    expect(GuardieTitolarita::sforoQuotePerGiorno($unita->titolarita()->get(), $nuova, (int) $riga->id))->toBeNull();
});
