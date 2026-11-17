<?php

namespace App\Services\Assemblee;

use App\Helpers\DateHelper;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\VerbaleModello;
use App\Services\Gestionale\Invii\ArchivioAPezzi;
use App\Services\Gestionale\Invii\InviiPerPersona;
use App\Services\Gestionale\Ricevute\EmissioneRicevute;
use App\Services\PDF\GruppoCarta;
use App\Services\PDF\PdfService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * L'avviso di convocazione dell'assemblea e il foglio delle firme (Web Pro Italia, 17/11/2026).
 *
 * ## Da dove viene il testo
 *
 * Come il verbale: parte da un **modello dello studio** (`verbale_modelli`, tipo `convocazione`, uno predefinito), il
 * programma riempie i segnaposto con i dati dell'assemblea ({@see genera()}) e il risultato diventa il **testo della
 * convocazione** (`assemblee.convocazione_testo`), libero e modificabile per intero, a mano o da un assistente.
 * Rigenerare sovrascrive, e per questo chiede una conferma esplicita.
 *
 * I segnaposto del destinatario (`{{destinatario.nome}}`…) restano nel testo e si riempiono alla stampa
 * ({@see testoPer()}): una riga da compilare nell'avviso generico, il nome e le unità nella stampa per persona.
 *
 * ## Le stampe
 *
 * - l'avviso generico ({@see pdf()} senza destinatari): da affiggere o da consegnare a chiunque;
 * - l'avviso per persona ({@see pdf()} con i destinatari): un foglio (o più) per ognuno, con il nome e l'indirizzo in
 *   alto a destra, alla distanza delle lettere (va in una busta con la finestra), ognuno con le sue pagine numerate;
 * - l'archivio .zip con un PDF per persona e l'elenco degli indirizzi ({@see archivio()}), con i pezzi delle lettere;
 * - il foglio delle firme ({@see pdfFoglioFirme()}): una riga per avente diritto.
 *
 * La carta è quella dello studio per il gruppo «Rendiconto e assemblea». Nessuna stampa scrive niente: gli invii li
 * registra {@see InviiAssemblea}.
 *
 * ## L'avviso dei cinque giorni
 *
 * L'art. 66 disp. att. c.c. chiede che l'avviso sia comunicato almeno cinque giorni prima della data fissata per la
 * prima convocazione. Il programma confronta le due date (i giorni di calendario fra la data dell'avviso, o oggi se
 * non è scritta, e il giorno della prima convocazione) e, se sono meno di cinque, lo dice ({@see avvisi()}). Non
 * blocca niente, e non decide quando un avviso si considera comunicato: quello lo valuta lo studio.
 */
final class ConvocazioneService
{
    /** I giorni fra l'avviso e la prima convocazione sotto i quali la pagina avvisa (art. 66 disp. att. c.c.). */
    public const GIORNI_DI_PREAVVISO = 5;

    public function __construct(
        private readonly SegnapostiConvocazione $segnaposti,
        private readonly DestinatariAssemblea $destinatari,
        private readonly VerbaleService $verbali,
    ) {}

    // --- Il testo ------------------------------------------------------------------------------------------------

    /** Il testo che il modello darebbe adesso, senza salvarlo. Senza un modello, quello predefinito (o quello di serie). */
    public function compila(Assemblea $assemblea, ?VerbaleModello $modello = null): string
    {
        $modello ??= VerbaleModello::predefinito(VerbaleModello::CONVOCAZIONE);
        [$valori, $elenchi] = $this->segnaposti->valori($assemblea);

        // I segnaposto del destinatario non hanno un valore qui: restano scritti, e li riempie la stampa.
        return CompilatoreModello::compila((string) ($modello?->corpo ?? ModelliDiSerie::CONVOCAZIONE), $valori, $elenchi);
    }

    /**
     * Genera il testo della convocazione dal modello e lo salva. Se c'è già un testo lo sovrascrive solo con
     * `$sovrascrivi` (la pagina chiede la conferma, un agente lo dice nella richiesta).
     */
    public function genera(Assemblea $assemblea, ?VerbaleModello $modello, bool $sovrascrivi): Assemblea
    {
        $this->verbali->assicuraModificabile($assemblea);

        if (filled($assemblea->convocazione_testo) && ! $sovrascrivi) {
            throw ValidationException::withMessages(['sovrascrivi' => 'La convocazione ha già un testo: rigenerarla dal modello lo sostituisce per intero. Conferma che vuoi sovrascriverlo.']);
        }

        $modello ??= VerbaleModello::predefinito(VerbaleModello::CONVOCAZIONE);
        $assemblea->forceFill(['convocazione_testo' => $this->compila($assemblea, $modello), 'convocazione_modello_id' => $modello?->id])->save();

        return $assemblea;
    }

    /** Il testo che si stampa: quello salvato, o (finché non c'è) quello che darebbe il modello predefinito. */
    public function testoDaStampare(Assemblea $assemblea): string
    {
        return filled($assemblea->convocazione_testo) ? (string) $assemblea->convocazione_testo : $this->compila($assemblea);
    }

    /**
     * Il testo per un destinatario (o, senza, quello generico): il testo da stampare con i segnaposto rimasti —
     * quelli del destinatario, e quelli che chi scrive ha aggiunto a mano — riempiti con i dati di adesso.
     *
     * @param  array{nome: string, indirizzo?: ?string, unita?: list<string>, millesimi?: ?string}|null  $destinatario
     */
    public function testoPer(Assemblea $assemblea, ?array $destinatario): string
    {
        [$valori, $elenchi] = $this->segnaposti->valori($assemblea);

        return CompilatoreModello::compila($this->testoDaStampare($assemblea), $valori + SegnapostiConvocazione::destinatario($destinatario), $elenchi);
    }

    /** L'impronta del testo da stampare: il registro degli invii la tiene, per dire se il testo è cambiato dopo un invio. */
    public function improntaDelTesto(Assemblea $assemblea): string
    {
        return hash('sha256', $this->testoDaStampare($assemblea));
    }

    // --- Gli avvisi della pagina ---------------------------------------------------------------------------------

    /**
     * Quello che la pagina della convocazione deve dire prima che l'avviso parta: le date che mancano, i cinque giorni,
     * le unità senza un titolare a cui scrivere, il collegamento che manca, il testo che non c'è.
     *
     * @return list<array{tipo: string, testo: string, codice: string}>
     */
    public function avvisi(Assemblea $assemblea, ?array $teste = null): array
    {
        $avvisi = [];
        $teste ??= $this->destinatari->teste($assemblea);
        $prima = $assemblea->data_prima_convocazione;

        if ($prima === null) {
            $avvisi[] = ['tipo' => 'warning', 'codice' => 'senza_data', 'testo' => 'Manca la data della prima convocazione: scrivila da «Date e ordine del giorno» prima di mandare l\'avviso.'];
        } else {
            $oggi = CarbonImmutable::parse(DateHelper::oggiUtente());
            $giornoAvviso = $assemblea->data_avviso ? CarbonImmutable::parse($assemblea->data_avviso->toDateString()) : $oggi;
            $giornoPrima = CarbonImmutable::parse($prima->toDateString());
            $giorni = (int) $giornoAvviso->diffInDays($giornoPrima, false);

            if ($giorni < self::GIORNI_DI_PREAVVISO) {
                $quando = $assemblea->data_avviso
                    ? 'Fra la data dell\'avviso ('.$giornoAvviso->format('d/m/Y').') e la prima convocazione ('.$giornoPrima->format('d/m/Y').')'
                    : 'Se l\'avviso parte oggi ('.$oggi->format('d/m/Y').'), fino alla prima convocazione ('.$giornoPrima->format('d/m/Y').')';
                $quanti = $giorni < 0 ? 'la prima convocazione è già passata' : ($giorni === 1 ? 'c\'è 1 giorno' : 'ci sono '.$giorni.' giorni');
                $avvisi[] = ['tipo' => 'warning', 'codice' => 'cinque_giorni', 'testo' => $quando.' '.$quanti.': meno dei cinque giorni che l\'art. 66 disp. att. c.c. chiede '
                    .'fra la comunicazione dell\'avviso e la data della prima convocazione. Il programma non blocca l\'invio: sposta la data o valuta con lo studio.'];
            }
        }

        if ($teste['unita_senza_titolari'] !== []) {
            $nomi = implode(', ', array_map(fn ($u) => $u['etichetta'], array_slice($teste['unita_senza_titolari'], 0, 8)));
            $avvisi[] = ['tipo' => 'warning', 'codice' => 'unita_senza_titolari', 'testo' => (count($teste['unita_senza_titolari']) === 1 ? 'Un\'unità non ha' : count($teste['unita_senza_titolari']).' unità non hanno')
                .' un titolare in archivio ('.$nomi.(count($teste['unita_senza_titolari']) > 8 ? '…' : '').'): per loro la convocazione non va a nessuno. Aggiungi i titolari dalle unità.'];
        }
        if ($teste['teste'] === []) {
            $avvisi[] = ['tipo' => 'warning', 'codice' => 'nessun_destinatario', 'testo' => 'Non risulta nessun avente diritto: controlla i titolari delle unità del condominio.'];
        } elseif (! $teste['preparato']) {
            $avvisi[] = ['tipo' => 'info', 'codice' => 'elenco_calcolato', 'testo' => 'L\'elenco delle presenze non è ancora stato preparato: gli aventi diritto sono calcolati adesso dai titolari delle unità, senza salvare niente.'];
        }
        if (in_array($assemblea->modalita, ['videoconferenza', 'mista'], true) && blank($assemblea->collegamento_videoconferenza)) {
            $avvisi[] = ['tipo' => 'info', 'codice' => 'senza_collegamento', 'testo' => 'L\'assemblea è anche in videoconferenza ma manca il collegamento: scrivilo qui sotto, o comunicalo a parte.'];
        }
        if (blank($assemblea->convocazione_testo)) {
            $avvisi[] = ['tipo' => 'info', 'codice' => 'senza_testo', 'testo' => 'La convocazione non ha ancora un testo: generalo dal modello (o scrivilo). Finché non c\'è, le stampe mostrano quello che darebbe il modello predefinito, e l\'avviso non si manda.'];
        }

        return $avvisi;
    }

    // --- Le stampe -----------------------------------------------------------------------------------------------

    /**
     * Il PDF dell'avviso: generico (senza destinatari) oppure un foglio per ognuno dei destinatari dati, con il nome
     * e l'indirizzo in alto. Con `$bozza` (la convocazione non ha ancora un testo salvato) la filigrana lo dice.
     *
     * @param  list<array{nome: string, indirizzo?: ?string, unita?: list<string>, millesimi?: ?string}>|null  $destinatari
     */
    public function pdf(Assemblea $assemblea, ?array $destinatari = null): string
    {
        $assemblea->loadMissing('condominio');
        $fogli = [];
        foreach ($destinatari ?? [null] as $d) {
            $fogli[] = [
                'destinatario' => $d === null ? null : ['nome' => $d['nome'], 'indirizzo' => $d['indirizzo'] ?? null],
                'parti' => self::parti($this->testoPer($assemblea, $d)),
            ];
        }

        return $this->stampaLettere($assemblea, $fogli, 'Avviso di convocazione', blank($assemblea->convocazione_testo));
    }

    /**
     * Le lettere dell'assemblea in un PDF: un foglio per voce, ognuno con le sue pagine. Lo usano l'avviso di
     * convocazione e la lettera di trasmissione del verbale ({@see InviiAssemblea::pdfDiTrasmissione()}).
     *
     * @param  list<array{destinatario: ?array{nome: string, indirizzo: ?string}, parti: list<string>, oggetto?: ?string, saluto?: ?string, chiusura?: ?string}>  $fogli
     */
    public function stampaLettere(Assemblea $assemblea, array $fogli, string $titolo, bool $bozza = false): string
    {
        $assemblea->loadMissing('condominio');
        $config = ['orientation' => 'P', 'margin_top' => 14];
        if ($bozza) {
            $config += ['showWatermarkText' => true, 'watermarkText' => 'BOZZA', 'watermarkTextAlpha' => 0.08];
        }

        $mpdf = app(PdfService::class)->generate('pdf.gestionale.lettera_assemblea', [
            'condominio' => $assemblea->condominio,
            'assemblea' => $assemblea,
            'fogli' => $fogli,
            'titolo' => $titolo,
            'gruppo_carta' => GruppoCarta::RENDICONTO_E_ASSEMBLEA,
        ], $config);
        $pdf = $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
        unset($mpdf);
        gc_collect_cycles();

        return $pdf;
    }

    /**
     * L'archivio .zip: un PDF per persona, con il suo nome, e il file con gli indirizzi email di ognuno (i pezzi
     * delle lettere, {@see ArchivioAPezzi} e {@see InviiPerPersona}). Lo .zip è un file temporaneo: chi lo manda al
     * browser lo cancella dopo.
     *
     * @return array{zip: string, nome: string, persone: int}
     */
    public function archivio(Assemblea $assemblea): array
    {
        if (! class_exists(\ZipArchive::class)) {
            throw ValidationException::withMessages(['zip' => 'Su questo server PHP non ha l\'estensione zip: l\'archivio non si può fare. Stampa l\'avviso per persona in un PDF solo.']);
        }
        $persone = $this->destinatari->persone($assemblea);
        if ($persone === []) {
            throw ValidationException::withMessages(['zip' => 'Non risulta nessun avente diritto a cui scrivere: controlla i titolari delle unità.']);
        }
        $assemblea->loadMissing('condominio');
        $archivio = new ArchivioAPezzi(\RuntimeException::class);

        try {
            $usati = [mb_strtolower(InviiPerPersona::FILE_DEGLI_INDIRIZZI)];
            $elenco = [];
            foreach ($persone as $p) {
                $nome = InviiPerPersona::nomeLibero(EmissioneRicevute::nomeFileDellaPersona($p['nome']), $usati).'.pdf';
                $archivio->aggiungi($nome, $this->pdf($assemblea, [$p]));
                $elenco[] = ['nome' => $p['nome'], 'indirizzi' => EmissioneRicevute::indirizzi($p['persona']), 'documenti' => 1, 'file' => $nome];
            }
            $archivio->aggiungi(InviiPerPersona::FILE_DEGLI_INDIRIZZI, InviiPerPersona::elencoDegliIndirizzi(
                'Avvisi di convocazione', (string) $assemblea->condominio->nome, $elenco, now(),
                fn (int $n) => $n === 1 ? '1 avviso' : $n.' avvisi', EmissioneRicevute::TIPI_INDIRIZZO,
                'Un PDF per persona, da spedire o da consegnare. Quello che spedisci fuori dal programma si registra con «Registra la spedizione».',
            ));

            return [
                'zip' => $archivio->scrivi(),
                'nome' => Str::replaceLast('.pdf', '.zip', $this->nomeFile($assemblea, 'convocazione', 'per-persona')),
                'persone' => count($persone),
            ];
        } catch (\Throwable $e) {
            $archivio->togli();

            throw $e;
        }
    }

    /**
     * Il foglio delle firme, da stampare e portare in assemblea: una riga per avente diritto (nome, unità,
     * millesimi) con lo spazio per la firma di chi è presente, il nome del delegato e la sua firma; in fondo i
     * totali e lo spazio per presidente e segretario. Orizzontale: le firme vogliono spazio.
     */
    public function pdfFoglioFirme(Assemblea $assemblea): string
    {
        $assemblea->loadMissing('condominio', 'tabella');
        $letto = $this->destinatari->teste($assemblea);
        $totale = $letto['totale'];

        $righe = array_map(fn (array $t) => [
            'nome' => $t['nome'],
            'contitolari' => $t['contitolari'],
            'unita' => implode(', ', $t['etichette']),
            'millesimi' => Millesimi::testoSuMille($t['millesimi'], $totale),
            // Se la delega è già segnata nell'elenco delle presenze, il foglio la porta già scritta.
            'delegato' => $t['tipo'] === 'delega' ? (string) $t['delegato'] : '',
        ], $letto['teste']);

        $mpdf = app(PdfService::class)->generate('pdf.gestionale.foglio_firme_assemblea', [
            'condominio' => $assemblea->condominio,
            'assemblea' => $assemblea,
            'righe' => $righe,
            'teste' => count($righe),
            'millesimi_totali' => Millesimi::testoSuMille(array_sum(array_map(fn ($t) => $t['millesimi'], $letto['teste'])), $totale),
            'unita_senza_titolari' => $letto['unita_senza_titolari'],
            'totale_tabella' => $totale,
            'prima' => $assemblea->data_prima_convocazione ? SegnapostiVerbale::giornoEOra($assemblea->data_prima_convocazione) : null,
            'seconda' => $assemblea->data_seconda_convocazione ? SegnapostiVerbale::giornoEOra($assemblea->data_seconda_convocazione) : null,
            'dove' => filled($assemblea->luogo) || $assemblea->modalita !== 'presenza' ? SegnapostiVerbale::dove($assemblea) : null,
            'titolo_stampa' => 'Foglio delle firme',
            'senza_firma' => true,
            'gruppo_carta' => GruppoCarta::RENDICONTO_E_ASSEMBLEA,
        ], ['orientation' => 'L', 'margin_left' => 10, 'margin_right' => 10, 'margin_top' => 32]);

        return $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    }

    public function nomeFile(Assemblea $assemblea, string $che = 'convocazione', ?string $suffisso = null): string
    {
        $assemblea->loadMissing('condominio');

        return PdfService::nomeFile($che.'-assemblea', (string) $assemblea->condominio->nome, $assemblea->giorno()?->format('Y-m-d'), $suffisso);
    }

    /**
     * Il testo in parti: una riga con soli tre (o più) trattini stacca quello che segue. La stampa mette fra le
     * parti una linea tratteggiata: è il modulo di delega, da ritagliare.
     *
     * @return list<string>
     */
    public static function parti(string $testo): array
    {
        $parti = preg_split('/^[ \t]*-{3,}[ \t]*$/mu', str_replace(["\r\n", "\r"], "\n", $testo)) ?: [$testo];

        return array_values(array_filter(array_map('trim', $parti), fn (string $p) => $p !== ''));
    }
}
