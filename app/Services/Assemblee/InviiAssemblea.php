<?php

namespace App\Services\Assemblee;

use App\Helpers\DateHelper;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\Verbale;
use App\Models\Assemblee\VerbaleModello;
use App\Models\Documento;
use App\Models\Gestionale\RegistroInvio;
use App\Models\User;
use App\Notifications\Assemblee\ComunicazioneAssembleaNotification;
use App\Services\Documenti\ArchivioDocumenti;
use App\Services\Gestionale\Invii\InviiPerPersona;
use App\Services\Gestionale\Lettere\EmissioneLettere;
use App\Services\Gestionale\Lettere\RegistroLettere;
use App\Services\Gestionale\Lettere\TestiLettere;
use App\Services\Gestionale\Ricevute\EmissioneRicevute;
use App\Services\Gestionale\Ricevute\ErroreDiPosta;
use App\Support\Agenti\DiscoInProva;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Gli invii dell'assemblea (Web Pro Italia, 17/11/2026): l'avviso di convocazione agli aventi diritto, e il verbale
 * approvato agli assenti (o a tutti). Per ogni persona resta scritto quando e come le è stato mandato: è quello che
 * serve a dimostrare la regolare convocazione, e da quando decorre il termine per impugnare di chi era assente.
 *
 * ## Perché sta sul registro degli invii delle lettere, e non su una tabella sua
 *
 * `registro_invii` (1.12) è nato come «una tabella sola per tutti i documenti mandati a una persona»: ha già la
 * persona, il canale, l'indirizzo, lo stato, l'errore, chi e quando, il legame con il registro delle email e un
 * documento qualunque come «documentabile». Qui il documento è l'assemblea, e i tipi sono due:
 * {@see RegistroInvio::TIPO_CONVOCAZIONE_ASSEMBLEA} e {@see RegistroInvio::TIPO_VERBALE_ASSEMBLEA}. Si riusa, senza
 * toccarlo, tutto quello che le lettere e i fogli del conguaglio hanno già:
 *
 * - **gli indirizzi** di una persona sono quelli di {@see EmissioneRicevute::indirizzi()}: la primaria e la secondaria
 *   proposte, la PEC solo se la si spunta (il programma la manda come email semplice);
 * - **la posta**: un messaggio per indirizzo, in coda, con il gettone che il registro delle email (`LogSentMessage`)
 *   usa per portare la riga a «inviata» quando il server accetta il messaggio, o a «non partita» con l'errore, detto
 *   in chiaro da {@see ErroreDiPosta}. «Inviata» non vuol dire arrivata. Senza un server di posta non si accoda niente;
 * - **i tetti**: senza il servizio della coda al massimo {@see EmissioneRicevute::TETTO_MESSAGGI_SENZA_CODA} messaggi
 *   per volta, a persone intere, e per la convocazione al massimo {@see PDF_PER_RICHIESTA} avvisi per volta (ognuno è
 *   un PDF fatto nella richiesta): le altre persone tornano in `restanti`;
 * - **la spedizione registrata a mano** ha la forma di quella delle lettere (`dati.spedizione`: canale, data, numero,
 *   nota; i canali sono {@see RegistroLettere::CANALI_SPEDIZIONE}), con la riga `stampa` / `spedita`.
 *
 * I servizi delle lettere ({@see EmissioneLettere}, {@see RegistroLettere}) non si chiamano per scrivere: sono
 * costruiti sulle rate e sui debiti, e leggono solo i loro tipi. Ogni lettura del registro filtra per tipo, quindi le
 * righe dell'assemblea non compaiono nelle pagine delle lettere, delle ricevute e dei fogli.
 *
 * ## Che cosa resta scritto
 *
 * - **Per email**: una riga per persona e indirizzo, con l'oggetto e il testo partiti, il nome dell'allegato e la sua
 *   impronta SHA-256. L'avviso di convocazione di ogni persona (con il suo nome, le sue unità e il modulo di delega
 *   già intestato) si conserva come documento della riga: è la prova di che cosa è partito. Il verbale è uno, quello
 *   dell'archivio del condominio: la riga ne tiene il numero e l'impronta.
 * - **Spedita fuori dal programma** (raccomandata, PEC, consegna a mano…): una riga per persona con il canale, la
 *   data, il numero e la nota. Una registrazione sbagliata si annulla ({@see annulla()}): la riga resta, con chi e
 *   quando, e non conta più.
 * - L'impronta del testo della convocazione (`dati.impronta_testo`) o la versione del verbale (`dati.versione`): se
 *   dopo l'invio il testo cambia, la pagina lo dice.
 *
 * ## Chi può mandare
 *
 * L'invio per email è una scrittura verso l'esterno. La richiesta porta sempre la conferma esplicita e l'elenco delle
 * persone (`conferma`, `persone`: vedi `InviaComunicazioneAssembleaRequest`), per una persona come per un agente AI.
 * Chi l'ha già ricevuta per email (stesso testo, stessa versione) si salta, se non lo si chiede con
 * `anche_gia_mandate`: due clic, o due richieste uguali, non fanno due email. Nell'anteprima di un agente
 * (`X-Kondo-Prova: 1`) la posta è finta, il database torna com'era e non si scrive nessun PDF.
 */
final class InviiAssemblea
{
    public const CONVOCAZIONE = 'convocazione';
    public const VERBALE = 'verbale';

    /** Che cosa si manda => il tipo nel registro degli invii. */
    public const TIPI = [
        self::CONVOCAZIONE => RegistroInvio::TIPO_CONVOCAZIONE_ASSEMBLEA,
        self::VERBALE => RegistroInvio::TIPO_VERBALE_ASSEMBLEA,
    ];

    /** Gli avvisi di convocazione per richiesta: ognuno è un PDF fatto mentre la pagina aspetta. */
    public const PDF_PER_RICHIESTA = 40;

    public const LUNGHEZZA_OGGETTO = 200;

    public const SENZA_SERVER_POSTA = 'Nessun server di posta configurato: le email non partono. Configuralo nelle impostazioni della posta; intanto puoi stampare e registrare la spedizione.';
    public const SCEGLI_GLI_INDIRIZZI = 'Scegli a quali indirizzi mandare: primaria, secondaria, PEC.';
    public const VERBALE_NON_APPROVATO = 'Si manda solo un verbale approvato, con il suo PDF nell\'archivio: questo non lo è ancora.';
    public const VERBALE_CAMBIATO = 'Il verbale è stato riaperto dopo che il messaggio era in coda: non è partito. Quando la nuova versione è approvata, mandala di nuovo.';
    public const CONVOCAZIONE_SENZA_TESTO = 'La convocazione non ha ancora un testo: generalo dal modello (o scrivilo) prima di mandarla.';
    public const CONVOCAZIONE_SENZA_DATA = 'Manca la data della prima convocazione: scrivila prima di mandare l\'avviso.';
    public const ASSEMBLEA_GIA_TENUTA = 'L\'assemblea è già cominciata o chiusa: l\'avviso di convocazione non si manda più. Una spedizione fatta prima si può ancora registrare.';

    /** I PDF scritti sul disco nell'operazione in corso: se l'operazione non riesce, si tolgono. */
    private array $scritti = [];

    public function __construct(
        private readonly DestinatariAssemblea $destinatari,
        private readonly ConvocazioneService $convocazione,
        private readonly SegnapostiConvocazione $segnaposti,
        private readonly VerbaleService $verbali,
        private readonly ArchivioDocumenti $archivio,
    ) {}

    // ── L'anteprima «chi riceve che cosa» ────────────────────────────────

    /**
     * Chi riceve che cosa, prima di mandare: per ogni avente diritto gli indirizzi dei tipi scelti (o che non ne ha
     * uno), se è fra quelli proposti (per il verbale: gli assenti, o tutti), che cosa gli è già stato mandato e come;
     * l'oggetto e il testo di partenza, dal modello dello studio; il messaggio della prima persona con i segnaposto
     * riempiti; il server di posta e i tetti; se si può mandare e, se no, perché.
     *
     * @param  array<string, mixed>  $input  `indirizzi` (i tipi), `chi` (per il verbale: `assenti` o `tutti`)
     * @return array<string, mixed>
     */
    public function anteprima(Assemblea $assemblea, string $cosa, array $input = []): array
    {
        $tipi = self::tipiScelti($input['indirizzi'] ?? null);
        $chi = $cosa === self::VERBALE && ($input['chi'] ?? null) !== DestinatariAssemblea::TUTTI ? DestinatariAssemblea::ASSENTI : DestinatariAssemblea::TUTTI;
        $letto = $this->destinatari->teste($assemblea);
        $persone = $this->destinatari->persone($assemblea);
        $stato = $this->stato($assemblea, $cosa);
        $testi = $this->testiDiPartenza($assemblea, $cosa);

        $righe = [];
        $messaggi = 0;
        $conEmail = 0;
        $senza = [];
        foreach ($persone as $p) {
            $tutti = EmissioneRicevute::indirizzi($p['persona']);
            $scelti = array_intersect_key($tutti, array_flip($tipi));
            $suo = $stato[$p['id']] ?? self::statoVuoto();
            $proposta = ($chi === DestinatariAssemblea::TUTTI || $p['assente'] === true) && ! $suo['mandata'] && ! $suo['in_coda'];
            if ($proposta && $scelti !== []) {
                $conEmail++;
                $messaggi += count($scelti);
            }
            if ($proposta && $scelti === []) {
                $senza[] = $p['nome'];
            }
            $righe[] = [
                'id' => $p['id'],
                'nome' => $p['nome'],
                'indirizzo_postale' => $p['indirizzo'],
                'unita' => $p['unita'],
                'millesimi' => $p['millesimi'],
                'rappresentante' => $p['rappresentante'],
                'assente' => $p['assente'],
                'indirizzi' => array_map(fn ($t, $i) => ['tipo' => $t, 'indirizzo' => $i], array_keys($scelti), array_values($scelti)),
                'senza_email' => $scelti === [],
                'solo_pec' => $scelti === [] && isset($tutti['pec']),
                'proposta' => $proposta,
            ] + $suo;
        }

        [$puo, $perche] = $this->siPuoMandare($assemblea, $cosa);
        $prima = $persone[0] ?? null;

        return [
            'cosa' => $cosa,
            'chi' => $chi,
            'indirizzi' => $tipi,
            'tipi_indirizzo' => EmissioneRicevute::TIPI_INDIRIZZO,
            'persone' => $righe,
            'riepilogo' => [
                'aventi_diritto' => count($letto['teste']),
                'persone' => count($persone),
                'con_email' => $conEmail,
                'messaggi' => $messaggi,
                'senza_indirizzo' => $senza,
                'gia_mandate' => count(array_filter($righe, fn ($r) => $r['mandata'])),
                'in_coda' => count(array_filter($righe, fn ($r) => $r['in_coda'])),
                'non_partite' => count(array_filter($righe, fn ($r) => $r['non_partita'])),
                'testo_cambiato' => count(array_filter($righe, fn ($r) => $r['mandata'] && $r['testo_cambiato'])),
            ],
            'elenco_preparato' => $letto['preparato'],
            'oggetto' => $testi['oggetto'],
            'testo' => $testi['testo'],
            'modello' => $testi['modello'],
            'esempio' => $prima === null ? null : ['persona' => $prima['nome']] + self::messaggio($assemblea, $this->testiDellaPersona($assemblea, $testi, $prima)),
            'allegato' => $cosa === self::CONVOCAZIONE ? $this->convocazione->nomeFile($assemblea) : $this->verbali->nomeFile($assemblea, false),
            'senza_server_posta' => EmissioneRicevute::senzaServerPosta(),
            'tetto_messaggi' => EmissioneRicevute::tettoMessaggi(),
            'tetto_pdf' => $cosa === self::CONVOCAZIONE ? self::PDF_PER_RICHIESTA : null,
            'canali_spedizione' => RegistroLettere::CANALI_SPEDIZIONE,
            'si_puo_mandare' => $puo,
            'perche_no' => $perche,
        ];
    }

    // ── L'invio per email ────────────────────────────────────────────────

    /**
     * L'invio: per ogni persona scelta (nell'ordine dei nomi) un messaggio per ognuno dei suoi indirizzi dei tipi
     * scelti, con il PDF allegato; dentro i tetti, a persone intere. La risposta dice quanti messaggi sono partiti, in
     * coda o non partiti con l'errore, chi è rimasto senza indirizzo, chi è stato saltato perché l'aveva già
     * ricevuta, chi resta per un altro giro, e com'è adesso l'assemblea.
     *
     * @param  array<string, mixed>  $input  `persone`, `indirizzi`, `oggetto`, `testo`, `anche_gia_mandate`
     * @return array<string, mixed>
     *
     * @throws ValidationException se non si può mandare: lo dice con una frase che la pagina e l'agente leggono
     */
    public function invia(Assemblea $assemblea, string $cosa, array $input, ?User $utente): array
    {
        [$puo, $perche] = $this->siPuoMandare($assemblea, $cosa);
        if (! $puo) {
            throw ValidationException::withMessages([$cosa === self::VERBALE ? 'verbale' : 'convocazione' => $perche]);
        }
        if (EmissioneRicevute::senzaServerPosta()) {
            throw ValidationException::withMessages(['posta' => self::SENZA_SERVER_POSTA]);
        }
        $tipi = self::tipiScelti($input['indirizzi'] ?? null);
        if ($tipi === []) {
            throw ValidationException::withMessages(['indirizzi' => self::SCEGLI_GLI_INDIRIZZI]);
        }
        $testi = $this->testiScelti($assemblea, $cosa, $input);
        $universo = collect($this->destinatari->persone($assemblea))->keyBy('id');
        $chieste = self::identificativi($input['persone'] ?? []);
        self::assicuraAventiDiritto($chieste, $universo->keys()->all());

        $stato = $this->stato($assemblea, $cosa);
        $anche = filter_var($input['anche_gia_mandate'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $gruppi = [];
        $senza = [];
        $saltate = [];
        // Nell'ordine dei nomi, come l'anteprima: «restanti» è così sempre la coda dello stesso elenco.
        foreach ($universo->only($chieste) as $p) {
            $scelti = array_intersect_key(EmissioneRicevute::indirizzi($p['persona']), array_flip($tipi));
            if ($scelti === []) {
                $senza[] = $p['nome'];

                continue;
            }
            if (! $anche && ($stato[$p['id']]['per_email_adesso'] ?? false)) {
                $saltate[] = $p['nome'];

                continue;
            }
            $gruppi[] = ['persona' => $p, 'destinazioni' => array_map(fn ($t, $i) => [$i, $t], array_keys($scelti), array_values($scelti))];
        }
        if ($gruppi === []) {
            throw ValidationException::withMessages(['persone' => 'Non parte niente: '.implode(' ', array_filter([
                $senza !== [] ? 'senza un indirizzo fra quelli scelti: '.implode(', ', $senza).' (stampa per loro e registra la spedizione).' : null,
                $saltate !== [] ? 'l\'hanno già ricevuta per email: '.implode(', ', $saltate).' (per rimandarla, chiedilo con «anche a chi l\'ha già ricevuta»).' : null,
            ]))]);
        }

        [$dentro, $fuori] = InviiPerPersona::dentroIDueTetti($gruppi, EmissioneRicevute::tettoMessaggi(),
            $cosa === self::CONVOCAZIONE ? self::PDF_PER_RICHIESTA : null, fn (array $g) => count($g['destinazioni']));

        $adesso = now();
        $inProva = $this->archivio->disco() instanceof DiscoInProva;
        $verbale = $cosa === self::VERBALE ? $this->verbaleDaMandare($assemblea) : null;
        $ids = [];
        $fatte = [];
        foreach ($dentro as $g) {
            $righe = $this->scrivi($assemblea, $cosa, $g['persona'], $g['destinazioni'], $testi, $tipi, $verbale, $utente, $adesso, $inProva);
            $ids = [...$ids, ...$this->spedisci($righe)];
            $fatte[] = (int) $g['persona']['id'];
        }

        // Quando la convocazione parte: la data dell'avviso si riempie se è vuota, e l'assemblea risulta convocata.
        if ($cosa === self::CONVOCAZIONE && RegistroInvio::whereIn('id', $ids ?: [0])->whereIn('stato', [RegistroInvio::STATO_IN_CODA, RegistroInvio::STATO_INVIATA])->exists()) {
            $this->segnaConvocata($assemblea, DateHelper::oggiUtente());
        }

        return $this->riepilogo($ids, count($fatte)) + [
            'cosa' => $cosa,
            'fatte' => $fatte,
            'senza_indirizzo' => $senza,
            'saltate' => $saltate,
            'restanti' => $fuori === null ? null : ['persone' => array_map(fn ($g) => (int) $g['persona']['id'], $fuori),
                'messaggi' => array_sum(array_map(fn ($g) => count($g['destinazioni']), $fuori))],
            'assemblea' => ['stato' => $assemblea->stato, 'data_avviso' => $assemblea->data_avviso?->format('Y-m-d')],
        ];
    }

    // ── La spedizione registrata a mano ──────────────────────────────────

    /**
     * «Registra la spedizione»: per le persone date, che l'avviso (o il verbale) è stato spedito o consegnato fuori dal
     * programma — raccomandata, PEC, a mano, posta, altro — con la data, il numero di ognuna se c'è, una nota. Una riga
     * del registro per persona. Per la convocazione la data dell'avviso si riempie se è vuota (con la data della
     * spedizione) e l'assemblea risulta convocata.
     *
     * @param  list<int>  $persone
     * @param  array<int|string, mixed>  $numeri  il numero (della raccomandata, del protocollo) per persona
     * @return array{registrate: int, assemblea: array{stato: string, data_avviso: ?string}}
     */
    public function registraSpedizione(Assemblea $assemblea, string $cosa, array $persone, string $canale, string $data, array $numeri, ?string $nota, ?User $utente): array
    {
        if ($cosa === self::VERBALE) {
            $this->verbaleDaMandare($assemblea);
        }
        $universo = collect($this->destinatari->persone($assemblea))->keyBy('id');
        $chieste = self::identificativi($persone);
        self::assicuraAventiDiritto($chieste, $universo->keys()->all());

        $adesso = now();
        $versione = $this->versione($assemblea, $cosa);
        DB::transaction(function () use ($assemblea, $cosa, $universo, $chieste, $canale, $data, $numeri, $nota, $utente, $adesso, $versione) {
            foreach ($universo->only($chieste) as $p) {
                $numero = $numeri[$p['id']] ?? null;
                $spedizione = array_filter([
                    'canale' => $canale,
                    'data' => $data,
                    'numero' => is_scalar($numero) && trim((string) $numero) !== '' ? mb_substr(trim((string) $numero), 0, 100) : null,
                    'nota' => filled($nota) ? trim((string) $nota) : null,
                ], fn ($v) => $v !== null && $v !== '');

                RegistroInvio::create([
                    'condominio_id' => $assemblea->condominio_id,
                    'anagrafica_id' => $p['id'],
                    'tipo' => self::TIPI[$cosa],
                    'canale' => RegistroInvio::CANALE_STAMPA,
                    'documentabile_type' => Assemblea::class,
                    'documentabile_id' => $assemblea->id,
                    'stato' => RegistroInvio::STATO_SPEDITA,
                    'emesso_da' => $utente?->id,
                    'emesso_il' => $adesso,
                    'dati' => $versione + [
                        'invio' => (string) Str::uuid(),
                        'cosa' => $cosa,
                        'assemblea_id' => (int) $assemblea->id,
                        'persona' => ['id' => $p['id'], 'nome' => $p['nome']],
                        'unita' => $p['unita'],
                        'spedizione' => $spedizione,
                        'eventi' => [self::evento('spedizione', $utente) + $spedizione],
                    ],
                ]);
            }
        });

        if ($cosa === self::CONVOCAZIONE) {
            $this->segnaConvocata($assemblea, $data);
        }

        return ['registrate' => count($chieste), 'assemblea' => ['stato' => $assemblea->stato, 'data_avviso' => $assemblea->data_avviso?->format('Y-m-d')]];
    }

    /**
     * Annulla una spedizione registrata per sbaglio (la persona sbagliata, la data sbagliata): la riga resta nel
     * registro, con chi l'ha annullata e quando, e non conta più. Un'email partita non si annulla: è partita.
     */
    public function annulla(Assemblea $assemblea, string|int $invio, ?User $utente): RegistroInvio
    {
        $riga = $this->rigaDellAssemblea($assemblea, $invio);
        if ($riga->canale !== RegistroInvio::CANALE_STAMPA || $riga->stato !== RegistroInvio::STATO_SPEDITA) {
            throw ValidationException::withMessages(['invio' => 'Si annulla solo una spedizione registrata a mano e non ancora annullata: un\'email partita resta nel registro com\'è.']);
        }
        $dati = (array) $riga->dati;
        $dati['eventi'] = [...(array) ($dati['eventi'] ?? []), self::evento('spedizione_annullata', $utente)];
        $riga->update(['stato' => RegistroInvio::STATO_ANNULLATA, 'dati' => $dati]);

        return $riga;
    }

    /** Una riga del registro di questa assemblea (convocazione o verbale), altrimenti 404. */
    public function rigaDellAssemblea(Assemblea $assemblea, string|int $invio): RegistroInvio
    {
        abort_unless(is_numeric($invio), 404);

        return RegistroInvio::whereKey((int) $invio)->where('condominio_id', $assemblea->condominio_id)->whereIn('tipo', array_values(self::TIPI))
            ->where('documentabile_type', Assemblea::class)->where('documentabile_id', $assemblea->id)->firstOrFail();
    }

    /** Il PDF conservato di una riga per email della convocazione (l'avviso com'è partito per quella persona), o null. */
    public function pdfConservato(RegistroInvio $riga): ?array
    {
        $documento = isset($riga->dati['documento_id']) ? Documento::find((int) $riga->dati['documento_id']) : null;
        $contenuto = $documento === null ? null : $this->archivio->leggi($documento->path);

        return $contenuto === null ? null : ['pdf' => $contenuto, 'nome' => (string) ($riga->dati['allegato'] ?? $documento->name)];
    }

    /** L'assemblea ha comunicazioni uscite (partite, in coda o spedite)? Allora non si elimina: sono la prova della convocazione. */
    public function haInviiUsciti(Assemblea $assemblea): bool
    {
        return RegistroInvio::whereIn('tipo', array_values(self::TIPI))->where('documentabile_type', Assemblea::class)->where('documentabile_id', $assemblea->id)
            ->whereIn('stato', [RegistroInvio::STATO_IN_CODA, RegistroInvio::STATO_INVIATA, RegistroInvio::STATO_SPEDITA])->exists();
    }

    // ── La lettera di trasmissione del verbale ───────────────────────────

    /**
     * La lettera di trasmissione del verbale, da stampare per chi non ha un indirizzo email (o per le persone date):
     * una lettera per persona con il testo del modello dello studio. Il verbale si stampa a parte e si allega.
     *
     * @param  list<int>|null  $persone  senza, chi fra i proposti (`$chi`) non ha un indirizzo email
     *
     * @throws ValidationException se il verbale non è approvato o non c'è nessuno a cui scrivere
     */
    public function pdfDiTrasmissione(Assemblea $assemblea, ?array $persone, string $chi = DestinatariAssemblea::ASSENTI): string
    {
        $this->verbaleDaMandare($assemblea);
        $universo = collect($this->destinatari->persone($assemblea))->keyBy('id');
        if ($persone !== null) {
            $chieste = self::identificativi($persone);
            self::assicuraAventiDiritto($chieste, $universo->keys()->all());
            $scelte = $universo->only($chieste);
        } else {
            $scelte = $universo->filter(fn ($p) => ($chi === DestinatariAssemblea::TUTTI || $p['assente'] === true) && EmissioneRicevute::indirizzi($p['persona']) === []);
        }
        if ($scelte->isEmpty()) {
            throw ValidationException::withMessages(['persone' => 'Nessuna persona da stampare: fra '.($chi === DestinatariAssemblea::TUTTI ? 'i condòmini' : 'gli assenti').' tutti hanno un indirizzo email. Per stampare la lettera di qualcuno, sceglilo dall\'elenco.']);
        }

        $testi = $this->testiDiPartenza($assemblea, self::VERBALE);
        $studio = EmissioneLettere::studio();
        // Il luogo (il comune dello studio, o del condominio) e il giorno di oggi, come le lettere ai condòmini.
        $assemblea->loadMissing('condominio');
        $luogo = EmissioneLettere::luogo($assemblea->condominio);
        $luogoData = ($luogo !== null ? $luogo.', ' : '').SegnapostiVerbale::giorno(DateHelper::adessoUtente());
        $fogli = [];
        foreach ($scelte as $p) {
            $suoi = $this->testiDellaPersona($assemblea, $testi, $p);
            $fogli[] = [
                'destinatario' => ['nome' => $p['nome'], 'indirizzo' => $p['indirizzo']],
                'luogo_data' => $luogoData,
                'oggetto' => $suoi['oggetto'],
                'saluto' => 'Gentile '.$p['nome'].',',
                'parti' => [$suoi['testo']."\n\nCordiali saluti.\n\nL'amministratore".($studio['nome'] !== null ? "\n".$studio['nome'] : '')],
            ];
        }

        return $this->convocazione->stampaLettere($assemblea, $fogli, 'Trasmissione del verbale');
    }

    // ── Lo stato degli invii, per persona ────────────────────────────────

    /**
     * Che cosa è stato mandato a ogni persona, e come: le righe del registro di questa assemblea (dalla più recente),
     * e in breve se le è stata mandata (un'email partita o una spedizione registrata), se ne ha una in coda, se
     * l'ultima non è partita, quando e come. Per il verbale contano le righe della versione di adesso; per la
     * convocazione tutte, e `testo_cambiato` dice se il testo di adesso non è più quello mandato.
     *
     * @return array<int, array<string, mixed>>
     */
    public function stato(Assemblea $assemblea, string $cosa): array
    {
        $righe = RegistroInvio::with('emessoDa:id,name')
            ->where('condominio_id', $assemblea->condominio_id)->where('tipo', self::TIPI[$cosa])
            ->where('documentabile_type', Assemblea::class)->where('documentabile_id', $assemblea->id)
            ->whereNotNull('anagrafica_id')->orderBy('id')->get();
        if ($righe->isEmpty()) {
            return [];
        }
        $versione = $this->versione($assemblea, $cosa);
        $fuso = (string) config('app.user_timezone', 'Europe/Rome');

        $stato = [];
        foreach ($righe->groupBy('anagrafica_id') as $aid => $sue) {
            $voci = $sue->map(function (RegistroInvio $r) use ($versione, $fuso, $cosa) {
                $dati = (array) $r->dati;
                $spedizione = (array) ($dati['spedizione'] ?? []);
                $attuale = $cosa === self::VERBALE
                    ? (int) ($dati['versione'] ?? 0) === (int) $versione['versione']
                    : ($dati['impronta_testo'] ?? null) === $versione['impronta_testo'];

                return [
                    'id' => (int) $r->id,
                    'canale' => $r->canale,
                    'destinatario' => $r->destinatario,
                    'tipo_indirizzo' => $dati['tipo_indirizzo'] ?? null,
                    'stato' => $r->stato,
                    'quando' => $r->emesso_il->copy()->setTimezone($fuso)->format('Y-m-d H:i'),
                    'da' => $r->emessoDa?->name,
                    'errore' => ErroreDiPosta::frase($r->errore),
                    'spedizione' => $spedizione === [] ? null : $spedizione + ['come' => RegistroLettere::CANALI_SPEDIZIONE[$spedizione['canale'] ?? ''] ?? ($spedizione['canale'] ?? '')],
                    'versione' => isset($dati['versione']) ? (int) $dati['versione'] : null,
                    'attuale' => $attuale,
                    'pdf' => isset($dati['documento_id']) && $r->canale === RegistroInvio::CANALE_EMAIL && $cosa === self::CONVOCAZIONE,
                ];
            })->values();

            $contano = $cosa === self::VERBALE ? $voci->where('attuale', true) : $voci;
            $riuscite = $contano->filter(fn ($v) => in_array($v['stato'], [RegistroInvio::STATO_INVIATA, RegistroInvio::STATO_SPEDITA], true));
            $ultimaRiuscita = $riuscite->last();
            $inCoda = $contano->contains(fn ($v) => $v['stato'] === RegistroInvio::STATO_IN_CODA);
            $ultima = $contano->reject(fn ($v) => $v['stato'] === RegistroInvio::STATO_ANNULLATA)->last();

            $stato[(int) $aid] = [
                'mandata' => $ultimaRiuscita !== null,
                'in_coda' => $inCoda,
                'non_partita' => $ultimaRiuscita === null && ! $inCoda && ($ultima['stato'] ?? null) === RegistroInvio::STATO_NON_PARTITA,
                'quando' => $ultimaRiuscita === null ? null : ($ultimaRiuscita['spedizione']['data'] ?? substr($ultimaRiuscita['quando'], 0, 10)),
                'come' => $ultimaRiuscita === null ? null : ($ultimaRiuscita['spedizione']['come'] ?? 'email'),
                'testo_cambiato' => $ultimaRiuscita !== null && ! $ultimaRiuscita['attuale'],
                // Un'email partita o in coda con il testo (o la versione) di adesso: un secondo invio uguale si salta.
                'per_email_adesso' => $voci->contains(fn ($v) => $v['canale'] === RegistroInvio::CANALE_EMAIL && $v['attuale']
                    && in_array($v['stato'], [RegistroInvio::STATO_IN_CODA, RegistroInvio::STATO_INVIATA], true)),
                'invii' => $voci->reverse()->values()->all(),
            ];
        }

        return $stato;
    }

    /** @return array<string, mixed> */
    private static function statoVuoto(): array
    {
        return ['mandata' => false, 'in_coda' => false, 'non_partita' => false, 'quando' => null, 'come' => null, 'testo_cambiato' => false, 'per_email_adesso' => false, 'invii' => []];
    }

    // ── I testi ──────────────────────────────────────────────────────────

    /**
     * L'oggetto e il testo di partenza dell'email: dal modello predefinito dello studio (o da quello di serie), con i
     * dati dell'assemblea già scritti e i segnaposto del destinatario ancora da riempire. La prima riga «Oggetto: …»
     * del modello è l'oggetto.
     *
     * @return array{oggetto: string, testo: string, modello: ?string}
     */
    public function testiDiPartenza(Assemblea $assemblea, string $cosa): array
    {
        $tipo = $cosa === self::VERBALE ? VerbaleModello::TRASMISSIONE_VERBALE : VerbaleModello::EMAIL_CONVOCAZIONE;
        $modello = VerbaleModello::predefinito($tipo);
        [$valori, $elenchi] = $this->segnaposti->valori($assemblea);
        $parti = ModelliDiSerie::oggettoETesto(CompilatoreModello::compila((string) ($modello?->corpo ?? ModelliDiSerie::corpo($tipo)), $valori, $elenchi));

        if ($parti['oggetto'] === '') {
            $parti['oggetto'] = ($cosa === self::VERBALE ? 'Verbale dell\'assemblea' : 'Convocazione dell\'assemblea').' — '.$valori['condominio.nome_completo'];
        }

        return $parti + ['modello' => $modello?->nome];
    }

    /**
     * L'oggetto e il testo scelti nella finestra (o da un agente): quelli della richiesta, o quelli di partenza. Non
     * vuoti e nella loro lunghezza.
     *
     * @param  array<string, mixed>  $input
     * @return array{oggetto: string, testo: string}
     */
    private function testiScelti(Assemblea $assemblea, string $cosa, array $input): array
    {
        $partenza = $this->testiDiPartenza($assemblea, $cosa);
        $oggetto = is_string($input['oggetto'] ?? null) && trim($input['oggetto']) !== '' ? $input['oggetto'] : $partenza['oggetto'];
        $testo = is_string($input['testo'] ?? null) && trim($input['testo']) !== '' ? $input['testo'] : $partenza['testo'];
        $oggetto = trim((string) preg_replace('/\s+/u', ' ', $oggetto));
        $testo = str_replace("\r\n", "\n", trim($testo));

        $errori = [];
        if ($oggetto === '') {
            $errori['oggetto'] = 'Scrivi l\'oggetto dell\'email.';
        } elseif (mb_strlen($oggetto) > self::LUNGHEZZA_OGGETTO) {
            $errori['oggetto'] = 'L\'oggetto è troppo lungo: al massimo '.self::LUNGHEZZA_OGGETTO.' caratteri.';
        }
        if ($testo === '') {
            $errori['testo'] = 'Scrivi il testo dell\'email.';
        } elseif (mb_strlen($testo) > TestiLettere::LUNGHEZZA_MASSIMA) {
            $errori['testo'] = 'Il testo è troppo lungo: al massimo '.TestiLettere::LUNGHEZZA_MASSIMA.' caratteri.';
        }
        if ($errori !== []) {
            throw ValidationException::withMessages($errori);
        }

        return ['oggetto' => $oggetto, 'testo' => $testo];
    }

    /**
     * L'oggetto e il testo di una persona: i segnaposto rimasti (quelli del destinatario, e quelli scritti a mano
     * nella finestra) riempiti con i suoi dati e con quelli dell'assemblea.
     *
     * @param  array{oggetto: string, testo: string}  $testi
     * @param  array{id: int, nome: string, indirizzo: ?string, unita: list<string>, millesimi: string}  $persona
     * @return array{oggetto: string, testo: string, nome: string}
     */
    private function testiDellaPersona(Assemblea $assemblea, array $testi, array $persona): array
    {
        [$valori, $elenchi] = $this->segnaposti->valori($assemblea);
        $valori += SegnapostiConvocazione::destinatario($persona);

        return [
            'oggetto' => trim((string) preg_replace('/\s+/u', ' ', CompilatoreModello::compila($testi['oggetto'], $valori, $elenchi))),
            'testo' => trim(CompilatoreModello::compila($testi['testo'], $valori, $elenchi)),
            'nome' => $persona['nome'],
        ];
    }

    /**
     * Il messaggio com'è: l'oggetto, il saluto, le righe del testo, la chiusura con chi scrive. Lo usano l'anteprima e
     * la notifica, così l'esempio che si vede è il messaggio che parte.
     *
     * @param  array{oggetto?: string, testo?: string, nome?: string}  $dati
     * @return array{oggetto: string, saluto: string, righe: list<string>, chiusura: string}
     */
    public static function messaggio(Assemblea $assemblea, array $dati): array
    {
        $assemblea->loadMissing('condominio');

        return ComunicazioneAssembleaNotification::testo((string) $assemblea->condominio?->nome, (string) ($dati['nome'] ?? ''), $dati);
    }

    // ── Interni ──────────────────────────────────────────────────────────

    /**
     * Si può mandare? Il verbale solo se è approvato e il suo PDF è nell'archivio; la convocazione solo con un testo
     * salvato e la data della prima convocazione, e finché l'assemblea non è cominciata.
     *
     * @return array{0: bool, 1: ?string}
     */
    public function siPuoMandare(Assemblea $assemblea, string $cosa): array
    {
        if ($cosa === self::VERBALE) {
            $verbale = $this->verbali->verbaleDi($assemblea);

            return $verbale->approvato() && $this->archivio->esiste($this->percorsoDelVerbale($verbale))
                ? [true, null] : [false, self::VERBALE_NON_APPROVATO];
        }
        if (in_array($assemblea->stato, ['in_corso', 'chiusa'], true)) {
            return [false, self::ASSEMBLEA_GIA_TENUTA];
        }
        // Lo stato dell'assemblea è un'etichetta che la persona può non aver toccato: un'assemblea con il verbale in attesa di
        // approvazione o approvato si è tenuta, anche se risulta ancora «convocata» (visto in prova il 17/11/2026).
        if (in_array($this->verbali->verbaleDi($assemblea)->stato, ['da_approvare', 'approvato'], true)) {
            return [false, self::ASSEMBLEA_GIA_TENUTA];
        }
        if (blank($assemblea->convocazione_testo)) {
            return [false, self::CONVOCAZIONE_SENZA_TESTO];
        }
        if ($assemblea->data_prima_convocazione === null) {
            return [false, self::CONVOCAZIONE_SENZA_DATA];
        }

        return [true, null];
    }

    /**
     * Dove sta sul disco il PDF approvato del verbale. Si rilegge dal documento, non dalla relazione già caricata: la
     * pagina la carica con le sole colonne che mostra (il nome), senza il percorso.
     */
    private function percorsoDelVerbale(Verbale $verbale): ?string
    {
        return $verbale->documento_id === null ? null : Documento::whereKey($verbale->documento_id)->value('path');
    }

    /** Il verbale approvato con il suo PDF, altrimenti l'errore che lo dice. */
    private function verbaleDaMandare(Assemblea $assemblea): Verbale
    {
        [$puo, $perche] = $this->siPuoMandare($assemblea, self::VERBALE);
        if (! $puo) {
            throw ValidationException::withMessages(['verbale' => $perche]);
        }

        return $this->verbali->verbaleDi($assemblea);
    }

    /**
     * Che cosa distingue un invio da uno successivo: per il verbale la versione, per la convocazione l'impronta del testo.
     *
     * @return array{versione?: int, impronta_testo?: string}
     */
    private function versione(Assemblea $assemblea, string $cosa): array
    {
        return $cosa === self::VERBALE
            ? ['versione' => (int) $this->verbali->verbaleDi($assemblea)->versione]
            : ['impronta_testo' => $this->convocazione->improntaDelTesto($assemblea)];
    }

    /**
     * Le righe di una persona: una per indirizzo, in coda, con l'oggetto e il testo riempiti, il nome dell'allegato e
     * la sua impronta. L'avviso di convocazione della persona si fa adesso e si conserva come documento della prima
     * riga; il verbale è quello dell'archivio. Dentro una transazione: se il PDF non si salva non resta niente, e il
     * file scritto si toglie. Nell'anteprima di un agente il PDF non si fa e non si scrive.
     *
     * @param  array<string, mixed>  $persona
     * @param  list<array{0: string, 1: string}>  $destinazioni  [indirizzo, tipo]
     * @return list<RegistroInvio>
     */
    private function scrivi(Assemblea $assemblea, string $cosa, array $persona, array $destinazioni, array $testi, array $tipi, ?Verbale $verbale,
        ?User $utente, CarbonInterface $adesso, bool $inProva): array
    {
        $suoi = $this->testiDellaPersona($assemblea, $testi, $persona);
        $pdf = null;
        $impronta = null;
        if ($cosa === self::CONVOCAZIONE) {
            $allegato = $this->convocazione->nomeFile($assemblea, 'convocazione', Str::slug($persona['nome']));
            if (! $inProva) {
                $pdf = $this->convocazione->pdf($assemblea, [$persona]);
                $impronta = hash('sha256', $pdf);
            }
        } else {
            $allegato = $this->verbali->nomeFile($assemblea, false);
            $contenuto = $verbale === null ? null : $this->archivio->leggi($this->percorsoDelVerbale($verbale));
            $impronta = $contenuto === null ? null : hash('sha256', $contenuto);
            unset($contenuto);
        }

        $dati = $this->versione($assemblea, $cosa) + [
            'invio' => (string) Str::uuid(),
            'cosa' => $cosa,
            'assemblea_id' => (int) $assemblea->id,
            'persona' => ['id' => (int) $persona['id'], 'nome' => $persona['nome']],
            'unita' => $persona['unita'],
            'oggetto' => $suoi['oggetto'],
            'testo' => $suoi['testo'],
            'nome' => $suoi['nome'],
            'oggetto_modello' => $testi['oggetto'],
            'testo_modello' => $testi['testo'],
            'allegato' => $allegato,
            'tipi_scelti' => $tipi,
        ];
        if ($cosa === self::VERBALE) {
            $dati['documento_id'] = (int) $verbale->documento_id;
        }

        $prima = count($this->scritti);
        try {
            return DB::transaction(function () use ($assemblea, $cosa, $persona, $destinazioni, $utente, $adesso, $pdf, $impronta, $dati, $allegato) {
                $righe = [];
                foreach ($destinazioni as [$indirizzo, $tipo]) {
                    $righe[] = RegistroInvio::create([
                        'condominio_id' => $assemblea->condominio_id,
                        'anagrafica_id' => $persona['id'],
                        'tipo' => self::TIPI[$cosa],
                        'canale' => RegistroInvio::CANALE_EMAIL,
                        'documentabile_type' => Assemblea::class,
                        'documentabile_id' => $assemblea->id,
                        'destinatario' => $indirizzo,
                        'stato' => RegistroInvio::STATO_IN_CODA,
                        'impronta' => $impronta,
                        'emesso_da' => $utente?->id,
                        'emesso_il' => $adesso,
                        'dati' => $dati + ['tipo_indirizzo' => $tipo, 'gettone' => (string) Str::uuid()],
                    ]);
                }
                if ($pdf !== null) {
                    $percorso = $this->archivio->salvaContenuto($pdf, 'pdf', ArchivioDocumenti::CARTELLA.'/'.$assemblea->condominio_id.'/assemblee');
                    $this->scritti[] = $percorso;
                    $documento = $righe[0]->documenti()->create([
                        'name' => $allegato,
                        'description' => 'Avviso di convocazione dell\'assemblea «'.$assemblea->titolo.'» a '.$persona['nome'].', del '
                            .$adesso->copy()->setTimezone((string) config('app.user_timezone', 'Europe/Rome'))->format('d/m/Y'),
                        'path' => $percorso,
                        'mime_type' => 'application/pdf',
                        'file_size' => strlen($pdf),
                        'created_by' => $utente?->id ?? 1,
                        'is_published' => false,
                        'is_approved' => true,
                    ]);
                    foreach ($righe as $r) {
                        $r->update(['dati' => (array) $r->dati + ['documento_id' => $documento->id]]);
                    }
                }

                return $righe;
            });
        } catch (\Throwable $e) {
            foreach (array_slice($this->scritti, $prima) as $percorso) {
                $this->archivio->elimina($percorso);
            }
            array_splice($this->scritti, $prima);

            throw $e;
        } finally {
            unset($pdf);
            gc_collect_cycles();
        }
    }

    /**
     * I messaggi di una persona: uno per riga (un indirizzo), con il gettone che lega la riga al registro delle
     * email. Un errore mentre il messaggio parte (con la coda `sync`) fa la riga «non partita», con l'errore.
     *
     * @param  list<RegistroInvio>  $righe
     * @return list<int>
     */
    private function spedisci(array $righe): array
    {
        $ids = [];
        foreach ($righe as $r) {
            try {
                $notifica = new ComunicazioneAssembleaNotification([$r->id]);
                $notifica->id = (string) $r->dati['gettone'];
                Notification::route('mail', $r->destinatario)->notify($notifica);
            } catch (\Throwable $e) {
                Log::warning('Comunicazione dell\'assemblea non partita: '.$e->getMessage(), ['invio' => $r->id]);
                RegistroInvio::whereKey($r->id)->where('stato', RegistroInvio::STATO_IN_CODA)->update(['stato' => RegistroInvio::STATO_NON_PARTITA, 'errore' => ErroreDiPosta::daEccezione($e)]);
            }
            $ids[] = (int) $r->id;
        }

        return $ids;
    }

    /** @param  list<int>  $ids */
    private function riepilogo(array $ids, int $persone): array
    {
        $scritte = RegistroInvio::whereIn('id', $ids ?: [0])->get(['id', 'stato', 'errore']);
        $conta = fn (string $stato) => $scritte->where('stato', $stato)->count();

        return [
            'persone' => $persone,
            'messaggi' => count($ids),
            'inviate' => $conta(RegistroInvio::STATO_INVIATA),
            'in_coda' => $conta(RegistroInvio::STATO_IN_CODA),
            'non_partite' => $conta(RegistroInvio::STATO_NON_PARTITA),
            'errori' => $scritte->pluck('errore')->filter()->unique()->take(3)->map(fn (string $e) => ErroreDiPosta::frase($e))->unique()->values()->all(),
            'tetto_messaggi' => EmissioneRicevute::tettoMessaggi(),
        ];
    }

    /**
     * La convocazione è partita: la data dell'avviso si riempie se è vuota e l'assemblea in preparazione risulta
     * convocata. Con il verbale approvato l'assemblea non si tocca più.
     */
    private function segnaConvocata(Assemblea $assemblea, string $giorno): void
    {
        $assemblea->loadMissing('verbale');
        if ($assemblea->bloccata()) {
            return;
        }
        $campi = [];
        if ($assemblea->data_avviso === null) {
            $campi['data_avviso'] = $giorno;
        }
        if ($assemblea->stato === 'bozza') {
            $campi['stato'] = 'convocata';
        }
        if ($campi !== []) {
            $assemblea->forceFill($campi)->save();
        }
    }

    /**
     * Le persone chieste devono essere tutte fra gli aventi diritto di questa assemblea: una che non lo è ferma
     * tutta la richiesta, prima di scrivere.
     *
     * @param  list<int>  $chieste
     * @param  list<int>  $universo
     */
    private static function assicuraAventiDiritto(array $chieste, array $universo): void
    {
        if ($chieste === []) {
            throw ValidationException::withMessages(['persone' => 'Scegli almeno una persona.']);
        }
        $estranee = array_values(array_diff($chieste, array_map('intval', $universo)));
        if ($estranee !== []) {
            throw ValidationException::withMessages(['persone' => (count($estranee) === 1 ? 'La persona '.$estranee[0].' non è' : 'Le persone '.implode(', ', $estranee).' non sono')
                .' fra gli aventi diritto di questa assemblea: rileggi l\'anteprima.']);
        }
    }

    /**
     * I tipi di indirizzo scelti, fra quelli che esistono; senza una scelta, quelli proposti (primaria e secondaria).
     *
     * @return list<string>
     */
    private static function tipiScelti(mixed $indirizzi): array
    {
        if ($indirizzi === null) {
            return EmissioneRicevute::TIPI_PROPOSTI;
        }

        return array_values(array_intersect(array_keys(EmissioneRicevute::TIPI_INDIRIZZO), array_map('strval', array_filter((array) $indirizzi, 'is_scalar'))));
    }

    /** @return list<int> */
    private static function identificativi(mixed $valori): array
    {
        return array_values(array_unique(array_map('intval', array_filter((array) $valori, 'is_numeric'))));
    }

    /** @return array{evento: string, il: string, giorno: string, da: ?int, da_nome: ?string} */
    private static function evento(string $evento, ?User $utente): array
    {
        return ['evento' => $evento, 'il' => now()->toIso8601String(), 'giorno' => DateHelper::oggiUtente(), 'da' => $utente?->id, 'da_nome' => $utente?->name];
    }
}
