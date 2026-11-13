<?php

/**
 * LA GUARDIA SULLE QUOTE, IN MODIFICA, RIFIUTA SOLO CIÒ CHE PEGGIORA (Web Pro Italia, 13/11/2026).
 *
 * Il caso segnalato: un'unità con due proprietari al 100 ciascuno (somma 200), lasciati così dall'importazione o dai
 * dati di prima delle guardie. La guardia 2 («la somma delle quote per ruolo è ≤ 100 in ogni giorno») rifiutava ogni
 * correzione: una riga a 50 fa 150, l'altra pure — non se ne usciva. Ora, per la **modifica di una riga che esiste
 * già**, un giorno ferma la modifica solo se dopo supera 100 **e** supera quanto c'era prima
 * (`GuardieTitolarita::sforoQuoteInModifica()`).
 *
 * Che cosa presidia questo file:
 * - da 100 + 100 una riga scende a 50 (resta 150, con l'avviso), poi l'altra a 50 (100, senza avviso);
 * - una modifica che alza la somma sopra 100, da sopra o da sotto, si rifiuta, e la frase dice quanto c'era già;
 * - una modifica che non tocca la quota passa anche sopra 100;
 * - il confronto è giorno per giorno, e sul ruolo nuovo;
 * - una riga **nuova** che sfora resta rifiutata, e i passaggi (`sforoQuotePerGiorno()`) restano alla regola di sempre.
 *
 * Che cosa NON copre: i passaggi di titolarità su un'unità sopra 100 (si rifiutano come prima: prima si correggono le
 * quote da «Modifica associazione»).
 */

use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Immobile;
use App\Models\User;
use App\Services\Subentro\GuardieTitolarita;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);

    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);

    $this->condominio = Condominio::factory()->create();
    $this->esercizio = Esercizio::factory()->create([
        'condominio_id' => $this->condominio->id,
        'nome' => 'Esercizio 2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto',
    ]);
    $this->immobile = Immobile::forceCreate([
        'condominio_id' => $this->condominio->id, 'nome' => 'Interno 7', 'descrizione' => 'Appartamento', 'interno' => '7',
    ]);
});

/** Una persona del condominio. */
function gqmPersona(Condominio $c, string $nome): Anagrafica
{
    static $seq = 0;
    $seq++;
    $a = Anagrafica::forceCreate([
        'nome' => $nome, 'email' => "guardiaquote{$seq}@test.it", 'indirizzo' => 'Via Verdi 1',
        'codice_fiscale' => 'GQMTST' . str_pad((string) $seq, 10, '0', STR_PAD_LEFT),
    ]);
    $a->condomini()->syncWithoutDetaching([$c->id]);

    return $a;
}

/** Una riga della pivot scritta direttamente, come la lascia l'importazione: le guardie qui non c'entrano. */
function gqmRiga(Immobile $i, Anagrafica $a, float $quota, string $tipologia = 'proprietario', string $dal = '2026-01-01', ?string $al = null): int
{
    return DB::table('anagrafica_immobile')->insertGetId([
        'immobile_id' => $i->id, 'anagrafica_id' => $a->id, 'tipologia' => $tipologia,
        'quota' => $quota, 'attivo' => true, 'data_inizio' => $dal, 'data_fine' => $al,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** Il corpo di «Modifica associazione», come lo manda il modulo. */
function gqmCorpo(Anagrafica $a, float $quota, array $extra = []): array
{
    return array_merge([
        'anagrafica_id' => $a->id, 'tipologia' => 'proprietario', 'quota' => $quota,
        'data_inizio' => '2026-01-01', 'data_fine' => null, 'note' => null,
    ], $extra);
}

function gqmQuota(int $riga): float
{
    return (float) DB::table('anagrafica_immobile')->where('id', $riga)->value('quota');
}

/** Le righe dell'unità come le legge la guardia. */
function gqmRighe(Immobile $i): \Illuminate\Support\Collection
{
    return $i->titolarita()->get();
}

// ---------------------------------------------------------------------------
// 1. Dal modulo («Modifica associazione»)
// ---------------------------------------------------------------------------

it('da 100 + 100 una riga scende a 50 (resta 150, e lo dice), poi l\'altra scende a 50 e la somma torna 100', function () {
    $rossi = gqmPersona($this->condominio, 'Rossi Mario');
    $bianchi = gqmPersona($this->condominio, 'Bianchi Anna');
    $rigaRossi = gqmRiga($this->immobile, $rossi, 100);
    $rigaBianchi = gqmRiga($this->immobile, $bianchi, 100);
    $rotta = fn (int $riga) => route('admin.gestionale.immobili.anagrafiche.update', [$this->condominio, $this->immobile, $riga]);

    // 200 → 150: non è ancora a posto, ma non peggiora. Passa, con l'avviso.
    $this->actingAs($this->user)
        ->put($rotta($rigaRossi), gqmCorpo($rossi, 50))
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertSessionHas('message.type', 'warning')
        ->assertSessionHas('message.message', fn (string $m) => str_contains($m, 'La somma delle quote per proprietario è ancora 150: correggi anche le altre righe.'));

    expect(gqmQuota($rigaRossi))->toBe(50.0)->and(gqmQuota($rigaBianchi))->toBe(100.0);

    // 150 → 100: a posto, e l'avviso non c'è più.
    $this->actingAs($this->user)
        ->put($rotta($rigaBianchi), gqmCorpo($bianchi, 50))
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertSessionHas('message.type', 'success');

    expect(gqmQuota($rigaRossi))->toBe(50.0)->and(gqmQuota($rigaBianchi))->toBe(50.0);
});

it('una modifica che alza la somma sopra 100 si rifiuta, e la frase dice quanto c\'era già', function () {
    $rossi = gqmPersona($this->condominio, 'Rossi Mario');
    $bianchi = gqmPersona($this->condominio, 'Bianchi Anna');
    gqmRiga($this->immobile, $rossi, 100);
    $rigaBianchi = gqmRiga($this->immobile, $bianchi, 60);
    $rotta = route('admin.gestionale.immobili.anagrafiche.update', [$this->condominio, $this->immobile, $rigaBianchi]);

    // 100 + 60 → 100 + 80: da 160 a 180, peggiora.
    $this->actingAs($this->user)
        ->putJson($rotta, gqmCorpo($bianchi, 80))
        ->assertUnprocessable()
        ->assertJsonPath('errors.quota.0', 'La somma delle quote per proprietario non può superare 100: il 1 gennaio 2026 farebbe 180. Quel giorno è già 160: una modifica può abbassarla, non alzarla.');

    expect(gqmQuota($rigaBianchi))->toBe(60.0);

    // …mentre abbassarla, anche senza arrivare a 100, passa: 160 → 150.
    $this->actingAs($this->user)->putJson($rotta, gqmCorpo($bianchi, 50))->assertRedirect();
    expect(gqmQuota($rigaBianchi))->toBe(50.0);
});

it('da 100 + 100 a 100 + 120 si rifiuta: dal modulo già per il tetto della riga, e dalla guardia perché peggiora', function () {
    $rossi = gqmPersona($this->condominio, 'Rossi Mario');
    $bianchi = gqmPersona($this->condominio, 'Bianchi Anna');
    gqmRiga($this->immobile, $rossi, 100);
    $rigaBianchi = gqmRiga($this->immobile, $bianchi, 100);

    // Dal modulo: 120 su una riga sola lo ferma già `max:100`, prima della guardia.
    $this->actingAs($this->user)
        ->putJson(route('admin.gestionale.immobili.anagrafiche.update', [$this->condominio, $this->immobile, $rigaBianchi]), gqmCorpo($bianchi, 120))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('quota');
    expect(gqmQuota($rigaBianchi))->toBe(100.0);

    // La guardia da sola, senza il tetto della riga: 200 → 220 peggiora.
    $nuova = ['anagrafica_id' => $bianchi->id, 'tipologia' => 'proprietario', 'quota' => 120.0, 'data_inizio' => '2026-01-01', 'data_fine' => null];
    $sforo = GuardieTitolarita::sforoQuoteInModifica(gqmRighe($this->immobile), $nuova, $rigaBianchi);

    expect($sforo)->toBe(['giorno' => '2026-01-01', 'somma' => 220.0, 'prima' => 200.0])
        ->and(GuardieTitolarita::errori(gqmRighe($this->immobile), $nuova, $rigaBianchi))->toHaveKey('quota');
});

it('da sotto 100 a sopra 100 la modifica si rifiuta come sempre, con la frase di sempre', function () {
    $rossi = gqmPersona($this->condominio, 'Rossi Mario');
    $bianchi = gqmPersona($this->condominio, 'Bianchi Anna');
    gqmRiga($this->immobile, $rossi, 50);
    $rigaBianchi = gqmRiga($this->immobile, $bianchi, 50);

    $this->actingAs($this->user)
        ->putJson(route('admin.gestionale.immobili.anagrafiche.update', [$this->condominio, $this->immobile, $rigaBianchi]), gqmCorpo($bianchi, 60))
        ->assertUnprocessable()
        ->assertJsonPath('errors.quota.0', 'La somma delle quote per proprietario non può superare 100: il 1 gennaio 2026 farebbe 110.');

    expect(gqmQuota($rigaBianchi))->toBe(50.0);
});

it('una modifica che non tocca la quota passa anche con la somma a 200, e l\'avviso lo ricorda', function () {
    $rossi = gqmPersona($this->condominio, 'Rossi Mario');
    $bianchi = gqmPersona($this->condominio, 'Bianchi Anna');
    gqmRiga($this->immobile, $rossi, 100);
    $rigaBianchi = gqmRiga($this->immobile, $bianchi, 100);

    $this->actingAs($this->user)
        ->put(route('admin.gestionale.immobili.anagrafiche.update', [$this->condominio, $this->immobile, $rigaBianchi]), gqmCorpo($bianchi, 100, ['note' => 'comproprietaria']))
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertSessionHas('message.type', 'warning')
        ->assertSessionHas('message.message', fn (string $m) => str_contains($m, 'è ancora 200'));

    expect(DB::table('anagrafica_immobile')->where('id', $rigaBianchi)->value('note'))->toBe('comproprietaria');
});

it('una riga NUOVA che sfora resta rifiutata, anche su un\'unità che è già sopra 100', function () {
    $rossi = gqmPersona($this->condominio, 'Rossi Mario');
    $bianchi = gqmPersona($this->condominio, 'Bianchi Anna');
    $verdi = gqmPersona($this->condominio, 'Verdi Luca');
    gqmRiga($this->immobile, $rossi, 100);
    gqmRiga($this->immobile, $bianchi, 100);

    $this->actingAs($this->user)
        ->postJson(route('admin.gestionale.immobili.anagrafiche.store', [$this->condominio, $this->immobile]), [
            'anagrafica_id' => $verdi->id, 'condominio_id' => $this->condominio->id, 'immobile_id' => $this->immobile->id,
            'tipologia' => 'proprietario', 'quota' => 10, 'data_inizio' => '2026-03-01', 'data_fine' => null, 'note' => null,
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.quota.0', 'La somma delle quote per proprietario non può superare 100: il 1 marzo 2026 farebbe 210.');

    expect(DB::table('anagrafica_immobile')->where('immobile_id', $this->immobile->id)->count())->toBe(2);
});

// ---------------------------------------------------------------------------
// 2. La guardia da sola
// ---------------------------------------------------------------------------

it('la strada 200 → 150 → 100 si percorre una riga alla volta; l\'avviso c\'è finché la somma resta sopra 100', function () {
    $rossi = gqmPersona($this->condominio, 'Rossi Mario');
    $bianchi = gqmPersona($this->condominio, 'Bianchi Anna');
    $rigaRossi = gqmRiga($this->immobile, $rossi, 100);
    $rigaBianchi = gqmRiga($this->immobile, $bianchi, 100);
    $a50 = fn (Anagrafica $a) => ['anagrafica_id' => $a->id, 'tipologia' => 'proprietario', 'quota' => 50.0, 'data_inizio' => '2026-01-01', 'data_fine' => null];

    expect(GuardieTitolarita::errori(gqmRighe($this->immobile), $a50($rossi), $rigaRossi))->toBe([])
        ->and(GuardieTitolarita::avvisoSommaAncoraAlta(gqmRighe($this->immobile), $a50($rossi), $rigaRossi))
            ->toBe('La somma delle quote per proprietario è ancora 150: correggi anche le altre righe.');

    DB::table('anagrafica_immobile')->where('id', $rigaRossi)->update(['quota' => 50]);

    expect(GuardieTitolarita::errori(gqmRighe($this->immobile), $a50($bianchi), $rigaBianchi))->toBe([])
        ->and(GuardieTitolarita::avvisoSommaAncoraAlta(gqmRighe($this->immobile), $a50($bianchi), $rigaBianchi))->toBeNull();
});

it('il confronto è giorno per giorno: un periodo allungato su giorni che erano a 100 si rifiuta, anche se altrove l\'unità è già a 200', function () {
    $rossi = gqmPersona($this->condominio, 'Rossi Mario');
    $bianchi = gqmPersona($this->condominio, 'Bianchi Anna');
    gqmRiga($this->immobile, $rossi, 100, 'proprietario', '2020-01-01');
    $rigaBianchi = gqmRiga($this->immobile, $bianchi, 100, 'proprietario', '2021-01-01');
    $nuova = fn (string $dal, float $quota) => ['anagrafica_id' => $bianchi->id, 'tipologia' => 'proprietario', 'quota' => $quota, 'data_inizio' => $dal, 'data_fine' => null];

    // Dal 2021 l'unità è a 200. Portare Bianchi al 2020 farebbe 200 anche nel 2020, che era a 100: peggiora.
    expect(GuardieTitolarita::sforoQuoteInModifica(gqmRighe($this->immobile), $nuova('2020-01-01', 100), $rigaBianchi))
        ->toBe(['giorno' => '2020-01-01', 'somma' => 200.0, 'prima' => 100.0])
        // Accorciare il periodo (dal 2022) non peggiora nessun giorno: passa, anche se resta a 200.
        ->and(GuardieTitolarita::sforoQuoteInModifica(gqmRighe($this->immobile), $nuova('2022-01-01', 100), $rigaBianchi))->toBeNull()
        // E portarla al 2020 insieme alla quota giusta va: 150 nel 2020 no (era 100)…
        ->and(GuardieTitolarita::sforoQuoteInModifica(gqmRighe($this->immobile), $nuova('2020-01-01', 50), $rigaBianchi))
            ->toBe(['giorno' => '2020-01-01', 'somma' => 150.0, 'prima' => 100.0]);

    // …ma dopo aver abbassato l'altra riga sì: la strada c'è sempre, abbassando prima.
    DB::table('anagrafica_immobile')->where('anagrafica_id', $rossi->id)->update(['quota' => 50]);
    expect(GuardieTitolarita::sforoQuoteInModifica(gqmRighe($this->immobile), $nuova('2020-01-01', 50), $rigaBianchi))->toBeNull();
});

it('una riga spostata su un altro ruolo, per quel ruolo, è una riga nuova', function () {
    $rossi = gqmPersona($this->condominio, 'Rossi Mario');
    $bianchi = gqmPersona($this->condominio, 'Bianchi Anna');
    $verdi = gqmPersona($this->condominio, 'Verdi Luca');
    gqmRiga($this->immobile, $rossi, 100);
    $rigaBianchi = gqmRiga($this->immobile, $bianchi, 100);
    gqmRiga($this->immobile, $verdi, 100, 'inquilino');

    // I proprietari sono a 200, gli inquilini a 100: fare di Bianchi un'inquilina al 100 porterebbe gli inquilini a 200.
    $nuova = ['anagrafica_id' => $bianchi->id, 'tipologia' => 'inquilino', 'quota' => 100.0, 'data_inizio' => '2026-01-01', 'data_fine' => null];

    expect(GuardieTitolarita::sforoQuoteInModifica(gqmRighe($this->immobile), $nuova, $rigaBianchi))
        ->toBe(['giorno' => '2026-01-01', 'somma' => 200.0, 'prima' => 100.0])
        ->and(GuardieTitolarita::messaggioSforo('inquilino', ['giorno' => '2026-01-01', 'somma' => 200.0, 'prima' => 100.0]))
            ->toBe('La somma delle quote per inquilino non può superare 100: il 1 gennaio 2026 farebbe 200.');
});

it('i passaggi restano alla regola di sempre: sforoQuotePerGiorno() rifiuta 150 anche partendo da 200', function () {
    $rossi = gqmPersona($this->condominio, 'Rossi Mario');
    $bianchi = gqmPersona($this->condominio, 'Bianchi Anna');
    $rigaRossi = gqmRiga($this->immobile, $rossi, 100);
    gqmRiga($this->immobile, $bianchi, 100);
    $nuova = ['anagrafica_id' => $rossi->id, 'tipologia' => 'proprietario', 'quota' => 50.0, 'data_inizio' => '2026-01-01', 'data_fine' => null];

    // È la funzione che chiamano `RegistraSubentroAction` e `AnnullaPassaggioAction`: non è cambiata.
    expect(GuardieTitolarita::sforoQuotePerGiorno(gqmRighe($this->immobile), $nuova, $rigaRossi))
        ->toBe(['giorno' => '2026-01-01', 'somma' => 150.0])
        // E una riga nuova (senza riga da escludere) passa da lì anche dentro `errori()`.
        ->and(GuardieTitolarita::errori(gqmRighe($this->immobile), ['anagrafica_id' => gqmPersona($this->condominio, 'Neri Paolo')->id] + $nuova, null))
            ->toBe(['quota' => 'La somma delle quote per proprietario non può superare 100: il 1 gennaio 2026 farebbe 250.']);
});
