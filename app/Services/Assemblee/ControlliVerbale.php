<?php

namespace App\Services\Assemblee;

use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\AssembleaPunto;
use App\Models\Assemblee\Verbale;

/**
 * I controlli prima di mandare il verbale in approvazione (Web Pro Italia, 17/11/2026, dopo la revisione).
 *
 * Chi approva firma un testo: questi controlli servono a non fargli firmare un testo che non corrisponde ai dati
 * dell'assemblea. Si aggiungono a quelli di `VerbaleService::inviaInApprovazione` (testo, presidente, segretario,
 * convocazione tenuta, esito di ogni punto) e rifiutano l'invio, dicendo che cosa manca, se:
 *
 * - mancano l'ora d'inizio o di fine;
 * - nel testo sono rimaste parti da compilare: la riga da riempire che `SegnapostiVerbale` scrive quando un dato manca
 *   («______»), un segnaposto fra graffe che nessuno ha sostituito, la frase «Esito da confermare»;
 * - un punto che si vota ha l'esito «approvato» o «respinto» ma nessun voto registrato;
 * - un punto ha un esito diverso da quello che danno i voti registrati, e chi manda non lo ha confermato in modo
 *   esplicito (`esiti_confermati`: gli id dei punti; serve per esempio per le maggioranze delle leggi speciali, che il
 *   programma non conosce);
 * - **i dati dell'assemblea sono cambiati dopo l'ultimo testo**: l'impronta ({@see impronta()}) salvata quando il
 *   testo è stato generato o salvato a mano non è più quella dei dati di adesso. Si rigenera il testo, oppure lo si
 *   rilegge e lo si salva; chi lo ha già riletto con i dati di adesso procede con `dati_riletti: true`.
 *
 * Tutto si legge dal database al momento del controllo, mai da relazioni caricate prima.
 */
final class ControlliVerbale
{
    /** Quante righe da compilare si elencano al massimo nel rifiuto: le altre si contano. */
    private const RIGHE_ELENCATE = 5;

    public function __construct(
        private readonly SegnapostiVerbale $segnaposti,
        private readonly ConteggiAssemblea $conteggi,
    ) {}

    /**
     * L'impronta dei dati che riempiono i segnaposto del verbale: i valori semplici e quelli di ogni punto, com'è nel
     * database adesso. Due impronte uguali vogliono dire che un testo generato allora uscirebbe uguale oggi.
     *
     * Restano fuori: «oggi» (cambia ogni giorno senza che cambi niente) e la differenza fra un punto informativo senza
     * esito e uno con l'esito «informativa», che l'invio in approvazione scrive da solo.
     *
     * ⚠️ Un segnaposto aggiunto o cambiato in `SegnapostiVerbale` cambia tutte le impronte: i verbali in bozza
     * chiederanno una rilettura al prossimo invio. È il verso prudente.
     */
    public function impronta(Assemblea $assemblea): string
    {
        $fresca = $this->rileggi($assemblea);
        $verbale = Verbale::where('assemblea_id', $fresca->id)->first();

        [$valori, $elenchi] = $this->segnaposti->valori($fresca, [
            'intestazione' => $verbale?->intestazione,
            'apertura' => $verbale?->apertura,
            'chiusura' => $verbale?->chiusura,
        ]);
        unset($valori['oggi']);

        foreach ($fresca->punti->values() as $i => $punto) {
            if ($punto->informativo() && isset($elenchi['punti'][$i])) {
                $elenchi['punti'][$i]['esito.parola'] = 'informativa';
            }
        }

        return hash('sha256', (string) json_encode([$valori, $elenchi], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * I motivi per cui il verbale non può andare in approvazione, uno per chiave (la chiave è il campo da correggere, o
     * quello con cui si conferma). Vuoto se va tutto bene.
     *
     * @param  list<int>  $esitiConfermati  gli id dei punti di cui chi manda conferma l'esito anche se i voti ne danno un altro
     * @return array<string, string>
     */
    public function primaDiInviare(Assemblea $assemblea, Verbale $verbale, bool $datiRiletti = false, array $esitiConfermati = []): array
    {
        $fresca = $this->rileggi($assemblea);
        $errori = [];

        if (blank($fresca->ora_inizio)) {
            $errori['ora_inizio'] = 'Manca l\'ora d\'inizio dell\'assemblea: scrivila nello svolgimento (pagina dell\'assemblea), poi rigenera o correggi il testo.';
        }
        if (blank($fresca->ora_fine)) {
            $errori['ora_fine'] = 'Manca l\'ora di chiusura dell\'assemblea: scrivila nello svolgimento (pagina dell\'assemblea), poi rigenera o correggi il testo.';
        }

        if (filled($verbale->testo)) {
            $rimasti = self::partiDaCompilare((string) $verbale->testo);
            if ($rimasti !== []) {
                $elenco = implode('; ', array_map(fn (array $r) => "riga {$r['riga']} «{$r['testo']}» ({$r['perche']})", array_slice($rimasti, 0, self::RIGHE_ELENCATE)));
                $altre = count($rimasti) - self::RIGHE_ELENCATE;
                $errori['segnaposti'] = 'Nel testo del verbale ci sono ancora parti da compilare: '.$elenco
                    .($altre > 0 ? ($altre === 1 ? '; e un\'altra riga' : "; e altre {$altre} righe") : '')
                    .'. Completa i dati dell\'assemblea e rigenera il testo, oppure correggi il testo a mano.';
            }
        }

        $senzaVoti = [];
        $diversi = [];
        foreach ($fresca->punti as $punto) {
            if ($punto->informativo() || $punto->esito === null) {
                continue;
            }
            $v = $this->conteggi->votazione($fresca, $punto);
            if ($v['esito_senza_voti']) {
                $senzaVoti[] = "il punto {$punto->numero} ha l'esito «{$punto->esito}» ma nessun voto registrato";
            } elseif ($v['esito_diverso'] && ! in_array((int) $punto->id, $esitiConfermati, true)) {
                $diversi[] = "il punto {$punto->numero} (id {$punto->id}) ha l'esito «{$punto->esito}», ma i voti registrati danno «{$v['esito_proposto']}» ({$this->senzaSegni($v['spiegazione'])})";
            }
        }
        if ($senzaVoti !== []) {
            $errori['voti'] = ucfirst(implode('; ', $senzaVoti)).'. Registra i voti (favorevoli, contrari, astenuti) dalla pagina dell\'assemblea, oppure segna il punto come rinviato.';
        }
        if ($diversi !== []) {
            $errori['esiti_confermati'] = ucfirst(implode('; ', $diversi)).'. Correggi l\'esito o i voti dalla pagina dell\'assemblea. Se l\'esito scritto è quello giusto (per esempio per una maggioranza di una legge speciale, che il programma non conosce), la persona lo conferma mandando in «esiti_confermati» gli id di quei punti.';
        }

        if (filled($verbale->testo) && ! $datiRiletti && ! $this->testoAllineato($fresca, $verbale)) {
            $errori['dati_riletti'] = (filled($verbale->impronta_dati)
                ? 'I dati dell\'assemblea (presenze, voti, esiti, ore, testi dei punti…) sono cambiati dopo l\'ultima volta che il testo del verbale è stato generato o salvato: il testo potrebbe non corrispondere più ai dati.'
                : 'Non risulta che il testo del verbale sia stato generato o salvato con i dati di adesso dell\'assemblea (è stato scritto prima che ci fosse questo controllo): potrebbe non corrispondere ai dati.')
                .' Rigenera il testo dal modello, oppure rileggilo, correggilo e salvalo («Salva il testo»), poi mandalo di nuovo. Chi ha già riletto il testo con i dati di adesso procede mandando «dati_riletti: true».';
        }

        return $errori;
    }

    /** Il testo è stato generato o salvato con i dati di adesso? Senza impronta (un testo di prima di questo controllo) non si può dire di sì. */
    public function testoAllineato(Assemblea $assemblea, Verbale $verbale): bool
    {
        return filled($verbale->impronta_dati) && hash_equals((string) $verbale->impronta_dati, $this->impronta($assemblea));
    }

    /**
     * Le righe del testo con qualcosa ancora da compilare.
     *
     * - «______»: la riga da riempire di `SegnapostiVerbale::VUOTO`, esattamente sei trattini bassi. Una riga più lunga
     *   (quella delle firme, ventidue) è una riga su cui si firma, non un dato che manca.
     * - Un segnaposto fra doppie graffe rimasto nel testo: il programma non lo conosce, o è stato scritto a mano.
     * - «Esito da confermare»: la frase che il modello scrive per un punto senza esito.
     *
     * @return list<array{riga:int, testo:string, perche:string}>
     */
    public static function partiDaCompilare(string $testo): array
    {
        $vuoto = preg_quote(SegnapostiVerbale::VUOTO, '/');
        $trovate = [];

        foreach (preg_split('/\r\n|\r|\n/', $testo) ?: [] as $i => $riga) {
            $perche = match (true) {
                preg_match('/\{\{[^{}]*\}\}/u', $riga) === 1 => 'un segnaposto non sostituito',
                preg_match('/(?<!_)'.$vuoto.'(?!_)/u', $riga) === 1 => 'un dato che manca',
                preg_match('/esito da confermare/iu', $riga) === 1 => 'l\'esito del punto non è confermato',
                default => null,
            };
            if ($perche !== null) {
                $trovate[] = ['riga' => $i + 1, 'testo' => self::accorcia(trim($riga)), 'perche' => $perche];
            }
        }

        return $trovate;
    }

    /** L'assemblea riletta dal database, con quello che serve ai conteggi e ai segnaposto. */
    private function rileggi(Assemblea $assemblea): Assemblea
    {
        return Assemblea::with(['condominio', 'tabella', 'presenze.anagrafica', 'presenze.delegato', 'punti.voti'])->findOrFail($assemblea->getKey());
    }

    private static function accorcia(string $riga): string
    {
        return mb_strlen($riga) > 90 ? mb_substr($riga, 0, 87).'…' : $riga;
    }

    /** La spiegazione dei conteggi senza i segni di spunta, che in una frase di rifiuto confondono. */
    private function senzaSegni(string $spiegazione): string
    {
        $frase = rtrim(trim(str_replace([' ✓', ' ✗'], '', $spiegazione)), '.');

        return mb_strtolower(mb_substr($frase, 0, 1)).mb_substr($frase, 1);
    }
}
