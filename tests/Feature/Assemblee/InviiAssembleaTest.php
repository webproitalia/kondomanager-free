<?php

/**
 * # Gli invii dell'assemblea: la convocazione agli aventi diritto, il verbale agli assenti (Web Pro Italia, 17/11/2026)
 *
 * Gli invii stanno sul registro degli invii della 1.12 (`registro_invii`), con l'assemblea come documento. Si prova che:
 * l'anteprima dice chi riceve che cosa e chi resta senza indirizzo; l'invio manda un'email per indirizzo con il PDF
 * allegato, scrive il registro, riempie la data dell'avviso e fa «convocata» l'assemblea; senza la conferma esplicita e
 * senza l'elenco delle persone non parte niente; un secondo invio uguale non fa due email; la spedizione fatta fuori dal
 * programma si registra e si annulla; il verbale si manda solo dopo l'approvazione, agli assenti di serie, con il PDF
 * approvato allegato; l'anteprima di un agente AI (`X-Kondo-Prova: 1`) non spedisce e non lascia traccia.
 *
 * Le email non partono davvero: il trasporto è quello di prova (`array`), che tiene i messaggi in memoria, e la coda è
 * `sync`. È la strada dei test delle lettere e dei fogli del conguaglio: così si leggono il destinatario, l'oggetto, il
 * testo e l'allegato del messaggio vero. Con `Notification::fake()` si prova a parte che la notifica va agli indirizzi giusti.
 */

use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\AssembleaPunto;
use App\Models\Assemblee\Verbale;
use App\Models\AzioneAgente;
use App\Models\ChiaveAgente;
use App\Models\Documento;
use App\Models\Gestionale\RegistroInvio;
use App\Notifications\Assemblee\ComunicazioneAssembleaNotification;
use App\Services\Assemblee\AventiDiritto;
use App\Services\Assemblee\InviiAssemblea;
use App\Services\Assemblee\VerbaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/ConvocazioneHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->utente = asmAmministratore();
    $this->actingAs($this->utente);
    Storage::fake('local');
    config(['mail.default' => 'array', 'queue.default' => 'sync']);
    $this->travelTo('2026-11-12 10:00:00');
});

/** L'assemblea da convocare, con il testo già generato; Anna ha anche la secondaria, Carla ha anche la PEC. */
function cnvPronta(array $assemblea = []): array
{
    $s = cnvAssemblea($assemblea);
    // Gli indirizzi sono unici nell'archivio: portano il numero dell'assemblea, così due scenari stanno insieme.
    $s['secondaria'] = 'anna.casa.'.$s['assemblea']->id.'@prova.it';
    $s['pec'] = 'carla.'.$s['assemblea']->id.'@pec.prova.it';
    $s['persone']['Anna Alberti']->forceFill(['email_secondaria' => $s['secondaria']])->save();
    $s['persone']['Carla Conti']->forceFill(['pec' => $s['pec']])->save();
    app(\App\Services\Assemblee\ConvocazioneService::class)->genera($s['assemblea'], null, false);
    $s['ids'] = collect($s['persone'])->map(fn ($p) => $p->id)->all();

    return $s;
}

/** Le righe del registro di un'assemblea, per tipo e (se serve) per persona. */
function cnvRighe(Assemblea $a, string $cosa = 'convocazione', ?int $persona = null): \Illuminate\Support\Collection
{
    return RegistroInvio::where('tipo', InviiAssemblea::TIPI[$cosa])->where('documentabile_type', Assemblea::class)->where('documentabile_id', $a->id)
        ->when($persona !== null, fn ($q) => $q->where('anagrafica_id', $persona))->orderBy('id')->get();
}

/**
 * Un'assemblea tenuta e con il verbale approvato: Anna presente, Bruno (per l'Int. 2, in comproprietà con Carla) per
 * delega, Dino assente e senza email, Elena assente con l'email.
 */
function cnvTenuta(bool $approvata = true): array
{
    $s = asmCondominio([
        'Int. 1' => ['millesimi' => '400', 'titolari' => ['Anna Alberti' => 'proprietario']],
        'Int. 2' => ['millesimi' => '300', 'titolari' => ['Bruno Bassi' => ['proprietario', 60], 'Carla Conti' => ['proprietario', 40]]],
        'Int. 3' => ['millesimi' => '200', 'titolari' => ['Dino Dei' => 'proprietario']],
        'Int. 4' => ['millesimi' => '100', 'titolari' => ['Elena Esposito' => 'proprietario']],
    ], ['convocazione_tenuta' => 'seconda', 'presidente' => 'Anna Alberti', 'segretario' => 'Mario Studio', 'ora_inizio' => '18:30', 'ora_fine' => '20:00', 'stato' => 'chiusa']);
    $s['persone']['Dino Dei']->forceFill(['email' => null, 'indirizzo' => 'Via Verdi 3, 03100 Frosinone'])->save();
    $a = $s['assemblea'];
    app(AventiDiritto::class)->prepara($a);
    asmSegna($a, [$s['persone']['Anna Alberti']]);
    asmSegna($a, [$s['persone']['Bruno Bassi']], 'delega', 'Avv. Rossi');
    $punto = AssembleaPunto::create(['assemblea_id' => $a->id, 'numero' => 1, 'titolo' => 'Approvazione del rendiconto 2025', 'maggioranza' => 'ordinaria', 'esito' => 'approvato']);
    asmVota($punto, [[$s['persone']['Anna Alberti'], 'favorevole'], [$s['persone']['Bruno Bassi'], 'favorevole']]);
    $s['ids'] = collect($s['persone'])->map(fn ($p) => $p->id)->all();

    if ($approvata) {
        $verbali = app(VerbaleService::class);
        $verbali->genera($a->fresh(), null, false);
        $verbali->inviaInApprovazione($a->fresh());
        $verbali->approva($a->fresh(), test()->utente);
    }

    return $s;
}

// ── La convocazione ──────────────────────────────────────────────────────

it('l\'anteprima dice chi riceve che cosa: gli indirizzi di ognuno, chi resta senza, il messaggio d\'esempio, e se si può mandare', function () {
    $s = cnvAssemblea();
    $a = $s['assemblea'];
    $s['persone']['Anna Alberti']->forceFill(['email_secondaria' => 'anna.casa@prova.it'])->save();
    $s['persone']['Carla Conti']->forceFill(['pec' => 'carla@pec.prova.it'])->save();

    // Senza un testo salvato l'avviso non si manda, e l'anteprima dice perché.
    $prima = $this->getJson(cnvIndirizzo('invii.anteprima', $a, ['cosa' => 'convocazione']))->assertOk()->json();
    expect($prima['si_puo_mandare'])->toBeFalse()->and($prima['perche_no'])->toBe(InviiAssemblea::CONVOCAZIONE_SENZA_TESTO);

    app(\App\Services\Assemblee\ConvocazioneService::class)->genera($a, null, false);
    $r = $this->getJson(cnvIndirizzo('invii.anteprima', $a, ['cosa' => 'convocazione']))->assertOk()->json();
    $persone = collect($r['persone'])->keyBy('nome');

    expect($r['si_puo_mandare'])->toBeTrue()
        ->and($r['indirizzi'])->toBe(['email', 'email_secondaria'])
        ->and($persone->keys()->all())->toBe(['Anna Alberti', 'Bruno Bassi', 'Carla Conti', 'Dino Dei'])
        ->and(array_column($persone['Anna Alberti']['indirizzi'], 'indirizzo'))->toBe([$s['persone']['Anna Alberti']->email, 'anna.casa@prova.it'])
        ->and($persone['Anna Alberti']['indirizzo_postale'])->toBe('Via Roma 1, 03100 Frosinone')
        ->and($persone['Anna Alberti']['unita'])->toBe(['Int. 1'])
        ->and($persone['Carla Conti']['rappresentante'])->toBeFalse()
        ->and($persone['Dino Dei']['senza_email'])->toBeTrue()->and($persone['Dino Dei']['proposta'])->toBeTrue()
        ->and($persone->pluck('mandata')->unique()->all())->toBe([false])
        ->and($r['riepilogo'])->toMatchArray(['aventi_diritto' => 3, 'persone' => 4, 'con_email' => 3, 'messaggi' => 4, 'senza_indirizzo' => ['Dino Dei'], 'gia_mandate' => 0])
        ->and($r['oggetto'])->toBe('Convocazione dell\'assemblea ordinaria — Condominio '.$s['condominio']->nome)
        ->and($r['testo'])->toContain('fissata in prima convocazione per il giorno domenica 22 novembre 2026 alle ore 9.00')
        ->and($r['esempio']['saluto'])->toBe('Gentile Anna Alberti,')
        ->and($r['esempio']['chiusura'])->toBe('Cordiali saluti, l\'amministratore del condominio '.$s['condominio']->nome)
        ->and($r['canali_spedizione'])->toHaveKeys(['raccomandata', 'pec', 'a_mano'])
        ->and(array_column($r['avvisi'], 'codice'))->toBe(['elenco_calcolato']);

    // Con la PEC spuntata Carla ha due indirizzi; con un tipo solo, chi non l'ha resta senza.
    $conPec = $this->getJson(cnvIndirizzo('invii.anteprima', $a, ['cosa' => 'convocazione', 'indirizzi' => ['email', 'pec']]))->json();
    expect(array_column(collect($conPec['persone'])->firstWhere('nome', 'Carla Conti')['indirizzi'], 'tipo'))->toBe(['email', 'pec'])
        ->and($conPec['riepilogo']['messaggi'])->toBe(4);

    // L'anteprima non scrive niente.
    expect(RegistroInvio::count())->toBe(0)->and($a->fresh()->stato)->toBe('bozza');
});

it('l\'invio: chi ha l\'indirizzo riceve il suo avviso in PDF, chi non l\'ha resta in elenco, il registro si scrive, l\'assemblea è convocata e la data dell\'avviso è oggi', function () {
    $s = cnvPronta();
    $a = $s['assemblea'];

    $r = $this->postJson(cnvIndirizzo('invii.invia', $a, ['cosa' => 'convocazione']), ['conferma' => true, 'persone' => array_values($s['ids'])])->assertOk()->json();

    expect($r)->toMatchArray(['persone' => 3, 'messaggi' => 4, 'inviate' => 4, 'in_coda' => 0, 'non_partite' => 0, 'senza_indirizzo' => ['Dino Dei'], 'saltate' => [], 'restanti' => null])
        ->and($r['assemblea'])->toBe(['stato' => 'convocata', 'data_avviso' => '2026-11-12'])
        ->and($a->fresh()->stato)->toBe('convocata')
        ->and($a->fresh()->data_avviso->format('Y-m-d'))->toBe('2026-11-12');

    // Il registro: una riga per indirizzo, con l'assemblea come documento; «inviata», legata al registro delle email.
    $righe = cnvRighe($a);
    expect($righe->pluck('destinatario')->all())->toBe([$s['persone']['Anna Alberti']->email, $s['secondaria'], $s['persone']['Bruno Bassi']->email, $s['persone']['Carla Conti']->email])
        ->and($righe->pluck('stato')->unique()->all())->toBe(['inviata'])
        ->and($righe->pluck('canale')->unique()->all())->toBe(['email'])
        ->and($righe->pluck('condominio_id')->unique()->all())->toBe([$a->condominio_id])
        ->and($righe->pluck('emesso_da')->unique()->all())->toBe([$this->utente->id])
        ->and($righe->whereNull('mail_log_id'))->toHaveCount(0)
        ->and(cnvRighe($a, 'convocazione', $s['ids']['Dino Dei']))->toHaveCount(0);

    // Anna: due righe, un avviso solo — il suo — conservato come documento della riga, con la sua impronta.
    $anna = cnvRighe($a, 'convocazione', $s['ids']['Anna Alberti']);
    $documento = Documento::findOrFail($anna[0]->dati['documento_id']);
    $conservato = Storage::disk('local')->get($documento->path);
    expect($anna->pluck('dati.documento_id')->unique())->toHaveCount(1)
        ->and($anna->pluck('impronta')->unique()->all())->toBe([hash('sha256', $conservato)])
        ->and(substr($conservato, 0, 5))->toBe('%PDF-')
        ->and($documento->documentable_type)->toBe(RegistroInvio::class)
        ->and($documento->is_published)->toBeFalsy()
        ->and(Documento::where('documentable_type', RegistroInvio::class)->count())->toBe(3)
        ->and($anna[0]->dati['persona'])->toBe(['id' => $s['ids']['Anna Alberti'], 'nome' => 'Anna Alberti'])
        ->and($anna[0]->dati['tipo_indirizzo'])->toBe('email')->and($anna[1]->dati['tipo_indirizzo'])->toBe('email_secondaria')
        ->and($anna[0]->dati['impronta_testo'])->toBe(hash('sha256', $a->fresh()->convocazione_testo));

    // I messaggi: uno per indirizzo, ognuno con l'avviso della sua persona e mai quello di un altro.
    $messaggi = cnvMessaggi();
    $per = fn (string $indirizzo) => $messaggi->first(fn ($m) => $m->getTo()[0]->getAddress() === $indirizzo);
    $perAnna = $per($s['secondaria']);
    expect($messaggi)->toHaveCount(4)
        ->and($messaggi->map(fn ($m) => count($m->getTo()))->unique()->all())->toBe([1])
        ->and($perAnna->getSubject())->toBe('Convocazione dell\'assemblea ordinaria — Condominio '.$s['condominio']->nome)
        ->and(cnvTesto($perAnna))->toContain('Gentile Anna Alberti,')
        ->toContain('In allegato trova l\'avviso di convocazione')
        ->toContain('domenica 22 novembre 2026 alle ore 9.00')
        ->toContain('Cordiali saluti')
        ->and($perAnna->getAttachments())->toHaveCount(1)
        ->and($perAnna->getAttachments()[0]->getFilename())->toContain('convocazione-assemblea')->toContain('anna-alberti')->toEndWith('.pdf')
        ->and(hash('sha256', $perAnna->getAttachments()[0]->getBody()))->toBe(hash('sha256', $conservato))
        ->and(hash('sha256', $per($s['persone']['Carla Conti']->email)->getAttachments()[0]->getBody()))->toBe(cnvRighe($a, 'convocazione', $s['ids']['Carla Conti'])->sole()->impronta)
        ->and(cnvTesto($per($s['persone']['Bruno Bassi']->email)))->toContain('Gentile Bruno Bassi,');

    // L'avviso partito si rilegge dal registro; quello di una riga che non è di questa assemblea no.
    $pdf = $this->get(cnvIndirizzo('invii.pdf', $a, ['invio' => $anna[0]->id]));
    $pdf->assertOk();
    expect($pdf->getContent())->toBe($conservato);

    // Lo stato degli invii, per persona: Anna raggiunta per email oggi, Dino ancora da fare.
    $dopo = collect($this->getJson(cnvIndirizzo('invii.anteprima', $a, ['cosa' => 'convocazione']))->json('persone'))->keyBy('nome');
    expect($dopo['Anna Alberti'])->toMatchArray(['mandata' => true, 'come' => 'email', 'quando' => '2026-11-12', 'proposta' => false, 'testo_cambiato' => false])
        ->and($dopo['Anna Alberti']['invii'])->toHaveCount(2)
        ->and($dopo['Anna Alberti']['invii'][0])->toMatchArray(['canale' => 'email', 'stato' => 'inviata', 'da' => 'Studio Prova', 'pdf' => true])
        ->and($dopo['Dino Dei'])->toMatchArray(['mandata' => false, 'proposta' => true]);
});

it('la notifica va, a richiesta, a ogni indirizzo scelto delle persone scelte e a nessun altro', function () {
    Notification::fake();
    $s = cnvPronta();
    $a = $s['assemblea'];

    $this->postJson(cnvIndirizzo('invii.invia', $a, ['cosa' => 'convocazione']),
        ['conferma' => true, 'persone' => [$s['ids']['Anna Alberti'], $s['ids']['Carla Conti'], $s['ids']['Dino Dei']], 'indirizzi' => ['email', 'pec']])->assertOk();

    $verso = [];
    Notification::assertSentOnDemand(ComunicazioneAssembleaNotification::class, function ($notifica, $canali, $notificabile) use (&$verso) {
        $verso[] = $notificabile->routes['mail'];

        return true;
    });
    sort($verso);
    $attesi = [$s['persone']['Anna Alberti']->email, $s['persone']['Carla Conti']->email, $s['pec']];
    sort($attesi);
    Notification::assertSentOnDemandTimes(ComunicazioneAssembleaNotification::class, 3);
    expect($verso)->toBe($attesi)
        // Con la posta finta i messaggi non partono: le righe restano in coda, e l'assemblea risulta convocata lo stesso (sono accodate).
        ->and(cnvRighe($a)->pluck('stato')->unique()->all())->toBe(['in_coda'])
        ->and($a->fresh()->stato)->toBe('convocata');
});

it('senza la conferma esplicita, senza l\'elenco delle persone o con una persona che non è fra gli aventi diritto non parte niente', function () {
    $s = cnvPronta();
    $a = $s['assemblea'];
    $url = cnvIndirizzo('invii.invia', $a, ['cosa' => 'convocazione']);
    $estranea = \App\Models\Anagrafica::forceCreate(['nome' => 'Zeno Estraneo', 'email' => 'zeno@prova.it', 'indirizzo' => 'Via Lontana 9']);

    $this->postJson($url, ['persone' => array_values($s['ids'])])->assertStatus(422)->assertJsonValidationErrors('conferma');
    $this->postJson($url, ['conferma' => false, 'persone' => array_values($s['ids'])])->assertStatus(422)->assertJsonValidationErrors('conferma');
    $this->postJson($url, ['conferma' => true])->assertStatus(422)->assertJsonValidationErrors('persone');
    $this->postJson($url, ['conferma' => true, 'persone' => []])->assertStatus(422)->assertJsonValidationErrors('persone');
    $rifiuto = $this->postJson($url, ['conferma' => true, 'persone' => [$s['ids']['Anna Alberti'], $estranea->id]])->assertStatus(422);
    expect($rifiuto->json('errors.persone.0'))->toContain('non è fra gli aventi diritto');
    // Solo chi non ha un indirizzo: non parte niente, e lo dice.
    $soloDino = $this->postJson($url, ['conferma' => true, 'persone' => [$s['ids']['Dino Dei']]])->assertStatus(422);
    expect($soloDino->json('errors.persone.0'))->toContain('senza un indirizzo fra quelli scelti: Dino Dei');
    // Nessun tipo di indirizzo.
    $this->postJson($url, ['conferma' => true, 'persone' => array_values($s['ids']), 'indirizzi' => []])->assertStatus(422)->assertJsonValidationErrors('indirizzi');

    expect(cnvMessaggi())->toHaveCount(0)
        ->and(RegistroInvio::count())->toBe(0)
        ->and($a->fresh()->stato)->toBe('bozza')
        ->and($a->fresh()->data_avviso)->toBeNull();
});

it('un secondo invio uguale non fa due email; con «anche a chi l\'ha già ricevuta» sì; se il testo cambia la pagina lo dice e si rimanda', function () {
    $s = cnvPronta();
    $a = $s['assemblea'];
    $url = cnvIndirizzo('invii.invia', $a, ['cosa' => 'convocazione']);
    $anna = [$s['ids']['Anna Alberti']];

    $this->postJson($url, ['conferma' => true, 'persone' => $anna, 'indirizzi' => ['email']])->assertOk();
    expect(cnvMessaggi())->toHaveCount(1);

    // La stessa richiesta un'altra volta: Anna l'ha già ricevuta con questo testo.
    $doppio = $this->postJson($url, ['conferma' => true, 'persone' => $anna, 'indirizzi' => ['email']])->assertStatus(422);
    expect($doppio->json('errors.persone.0'))->toContain('l\'hanno già ricevuta per email: Anna Alberti')
        ->and(cnvMessaggi())->toHaveCount(1);
    // Insieme a un'altra persona: parte solo per l'altra, e la risposta dice chi è stata saltata.
    $misto = $this->postJson($url, ['conferma' => true, 'persone' => [$s['ids']['Anna Alberti'], $s['ids']['Bruno Bassi']], 'indirizzi' => ['email']])->assertOk()->json();
    expect($misto)->toMatchArray(['persone' => 1, 'messaggi' => 1, 'saltate' => ['Anna Alberti']])->and(cnvMessaggi())->toHaveCount(2);

    $this->postJson($url, ['conferma' => true, 'persone' => $anna, 'indirizzi' => ['email'], 'anche_gia_mandate' => true])->assertOk();
    expect(cnvMessaggi())->toHaveCount(3)->and(cnvRighe($a, 'convocazione', $anna[0]))->toHaveCount(2);

    // Il testo cambia (la sala è un'altra): chi l'ha avuta ha il testo di prima, e rimandarla non è un doppione.
    $this->put(cnvIndirizzo('convocazione.update', $a), ['testo' => $a->fresh()->convocazione_testo."\n\nNota: la sala è al primo piano."])->assertSessionHasNoErrors();
    $stato = collect($this->getJson(cnvIndirizzo('invii.anteprima', $a, ['cosa' => 'convocazione']))->json())->all();
    expect(collect($stato['persone'])->firstWhere('nome', 'Anna Alberti'))->toMatchArray(['mandata' => true, 'testo_cambiato' => true])
        ->and($stato['riepilogo']['testo_cambiato'])->toBe(2);
    $this->postJson($url, ['conferma' => true, 'persone' => $anna, 'indirizzi' => ['email']])->assertOk();
    expect(cnvMessaggi())->toHaveCount(4);
});

it('l\'oggetto e il testo della finestra si usano così come sono scritti, con i segnaposto riempiti per ogni persona', function () {
    $s = cnvPronta();
    $a = $s['assemblea'];

    $this->postJson(cnvIndirizzo('invii.invia', $a, ['cosa' => 'convocazione']), [
        'conferma' => true, 'persone' => [$s['ids']['Bruno Bassi']],
        'oggetto' => 'Assemblea del {{prima.giorno}}', 'testo' => "Le scrivo per l'unità {{destinatario.unita}}.\nA presto [qui](https://esempio.it).",
    ])->assertOk();

    $messaggio = cnvMessaggi()->sole();
    $riga = cnvRighe($a)->sole();
    expect($messaggio->getSubject())->toBe('Assemblea del domenica 22 novembre 2026')
        ->and(cnvTesto($messaggio))->toContain('Le scrivo per l\'unità Int. 2.')->toContain('Gentile Bruno Bassi,')
        // Un testo con la forma di un collegamento non diventa un collegamento nell'email.
        ->and((string) $messaggio->getHtmlBody())->not->toContain('href="https://esempio.it"')
        ->and($riga->dati['testo_modello'])->toContain('{{destinatario.unita}}')
        ->and($riga->dati['testo'])->toContain('Int. 2')->not->toContain('{{');
});

it('«Registra la spedizione»: resta scritto come e quando, per persona; riempie la data dell\'avviso; si annulla, e un\'email partita no', function () {
    $s = cnvPronta();
    $a = $s['assemblea'];
    $url = cnvIndirizzo('invii.spedizione', $a, ['cosa' => 'convocazione']);
    $dino = $s['ids']['Dino Dei'];

    // Una data dopo oggi, un canale che non c'è, una persona che non è dell'assemblea.
    $this->postJson($url, ['persone' => [$dino], 'canale' => 'raccomandata', 'data' => '2026-11-13'])->assertStatus(422)->assertJsonValidationErrors('data');
    $this->postJson($url, ['persone' => [$dino], 'canale' => 'piccione', 'data' => '2026-11-10'])->assertStatus(422)->assertJsonValidationErrors('canale');
    $this->postJson($url, ['persone' => [999999], 'canale' => 'raccomandata', 'data' => '2026-11-10'])->assertStatus(422)->assertJsonValidationErrors('persone');
    expect(RegistroInvio::count())->toBe(0);

    $r = $this->postJson($url, ['persone' => [$dino, $s['ids']['Carla Conti']], 'canale' => 'raccomandata', 'data' => '2026-11-10',
        'numeri' => [$dino => ' 15012345678-9 '], 'nota' => 'Ufficio di Frosinone centro'])->assertOk()->json();

    expect($r)->toBe(['registrate' => 2, 'assemblea' => ['stato' => 'convocata', 'data_avviso' => '2026-11-10']])
        ->and(cnvMessaggi())->toHaveCount(0);
    $riga = cnvRighe($a, 'convocazione', $dino)->sole();
    expect($riga->canale)->toBe('stampa')->and($riga->stato)->toBe('spedita')->and($riga->destinatario)->toBeNull()
        ->and($riga->dati['spedizione'])->toBe(['canale' => 'raccomandata', 'data' => '2026-11-10', 'numero' => '15012345678-9', 'nota' => 'Ufficio di Frosinone centro'])
        ->and($riga->dati['eventi'][0])->toMatchArray(['evento' => 'spedizione', 'da_nome' => 'Studio Prova'])
        ->and(cnvRighe($a, 'convocazione', $s['ids']['Carla Conti'])->sole()->dati['spedizione'])->not->toHaveKey('numero');

    $stato = collect($this->getJson(cnvIndirizzo('invii.anteprima', $a, ['cosa' => 'convocazione']))->json('persone'))->keyBy('nome');
    expect($stato['Dino Dei'])->toMatchArray(['mandata' => true, 'come' => 'raccomandata A/R', 'quando' => '2026-11-10', 'proposta' => false])
        ->and($stato['Dino Dei']['invii'][0]['spedizione'])->toMatchArray(['come' => 'raccomandata A/R', 'numero' => '15012345678-9']);

    // Una registrazione sbagliata si annulla: resta nel registro, e non conta più.
    $this->deleteJson(cnvIndirizzo('invii.annulla', $a, ['invio' => $riga->id]))->assertOk();
    expect($riga->fresh()->stato)->toBe('annullata')
        ->and(collect($riga->fresh()->dati['eventi'])->pluck('evento')->all())->toBe(['spedizione', 'spedizione_annullata']);
    $dopo = collect($this->getJson(cnvIndirizzo('invii.anteprima', $a, ['cosa' => 'convocazione']))->json('persone'))->keyBy('nome');
    expect($dopo['Dino Dei'])->toMatchArray(['mandata' => false, 'proposta' => true]);
    $this->deleteJson(cnvIndirizzo('invii.annulla', $a, ['invio' => $riga->id]))->assertStatus(422);

    // Un'email partita resta com'è.
    $this->postJson(cnvIndirizzo('invii.invia', $a, ['cosa' => 'convocazione']), ['conferma' => true, 'persone' => [$s['ids']['Anna Alberti']], 'indirizzi' => ['email']])->assertOk();
    $email = cnvRighe($a, 'convocazione', $s['ids']['Anna Alberti'])->sole();
    $this->deleteJson(cnvIndirizzo('invii.annulla', $a, ['invio' => $email->id]))->assertStatus(422);
    expect($email->fresh()->stato)->toBe('inviata');
    // La data dell'avviso scritta dalla prima spedizione non cambia con gli invii di dopo.
    expect($a->fresh()->data_avviso->format('Y-m-d'))->toBe('2026-11-10');
});

it('senza un server di posta non parte e non si scrive niente; con l\'assemblea già cominciata l\'avviso non si manda più', function () {
    $s = cnvPronta();
    $a = $s['assemblea'];
    $url = cnvIndirizzo('invii.invia', $a, ['cosa' => 'convocazione']);

    config(['mail.default' => 'log']);
    $senza = $this->postJson($url, ['conferma' => true, 'persone' => array_values($s['ids'])])->assertStatus(422);
    expect($senza->json('errors.posta.0'))->toBe(InviiAssemblea::SENZA_SERVER_POSTA)
        ->and(RegistroInvio::count())->toBe(0)->and($a->fresh()->stato)->toBe('bozza')
        ->and($this->getJson(cnvIndirizzo('invii.anteprima', $a, ['cosa' => 'convocazione']))->json('senza_server_posta'))->toBeTrue();

    config(['mail.default' => 'array']);
    $a->forceFill(['stato' => 'in_corso'])->save();
    $tardi = $this->postJson($url, ['conferma' => true, 'persone' => array_values($s['ids'])])->assertStatus(422);
    expect($tardi->json('errors.convocazione.0'))->toBe(InviiAssemblea::ASSEMBLEA_GIA_TENUTA)->and(RegistroInvio::count())->toBe(0);
});

it('un indirizzo che il server rifiuta: la riga è «non partita» con la frase che lo dice, gli altri partono, e la persona resta da fare', function () {
    $s = cnvPronta();
    $a = $s['assemblea'];
    $rifiutato = $s['persone']['Bruno Bassi']->email;
    \Illuminate\Support\Facades\Mail::extend('rifiuta_assemblea', fn () => new class($rifiutato) extends \Symfony\Component\Mailer\Transport\AbstractTransport
    {
        public function __construct(private string $rifiutato)
        {
            parent::__construct();
        }

        protected function doSend(\Symfony\Component\Mailer\SentMessage $message): void
        {
            foreach ($message->getEnvelope()->getRecipients() as $r) {
                if ($r->getAddress() === $this->rifiutato) {
                    throw new \Symfony\Component\Mailer\Exception\TransportException('Expected response code "250" but got code "550", with message "550 5.1.1 User unknown".');
                }
            }
        }

        public function __toString(): string
        {
            return 'rifiuta_assemblea://';
        }
    });
    config(['mail.mailers.rifiuta_assemblea' => ['transport' => 'rifiuta_assemblea'], 'mail.default' => 'rifiuta_assemblea']);

    $r = $this->postJson(cnvIndirizzo('invii.invia', $a, ['cosa' => 'convocazione']),
        ['conferma' => true, 'persone' => [$s['ids']['Bruno Bassi'], $s['ids']['Carla Conti']], 'indirizzi' => ['email']])->assertOk()->json();

    expect($r)->toMatchArray(['messaggi' => 2, 'inviate' => 1, 'non_partite' => 1])
        ->and($r['errori'])->toBe(['Il server di posta non ha accettato il messaggio (codice 550).'])
        ->and(cnvRighe($a, 'convocazione', $s['ids']['Bruno Bassi'])->sole()->stato)->toBe('non_partita')
        // Una è partita: l'assemblea è convocata.
        ->and($a->fresh()->stato)->toBe('convocata');
    $stato = collect($this->getJson(cnvIndirizzo('invii.anteprima', $a, ['cosa' => 'convocazione']))->json('persone'))->keyBy('nome');
    expect($stato['Bruno Bassi'])->toMatchArray(['mandata' => false, 'non_partita' => true, 'proposta' => true])
        ->and($stato['Bruno Bassi']['invii'][0]['errore'])->toBe('Il server di posta non ha accettato il messaggio (codice 550).')
        ->and($stato['Carla Conti']['mandata'])->toBeTrue();
});

it('un\'assemblea con la convocazione partita non si elimina; un\'altra assemblea, un altro condominio o una cosa che non esiste fanno 404', function () {
    $s = cnvPronta();
    $a = $s['assemblea'];
    $altro = cnvPronta();

    $this->postJson(cnvIndirizzo('invii.invia', $a, ['cosa' => 'convocazione']), ['conferma' => true, 'persone' => [$s['ids']['Anna Alberti']]])->assertOk();
    $riga = cnvRighe($a)->first();

    $this->delete(route('admin.gestionale.assemblee.destroy', ['condominio' => $a->condominio_id, 'assemblea' => $a->id]));
    expect(Assemblea::find($a->id))->not->toBeNull();

    // La riga di un'assemblea non si legge né si annulla da un'altra; l'assemblea di un altro condominio non si apre.
    $this->get(cnvIndirizzo('invii.pdf', $altro['assemblea'], ['invio' => $riga->id]))->assertNotFound();
    $this->deleteJson(cnvIndirizzo('invii.annulla', $altro['assemblea'], ['invio' => $riga->id]))->assertNotFound();
    $this->getJson(route('admin.gestionale.assemblee.invii.anteprima', ['condominio' => $altro['condominio']->id, 'assemblea' => $a->id, 'cosa' => 'convocazione']))->assertNotFound();
    $this->postJson(route('admin.gestionale.assemblee.invii.invia', ['condominio' => $altro['condominio']->id, 'assemblea' => $a->id, 'cosa' => 'convocazione']),
        ['conferma' => true, 'persone' => [$s['ids']['Bruno Bassi']]])->assertNotFound();
    $this->getJson('/admin/gestionale/'.$a->condominio_id.'/assemblee/'.$a->id.'/invii/altro')->assertNotFound();
    // Solo i due messaggi di Anna (primaria e secondaria): nient'altro è partito.
    expect(cnvMessaggi())->toHaveCount(2);
});

it('i permessi: con «Visualizza assemblee» si leggono l\'anteprima e le stampe, ma per preparare e mandare serve «Gestisci assemblee»', function () {
    $s = cnvPronta();
    $a = $s['assemblea'];
    $ruolo = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'collaboratore', 'guard_name' => 'web']);
    $ruolo->syncPermissions(array_map(fn ($p) => $p->value, \App\Enums\Role::COLLABORATORE->permissions()));
    $ruolo->revokePermissionTo(\App\Enums\Permission::MANAGE_ASSEMBLEE->value);
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $collaboratore = \App\Models\User::factory()->create();
    $collaboratore->assignRole($ruolo);
    $this->actingAs($collaboratore);

    // Legge e stampa.
    $this->get(cnvIndirizzo('convocazione.show', $a))->assertOk();
    $this->getJson(cnvIndirizzo('invii.anteprima', $a, ['cosa' => 'convocazione']))->assertOk();
    $this->get(cnvIndirizzo('convocazione.stampa', $a))->assertOk();
    $this->get(cnvIndirizzo('foglio-firme.stampa', $a))->assertOk();
    // Non scrive e non manda.
    $this->put(cnvIndirizzo('convocazione.update', $a), ['testo' => 'no'])->assertForbidden();
    $this->post(cnvIndirizzo('convocazione.genera', $a), ['sovrascrivi' => true])->assertForbidden();
    $this->postJson(cnvIndirizzo('invii.invia', $a, ['cosa' => 'convocazione']), ['conferma' => true, 'persone' => array_values($s['ids'])])->assertForbidden();
    $this->postJson(cnvIndirizzo('invii.spedizione', $a, ['cosa' => 'convocazione']), ['persone' => [$s['ids']['Dino Dei']], 'canale' => 'a_mano', 'data' => '2026-11-12'])->assertForbidden();
    expect(cnvMessaggi())->toHaveCount(0)->and(RegistroInvio::count())->toBe(0)->and($a->fresh()->convocazione_testo)->toContain('AVVISO DI CONVOCAZIONE');

    // Senza «Visualizza assemblee» non si entra nemmeno a leggere.
    $ruolo->revokePermissionTo(\App\Enums\Permission::VIEW_ASSEMBLEE->value);
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $this->get(cnvIndirizzo('convocazione.show', $a))->assertForbidden();
    $this->getJson(cnvIndirizzo('invii.anteprima', $a, ['cosa' => 'convocazione']))->assertForbidden();
    $this->get(cnvIndirizzo('convocazione.per-persona.stampa', $a))->assertForbidden();
});

// ── Il verbale agli assenti ──────────────────────────────────────────────

it('un verbale non approvato non si manda: né per email, né registrando una spedizione, né con la lettera di trasmissione', function () {
    $s = cnvTenuta(false);
    $a = $s['assemblea'];
    app(VerbaleService::class)->genera($a->fresh(), null, false);

    $anteprima = $this->getJson(cnvIndirizzo('invii.anteprima', $a, ['cosa' => 'verbale']))->assertOk()->json();
    expect($anteprima['si_puo_mandare'])->toBeFalse()->and($anteprima['perche_no'])->toBe(InviiAssemblea::VERBALE_NON_APPROVATO);

    $rifiuto = $this->postJson(cnvIndirizzo('invii.invia', $a, ['cosa' => 'verbale']), ['conferma' => true, 'persone' => [$s['ids']['Elena Esposito']]])->assertStatus(422);
    expect($rifiuto->json('errors.verbale.0'))->toBe(InviiAssemblea::VERBALE_NON_APPROVATO);
    $this->postJson(cnvIndirizzo('invii.spedizione', $a, ['cosa' => 'verbale']), ['persone' => [$s['ids']['Dino Dei']], 'canale' => 'a_mano', 'data' => '2026-11-12'])->assertStatus(422);
    $lettera = $this->get(cnvIndirizzo('verbale.trasmissione.stampa', $a));
    $lettera->assertStatus(422);
    expect($lettera->getContent())->toContain('Lettere non stampate.')->toContain('verbale approvato');

    // In attesa di approvazione è lo stesso.
    app(VerbaleService::class)->inviaInApprovazione($a->fresh());
    $this->postJson(cnvIndirizzo('invii.invia', $a, ['cosa' => 'verbale']), ['conferma' => true, 'persone' => [$s['ids']['Elena Esposito']]])->assertStatus(422);

    expect(cnvMessaggi())->toHaveCount(0)->and(RegistroInvio::count())->toBe(0);
});

it('il verbale approvato va di serie agli assenti, con il PDF dell\'archivio allegato; chi non ha un indirizzo resta in elenco, con la lettera e la spedizione registrata', function () {
    $this->travelTo('2026-11-24 10:00:00');
    $s = cnvTenuta();
    $a = $s['assemblea'];
    $verbale = Verbale::where('assemblea_id', $a->id)->firstOrFail();
    $approvato = Storage::disk('local')->get(Documento::findOrFail($verbale->documento_id)->path);

    // Di serie gli assenti: Dino ed Elena. Bruno è intervenuto per delega, e con lui la comproprietaria Carla: non sono assenti.
    $r = $this->getJson(cnvIndirizzo('invii.anteprima', $a, ['cosa' => 'verbale']))->assertOk()->json();
    $persone = collect($r['persone'])->keyBy('nome');
    expect($r['si_puo_mandare'])->toBeTrue()->and($r['chi'])->toBe('assenti')
        ->and($persone->filter(fn ($p) => $p['proposta'])->keys()->all())->toBe(['Dino Dei', 'Elena Esposito'])
        ->and($persone->filter(fn ($p) => $p['assente'])->keys()->all())->toBe(['Dino Dei', 'Elena Esposito'])
        ->and($r['riepilogo'])->toMatchArray(['persone' => 5, 'con_email' => 1, 'messaggi' => 1, 'senza_indirizzo' => ['Dino Dei']])
        ->and($r['oggetto'])->toBe('Verbale dell\'assemblea ordinaria del 23 novembre 2026 — Condominio '.$s['condominio']->nome)
        ->and($r['testo'])->toContain('Le trasmetto in allegato il verbale dell\'assemblea ordinaria');
    // Con «a tutti i condòmini» sono proposti tutti.
    $tutti = $this->getJson(cnvIndirizzo('invii.anteprima', $a, ['cosa' => 'verbale', 'chi' => 'tutti']))->json();
    expect(collect($tutti['persone'])->where('proposta', true))->toHaveCount(5)->and($tutti['riepilogo']['con_email'])->toBe(4);

    $esito = $this->postJson(cnvIndirizzo('invii.invia', $a, ['cosa' => 'verbale']), ['conferma' => true, 'persone' => [$s['ids']['Dino Dei'], $s['ids']['Elena Esposito']]])->assertOk()->json();
    expect($esito)->toMatchArray(['persone' => 1, 'messaggi' => 1, 'inviate' => 1, 'senza_indirizzo' => ['Dino Dei']]);

    // L'allegato è il PDF approvato, quello dell'archivio: non se ne fa un altro.
    $messaggio = cnvMessaggi()->sole();
    $riga = cnvRighe($a, 'verbale')->sole();
    expect($messaggio->getTo()[0]->getAddress())->toBe($s['persone']['Elena Esposito']->email)
        ->and($messaggio->getSubject())->toBe('Verbale dell\'assemblea ordinaria del 23 novembre 2026 — Condominio '.$s['condominio']->nome)
        ->and(cnvTesto($messaggio))->toContain('Gentile Elena Esposito,')->toContain('Le trasmetto in allegato il verbale')
        ->and($messaggio->getAttachments())->toHaveCount(1)
        ->and($messaggio->getAttachments()[0]->getBody())->toBe($approvato)
        ->and($messaggio->getAttachments()[0]->getFilename())->toContain('verbale-assemblea')->not->toContain('bozza')
        ->and($riga->stato)->toBe('inviata')
        ->and($riga->impronta)->toBe(hash('sha256', $approvato))
        ->and($riga->dati['documento_id'])->toBe($verbale->documento_id)
        ->and($riga->dati['versione'])->toBe(1)
        ->and(Documento::where('documentable_type', RegistroInvio::class)->count())->toBe(0)
        // Mandare il verbale non tocca l'assemblea.
        ->and($a->fresh()->stato)->toBe('chiusa')->and($a->fresh()->data_avviso)->toBeNull();

    // Dino non ha un indirizzo: la lettera di trasmissione si stampa (di serie per gli assenti senza email) e la consegna si registra.
    $spia = cnvSpia();
    $lettera = $this->get(cnvIndirizzo('verbale.trasmissione.stampa', $a));
    $lettera->assertOk();
    $foglio = $spia->stampe[0]['dati']['fogli'];
    expect(substr($lettera->getContent(), 0, 5))->toBe('%PDF-')
        ->and(array_map(fn ($f) => $f['destinatario'], $foglio))->toBe([['nome' => 'Dino Dei', 'indirizzo' => 'Via Verdi 3, 03100 Frosinone']])
        ->and($foglio[0]['oggetto'])->toContain('Verbale dell\'assemblea ordinaria del 23 novembre 2026')
        ->and($foglio[0]['saluto'])->toBe('Gentile Dino Dei,')
        ->and($foglio[0]['luogo_data'])->toEndWith('24 novembre 2026')
        ->and($foglio[0]['parti'][0])->toContain('Le trasmetto in allegato il verbale')->toContain('L\'amministratore');
    // Per una persona scelta, anche se ha l'email.
    $this->get(cnvIndirizzo('verbale.trasmissione.stampa', $a, ['persone' => [$s['ids']['Anna Alberti']]]))->assertOk();
    expect($spia->stampe[1]['dati']['fogli'][0]['destinatario']['nome'])->toBe('Anna Alberti');

    $this->postJson(cnvIndirizzo('invii.spedizione', $a, ['cosa' => 'verbale']), ['persone' => [$s['ids']['Dino Dei']], 'canale' => 'a_mano', 'data' => '2026-11-24'])->assertOk();
    $dopo = collect($this->getJson(cnvIndirizzo('invii.anteprima', $a, ['cosa' => 'verbale']))->json('persone'))->keyBy('nome');
    expect($dopo['Dino Dei'])->toMatchArray(['mandata' => true, 'come' => 'a mano', 'quando' => '2026-11-24'])
        ->and($dopo['Elena Esposito'])->toMatchArray(['mandata' => true, 'come' => 'email', 'quando' => '2026-11-24'])
        ->and($dopo->filter(fn ($p) => $p['proposta']))->toHaveCount(0);

    // La pagina del verbale porta l'anteprima dell'invio.
    $this->get(cnvIndirizzo('verbale.show', $a))->assertOk()
        ->assertInertia(fn ($p) => $p->component('gestionale/assemblee/VerbaleEdit')->where('invio.si_puo_mandare', true)->where('invio.riepilogo.gia_mandate', 2));
});

it('una nuova versione del verbale si manda da capo: gli invii della versione di prima restano nel registro e non contano', function () {
    $s = cnvTenuta();
    $a = $s['assemblea'];
    $elena = $s['ids']['Elena Esposito'];
    $this->postJson(cnvIndirizzo('invii.invia', $a, ['cosa' => 'verbale']), ['conferma' => true, 'persone' => [$elena]])->assertOk();

    $verbali = app(VerbaleService::class);
    $verbali->riapri($a->fresh());
    // Riaperto: non è più approvato, e non si manda.
    $this->postJson(cnvIndirizzo('invii.invia', $a, ['cosa' => 'verbale']), ['conferma' => true, 'persone' => [$elena]])->assertStatus(422);
    $verbali->inviaInApprovazione($a->fresh());
    $verbali->approva($a->fresh(), $this->utente);

    $stato = collect($this->getJson(cnvIndirizzo('invii.anteprima', $a, ['cosa' => 'verbale']))->json('persone'))->keyBy('nome');
    expect($stato['Elena Esposito'])->toMatchArray(['mandata' => false, 'proposta' => true])
        ->and($stato['Elena Esposito']['invii'][0])->toMatchArray(['versione' => 1, 'attuale' => false, 'stato' => 'inviata']);

    // La versione 2 parte senza «anche a chi l'ha già ricevuta»: è un altro documento.
    $this->postJson(cnvIndirizzo('invii.invia', $a, ['cosa' => 'verbale']), ['conferma' => true, 'persone' => [$elena]])->assertOk();
    expect(cnvRighe($a, 'verbale', $elena)->pluck('dati.versione')->all())->toBe([1, 2])->and(cnvMessaggi())->toHaveCount(2);
});

it('un verbale riaperto mentre il messaggio è in coda non parte: la riga dice perché', function () {
    Queue::fake();
    $s = cnvTenuta();
    $a = $s['assemblea'];

    $r = $this->postJson(cnvIndirizzo('invii.invia', $a, ['cosa' => 'verbale']), ['conferma' => true, 'persone' => [$s['ids']['Elena Esposito']]])->assertOk()->json();
    expect($r)->toMatchArray(['messaggi' => 1, 'in_coda' => 1, 'inviate' => 0]);

    app(VerbaleService::class)->riapri($a->fresh());
    foreach (Queue::pushed(SendQueuedNotifications::class) as $lavoro) {
        $lavoro->handle(app(ChannelManager::class));
    }

    $riga = cnvRighe($a, 'verbale')->sole();
    expect(cnvMessaggi())->toHaveCount(0)
        ->and($riga->stato)->toBe('non_partita')
        ->and($riga->errore)->toBe(InviiAssemblea::VERBALE_CAMBIATO)
        ->and(\App\Services\Gestionale\Ricevute\ErroreDiPosta::frase($riga->errore))->toBe(InviiAssemblea::VERBALE_CAMBIATO);
});

it('senza il servizio della coda partono al massimo cinquanta messaggi per volta, a persone intere, e la risposta dice chi resta', function () {
    // Ventisei persone assenti con due indirizzi: cinquantadue messaggi.
    $unita = [];
    foreach (range(1, 26) as $n) {
        $unita['Int. '.$n] = ['millesimi' => '38', 'titolari' => [sprintf('Persona %02d', $n) => 'proprietario']];
    }
    // Con le ore di inizio e di fine: dal 17/11/2026 un verbale senza non si manda in approvazione.
    $s = asmCondominio($unita, ['convocazione_tenuta' => 'prima', 'presidente' => 'P', 'segretario' => 'S', 'stato' => 'chiusa', 'ora_inizio' => '18:30', 'ora_fine' => '19:30']);
    $a = $s['assemblea'];
    foreach ($s['persone'] as $nome => $p) {
        $p->forceFill(['email_secondaria' => 'bis-'.$p->email])->save();
    }
    app(AventiDiritto::class)->prepara($a);
    $verbali = app(VerbaleService::class);
    $verbali->genera($a->fresh(), null, false);
    $verbali->inviaInApprovazione($a->fresh());
    $verbali->approva($a->fresh(), $this->utente);
    $ids = collect($s['persone'])->map(fn ($p) => $p->id)->values()->all();

    $r = $this->postJson(cnvIndirizzo('invii.invia', $a, ['cosa' => 'verbale']), ['conferma' => true, 'persone' => $ids])->assertOk()->json();

    expect($r)->toMatchArray(['persone' => 25, 'messaggi' => 50, 'inviate' => 50, 'tetto_messaggi' => 50])
        ->and($r['restanti'])->toBe(['persone' => [$s['persone']['Persona 26']->id], 'messaggi' => 2])
        ->and(cnvMessaggi())->toHaveCount(50);

    // Il secondo giro, con le stesse persone: le venticinque già raggiunte si saltano, parte l'ultima.
    $secondo = $this->postJson(cnvIndirizzo('invii.invia', $a, ['cosa' => 'verbale']), ['conferma' => true, 'persone' => $ids])->assertOk()->json();
    expect($secondo)->toMatchArray(['persone' => 1, 'messaggi' => 2, 'restanti' => null])->and($secondo['saltate'])->toHaveCount(25)
        ->and(cnvMessaggi())->toHaveCount(52);
});

// ── Gli agenti AI ────────────────────────────────────────────────────────

it('un agente AI: senza conferma non spedisce; con la conferma e l\'elenco sì; la sua anteprima (X-Kondo-Prova) non spedisce niente e non lascia traccia', function () {
    $s = cnvPronta();
    $a = $s['assemblea'];
    [, $chiave] = ChiaveAgente::genera('Assistente degli invii', $this->utente, false);
    $agente = fn (array $intestazioni = []) => $this->withHeaders(['Authorization' => 'Bearer '.$chiave] + $intestazioni);
    $url = cnvIndirizzo('invii.invia', $a, ['cosa' => 'convocazione']);
    $spia = cnvSpia();

    // Legge l'anteprima «chi riceve che cosa» con lo stato degli invii.
    $anteprima = $agente()->getJson(cnvIndirizzo('invii.anteprima', $a, ['cosa' => 'convocazione']))->assertOk()->json();
    expect($anteprima['ok'])->toBeTrue()->and($anteprima['dati']['persone'])->toHaveCount(4)->and($anteprima['dati']['riepilogo']['senza_indirizzo'])->toBe(['Dino Dei']);

    // Senza `conferma` (o senza l'elenco delle persone) l'agente non spedisce: il rifiuto dice che cosa manca.
    $senza = $agente()->postJson($url, ['persone' => [$s['ids']['Anna Alberti']]]);
    $senza->assertStatus(422);
    expect($senza->json('errori'))->toHaveKey('conferma')
        ->and(json_encode($senza->json(), JSON_UNESCAPED_UNICODE))->toContain('conferma: true');
    $agente()->postJson($url, ['conferma' => true])->assertStatus(422);
    expect(cnvMessaggi())->toHaveCount(0)->and(RegistroInvio::count())->toBe(0)->and($a->fresh()->stato)->toBe('bozza')
        ->and(AzioneAgente::where('rotta', 'admin.gestionale.assemblee.invii.invia')->orderBy('id')->value('stato'))->toBe(422);

    // Con la conferma esplicita e l'elenco: parte davvero, e l'agente legge lo stato.
    $vero = $agente()->postJson($url, ['conferma' => true, 'persone' => [$s['ids']['Anna Alberti']]])->assertOk()->json();
    expect($vero['ok'])->toBeTrue()->and($vero['prova'])->toBeFalse()
        ->and($vero['dati'])->toMatchArray(['persone' => 1, 'messaggi' => 2, 'inviate' => 2])
        ->and(cnvMessaggi())->toHaveCount(2)
        ->and($spia->stampe)->toHaveCount(1)
        ->and($a->fresh()->stato)->toBe('convocata');
    $stato = collect($agente()->getJson(cnvIndirizzo('invii.anteprima', $a, ['cosa' => 'convocazione']))->json('dati.persone'))->keyBy('nome');
    expect($stato['Anna Alberti']['mandata'])->toBeTrue()->and($stato['Bruno Bassi']['mandata'])->toBeFalse();

    // Una chiave di sola lettura legge l'anteprima ma non manda.
    [, $sola] = ChiaveAgente::genera('Solo lettura', $this->utente, true);
    $this->withHeader('Authorization', 'Bearer '.$sola)->getJson(cnvIndirizzo('invii.anteprima', $a, ['cosa' => 'convocazione']))->assertOk();
    $this->withHeader('Authorization', 'Bearer '.$sola)->postJson($url, ['conferma' => true, 'persone' => [$s['ids']['Carla Conti']]])->assertStatus(403);
    expect(cnvMessaggi())->toHaveCount(2);

    // L'anteprima della scrittura (X-Kondo-Prova), per ultima perché nel processo della prova la posta finta del ponte resta accesa: dice che
    // cosa partirebbe, e non parte niente; nessuna riga, nessun PDF fatto né scritto, l'assemblea com'era.
    $righePrima = RegistroInvio::count();
    $prova = $agente(['X-Kondo-Prova' => '1'])->postJson($url, ['conferma' => true, 'persone' => [$s['ids']['Bruno Bassi'], $s['ids']['Carla Conti']]])->assertOk()->json();
    expect($prova['ok'])->toBeTrue()->and($prova['prova'])->toBeTrue()
        ->and($prova['dati'])->toMatchArray(['persone' => 2, 'messaggi' => 2, 'in_coda' => 2, 'inviate' => 0])
        ->and(cnvMessaggi())->toHaveCount(2)
        ->and(RegistroInvio::count())->toBe($righePrima)
        ->and(cnvRighe($a, 'convocazione', $s['ids']['Bruno Bassi']))->toHaveCount(0)
        ->and(Documento::where('documentable_type', RegistroInvio::class)->count())->toBe(1)
        ->and($spia->stampe)->toHaveCount(1)
        ->and(AzioneAgente::where('rotta', 'admin.gestionale.assemblee.invii.invia')->where('prova', true)->value('stato'))->toBe(200);

    // Anche registrare una spedizione, in anteprima, non lascia righe.
    $agente(['X-Kondo-Prova' => '1'])->postJson(cnvIndirizzo('invii.spedizione', $a, ['cosa' => 'convocazione']), ['persone' => [$s['ids']['Dino Dei']], 'canale' => 'a_mano', 'data' => '2026-11-12'])->assertOk();
    expect(cnvRighe($a, 'convocazione', $s['ids']['Dino Dei']))->toHaveCount(0);
});

it('l\'anteprima di un agente AI su un\'assemblea ancora da convocare non la fa «convocata» e non scrive la data dell\'avviso', function () {
    $s = cnvPronta();
    $a = $s['assemblea'];
    [, $chiave] = ChiaveAgente::genera('Assistente degli invii', $this->utente, false);

    $prova = $this->withHeaders(['Authorization' => 'Bearer '.$chiave, 'X-Kondo-Prova' => '1'])
        ->postJson(cnvIndirizzo('invii.invia', $a, ['cosa' => 'convocazione']), ['conferma' => true, 'persone' => array_values($s['ids'])])->assertOk()->json();

    expect($prova['prova'])->toBeTrue()
        ->and($prova['dati'])->toMatchArray(['persone' => 3, 'messaggi' => 4, 'in_coda' => 4, 'senza_indirizzo' => ['Dino Dei']])
        ->and(cnvMessaggi())->toHaveCount(0)
        ->and(RegistroInvio::count())->toBe(0)
        ->and(Documento::where('documentable_type', RegistroInvio::class)->count())->toBe(0)
        ->and($a->fresh()->stato)->toBe('bozza')
        ->and($a->fresh()->data_avviso)->toBeNull();
});

it('l\'anteprima di un agente AI non spedisce nemmeno il verbale', function () {
    $s = cnvTenuta();
    $a = $s['assemblea'];
    [, $chiave] = ChiaveAgente::genera('Assistente del verbale', $this->utente, false);

    $prova = $this->withHeaders(['Authorization' => 'Bearer '.$chiave, 'X-Kondo-Prova' => '1'])
        ->postJson(cnvIndirizzo('invii.invia', $a, ['cosa' => 'verbale']), ['conferma' => true, 'persone' => [$s['ids']['Elena Esposito']]])->assertOk()->json();

    expect($prova['prova'])->toBeTrue()->and($prova['dati'])->toMatchArray(['messaggi' => 1, 'in_coda' => 1])
        ->and(cnvMessaggi())->toHaveCount(0)->and(cnvRighe($a, 'verbale'))->toHaveCount(0);

    // Senza conferma, niente.
    $this->withHeader('Authorization', 'Bearer '.$chiave)->postJson(cnvIndirizzo('invii.invia', $a, ['cosa' => 'verbale']), ['persone' => [$s['ids']['Elena Esposito']]])->assertStatus(422);
    expect(cnvMessaggi())->toHaveCount(0);
});
