<?php

namespace App\Services\Riparto;

use App\Exceptions\Gestionale\VociOltreIlPreventivoException;
use App\Helpers\MoneyHelper;
use App\Models\Esercizio;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoRate;
use App\Services\Gestionale\SpesaPerVoceService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Una voce del preventivo divisa fra più piani rate (Web Pro Italia, 15/11/2026).
 *
 * Uno studio fa due piani sulle stesse voci quando le scadenze sono irregolari — un acconto e un saldo, o due blocchi di rate —
 * e ogni piano prende un pezzo di ogni voce (`piano_rate_capitoli.importo`; vuoto vuol dire «tutta la voce»). Ogni piano però
 * guardava solo sé stesso: il controllo `convalidaCapitoliUnici` (che vietava del tutto due piani sulla stessa voce, e quindi anche
 * la divisione voluta) non lo chiamava nessuno, e la «Selezione rapida» di una voce già coperta da un altro piano le copiava di
 * nuovo l'importo intero. I condòmini pagavano la stessa voce due volte, e niente lo diceva.
 *
 * LA REGOLA. Per ogni voce, quanto le danno tutti i piani validi della stessa gestione e dello stesso esercizio non supera il suo
 * fabbisogno: il preventivo, o lo speso se le fatture registrate lo hanno già superato (è lo «sforo da recuperare» che il modulo di
 * creazione propone a un piano integrativo, e la stessa base della guardia del già versato, `CalcoloQuoteService`).
 *
 * - **Validi**: `attivo` e in bozza o approvati. Un piano non si annulla: si elimina, e allora non c'è più. `attivo = false` è
 *   l'unico modo in cui un piano resta a database senza valere; il motore e la guardia del già versato guardano `attivo`, la
 *   copertura guarda lo stato: qui un piano conta solo se valgono tutte e due. Gli straordinari non hanno voci (hanno fatture).
 * - **Stesso esercizio**: il programma usa la stessa gestione ordinaria su più esercizi (`CondominioService::createDefaultGestione`),
 *   e ogni anno il suo piano chiede di nuovo la voce intera. L'esercizio di un piano è quello con cui è nato o è stato generato
 *   (`CompetenzaDelPiano::esercizioDelPiano`, che lo deduce per i piani vecchi), anche quando lo si ricalcola dalla pagina di un altro
 *   esercizio: dalla 1.12 l'elenco dei piani di un esercizio mostra anche quelli delle gestioni agganciate, e la generazione lascia il
 *   piano nel suo (`GeneratePianoRateAction`). Solo il piano che non ha ancora un esercizio salvato prende quello della pagina.
 * - **Quanto dà un piano a una voce** è quanto il motore le addebita (`CalcoloQuoteService::processaConti`): l'importo del piano o,
 *   vuoto, quello della voce; l'importo scritto su un capitolo si divide fra i suoi sottoconti in proporzione, con il resto
 *   all'ultimo; un piano senza voci prende le voci che nessun altro piano attivo **dello stesso esercizio** ha (Coda 438, decisione
 *   183.2: il piano del 2027 senza voci prende anche quelle del piano del 2026). Si confronta voce per voce, al livello a cui il
 *   motore ripartisce: un capitolo in un piano e un suo sottoconto in un altro sono la stessa spesa.
 * - **Un piano già emesso non ferma gli altri** (le sue rate sono in contabilità, `PianoRate::haRateEmesse`). È il caso dubbio, e si
 *   lascia passare (sopra la 1.12, 15/11/2026): la 1.12 non ricalcola un piano emesso né gli toglie una voce («Annulla prima le
 *   emissioni»), e per cambiarlo se ne fa uno nuovo, che chiede la stessa voce finché il vecchio non si toglie; oppure gli si mette
 *   accanto un piano per una spesa in più sulla stessa voce (le pulizie straordinarie), e il rendiconto mette il deliberato accanto
 *   al preventivo. Il programma non sa quale dei due casi è, né se è invece un saldo scritto per intero accanto a un acconto emesso:
 *   il rifiuto qui non c'è, e la pagina del piano lo dice in rosso (la copertura conta anche i piani emessi).
 * - **«Sposta spesa» non conta**: è uno spostamento voluto dentro lo stesso piano, con il suo motivo nello storico, e il totale del
 *   piano non cambia. Le voci si confrontano con gli importi di prima degli spostamenti.
 * - **La tolleranza è il centesimo, non l'euro.** La scheda della voce (`ContoResource`) e il cruscotto chiamano «oltre» una
 *   copertura solo sopra un euro, ma lì il numero si colora e basta; qui si rifiuta un piano. Gli importi dei piani sono scritti
 *   al centesimo e si sommano esatti, quindi ogni centesimo in più è un centesimo chiesto due volte: con un euro di tolleranza un
 *   «500,50» battuto al posto di «500,00» passerebbe. L'unico arrotondamento è quello del motore quando divide l'importo di un
 *   capitolo fra i sottoconti: si tollera un centesimo per ogni piano che arriva alla voce così, e nessuno per gli altri.
 *
 * QUANDO. Alla creazione del piano e al ricalcolo (anche con le voci che «Sincronizza» aggiunge), per le voci che il piano tiene
 * insieme ad almeno un altro piano valido e non ancora emesso: la somma è il pagamento doppio da fermare. Le voci con un «già versato»
 * che vale nell'esercizio del piano restano alla guardia della 1.12 (`CalcoloQuoteService::guardiaSovraFinanziamentoGiaVersato`), che
 * dice la stessa cosa con le cifre del già versato. Un piano da solo che chiede a una voce più del preventivo non si ferma qui: il
 * programma lo permette da sempre e lo mostra (la riga «Parziale» della pagina del piano: «il piano chiede … più del preventivo»), e un
 * piano tiene gli importi che ha copiato anche se dopo il preventivo cambia (lo «snapshot» di `SyncOrphanChaptersAction`). Ma un
 * secondo piano su quella voce non si aggiunge, perché la somma la supera. La rimozione di una voce non toglie niente a nessuno e non
 * si controlla: è anzi la strada per correggere due piani che si sovrappongono.
 *
 * DOVE SI VEDE. La pagina del piano mostra, sotto ogni voce tenuta anche da un altro piano, gli altri piani (anche quelli emessi, che
 * non fermano), la somma e se torna (`copertura`, letta da `PianoRateResource`).
 */
class VociFraPiuPiani
{
    /** Gli stati in cui un piano tiene le sue voci. */
    public const STATI_VALIDI = ['bozza', 'approvato'];

    /** Quante voci un rifiuto nomina al massimo: le altre si contano. */
    public const ESEMPI = 10;

    /** @var array<int, array{conti: array<int, object>, figli: array<int, list<int>>, radici: list<int>}> per gestione, entro una chiamata */
    private array $alberi = [];

    public function __construct(
        private readonly SpesaPerVoceService $spesaPerVoce,
        private readonly CompetenzaDelPiano $competenza,
    ) {}

    /**
     * Rifiuta il piano se una sua voce, con gli altri piani, supera il fabbisogno.
     *
     * @param  string  $azione  `creazione`, `ricalcolo` o `sincronizzazione`: la frase del rifiuto e la strada che indica
     *
     * @throws VociOltreIlPreventivoException
     */
    public function controlla(PianoRate $piano, ?Esercizio $esercizio, string $azione): void
    {
        $eccedenze = $this->eccedenze($piano, $esercizio);

        if ($eccedenze !== []) {
            throw new VociOltreIlPreventivoException($piano, $eccedenze, $azione, $this->esercizioDi($piano, $esercizio));
        }
    }

    /**
     * Le voci del piano che, con gli altri piani validi della stessa gestione e dello stesso esercizio, superano il fabbisogno. Solo
     * quelle che tiene anche un altro piano non ancora emesso (vedi QUANDO, in testa): un piano emesso non ferma gli altri.
     *
     * @return list<array{voce_id: int, voce: string, preventivo: int, speso: int, tetto: int, questo: int, spostato: int, altri: list<array{id: int, nome: string, importo: int}>, totale: int, oltre: int}>
     */
    public function eccedenze(PianoRate $piano, ?Esercizio $esercizio = null): array
    {
        $quadro = $this->quadro($piano, $esercizio, conIPianiEmessi: false);
        $eccedenze = [];
        $mioEsercizio = $this->esercizioDi($piano, $esercizio)?->id;

        foreach ($quadro['voci'] as $voceId => $v) {
            if ($v['altri'] === []) {
                continue;
            }
            // Una voce con un già versato ha la sua guardia, dalla 1.12 (`CalcoloQuoteService::guardiaSovraFinanziamentoGiaVersato`): dice la
            // stessa cosa con le cifre del già versato e l'esercizio a cui vale. Qui si lascia a lei, per non dare due frasi diverse.
            if (\App\Models\Gestionale\ContributoVersato::ambitoNellEsercizio((int) $voceId, $mioEsercizio) !== null) {
                continue;
            }
            if ($v['oltre'] > 0) {
                $eccedenze[] = $v;
            }
        }

        return $eccedenze;
    }

    /**
     * Per ogni voce del piano (la riga di `piano_rate_capitoli`) tenuta anche da un altro piano valido della stessa gestione e dello
     * stesso esercizio: gli altri piani, la somma, il fabbisogno e se torna. Le voci che tiene solo questo piano non ci sono.
     *
     * @return array<int, array{altri_piani: list<array{id: int, nome: string, stato: string, importo: int}>, questo_piano: int, totale: int, preventivo: int, speso: int, tetto: int, oltre: int, esito: string, frase: string}>
     */
    public function copertura(PianoRate $piano, ?Esercizio $esercizio = null): array
    {
        $quadro = $this->quadro($piano, $esercizio, conIPianiEmessi: true);
        $perRiga = [];

        foreach ($quadro['raggiunte'] as $rigaContoId => $voci) {
            $altri = [];
            $questo = $totale = $preventivo = $speso = $tetto = $oltre = 0;

            foreach (array_unique($voci) as $voceId) {
                $v = $quadro['voci'][$voceId] ?? null;
                if ($v === null) {
                    continue;
                }
                $questo += $v['questo'];
                $totale += $v['totale'];
                $preventivo += $v['preventivo'];
                $speso += $v['speso'];
                $tetto += $v['tetto'];
                $oltre += $v['oltre'];
                foreach ($v['altri'] as $a) {
                    $altri[$a['id']] ??= ['id' => $a['id'], 'nome' => $a['nome'], 'stato' => $a['stato'], 'importo' => 0];
                    $altri[$a['id']]['importo'] += $a['importo'];
                }
            }

            if ($altri === []) {
                continue;
            }

            $altri = array_values($altri);
            $esito = $oltre > 0 ? 'oltre' : ($totale < $tetto ? 'sotto' : 'torna');
            $perRiga[(int) $rigaContoId] = [
                'altri_piani' => $altri,
                'questo_piano' => $questo,
                'totale' => $totale,
                'preventivo' => $preventivo,
                'speso' => $speso,
                'tetto' => $tetto,
                'oltre' => $oltre,
                'esito' => $esito,
                'frase' => $this->fraseDellaCopertura($altri, $totale, $preventivo, $speso, $tetto, $oltre, $esito),
            ];
        }

        return $perRiga;
    }

    /**
     * Il quadro della gestione visto dal piano: per ogni voce che il piano raggiunge, quanto le dà lui, quanto gli altri, il fabbisogno.
     *
     * @param  bool  $conIPianiEmessi  la pagina li conta (la somma è quella che i condòmini pagano), il rifiuto no (vedi la regola, in testa)
     * @return array{voci: array<int, array<string, mixed>>, raggiunte: array<int, list<int>>}
     */
    private function quadro(PianoRate $piano, ?Esercizio $esercizio, bool $conIPianiEmessi): array
    {
        $vuoto = ['voci' => [], 'raggiunte' => []];
        if ($piano->tipo === 'straordinario' || $piano->gestione_id === null) {
            return $vuoto;
        }
        $pianoContoId = DB::table('piani_conti')->where('gestione_id', $piano->gestione_id)->value('id');
        if ($pianoContoId === null) {
            return $vuoto;
        }
        $albero = $this->albero((int) $piano->gestione_id, (int) $pianoContoId);
        $mioEsercizio = $this->esercizioDi($piano, $esercizio)?->id;

        // I piani della gestione che tengono voci: attivi, in bozza o approvati, non straordinari. Con il piano stesso, anche se lo
        // stato è un altro (un piano appena creato è sempre in bozza; qui conta che le sue voci siano quelle a database).
        $piani = PianoRate::query()
            ->where('gestione_id', $piano->gestione_id)
            ->where(fn ($q) => $q->where('id', $piano->id)->orWhere(fn ($v) => $v->where('attivo', true)->whereIn('stato', self::STATI_VALIDI)))
            ->where(fn ($q) => $q->whereNull('tipo')->orWhere('tipo', '!=', 'straordinario'))
            ->orderBy('id')
            ->get();

        $righe = DB::table('piano_rate_capitoli')->whereIn('piano_rate_id', $piani->pluck('id')->all() ?: [0])
            ->get(['piano_rate_id', 'conto_id', 'importo'])->groupBy('piano_rate_id');
        $spostato = $this->spostatoConSpostaSpesa($piani->pluck('id')->all());

        $mappe = [];
        $mappeDivise = [];
        $raggiunteDelPiano = [];
        $attiviDelMioEsercizio = null;
        foreach ($piani as $p) {
            $questo = (int) $p->id === (int) $piano->id;
            if (! $questo && $this->esercizioDi($p, null)?->id !== $mioEsercizio) {
                continue;
            }
            if (! $questo && ! $conIPianiEmessi && $p->haRateEmesse()) {
                continue;
            }
            // Il piano senza voci scelte prende quelle che nessun altro piano attivo del suo esercizio ha: è la regola del motore
            // (`CalcoloQuoteService::calcolaPerGestione`, `$contiImpegnatiIds`, Coda 438 decisione 183.2), qui ripetuta così com'è. Gli altri
            // piani attivi in qualunque stato, anche emessi: il motore li conta tutti, e una voce che uno di loro ha non arriva a questo.
            $impegnate = [];
            if (($righe->get($p->id) ?? collect())->isEmpty()) {
                $attiviDelMioEsercizio ??= $this->attiviDellEsercizio($piano, $mioEsercizio);
                $impegnate = DB::table('piano_rate_capitoli')
                    ->whereIn('piano_rate_id', array_values(array_diff($attiviDelMioEsercizio, [(int) $p->id])) ?: [0])
                    ->pluck('conto_id')->map(fn ($id) => (int) $id)->flip()->all();
            }
            $raggiunte = [];
            $divise = [];
            $mappe[(int) $p->id] = $this->assegnazioni($p, $righe->get($p->id) ?? collect(), $albero, $impegnate, $spostato[(int) $p->id] ?? [], $raggiunte, $divise);
            $mappeDivise[(int) $p->id] = $divise;
            if ((int) $p->id === (int) $piano->id) {
                $raggiunteDelPiano = $raggiunte;
            }
        }

        $mie = $mappe[(int) $piano->id] ?? [];
        if ($mie === []) {
            return ['voci' => [], 'raggiunte' => $raggiunteDelPiano];
        }

        $esercizioPerLoSpeso = $this->esercizioDi($piano, $esercizio);
        $speso = $esercizioPerLoSpeso !== null ? $this->spesaPerVoce->perEsercizio($esercizioPerLoSpeso, array_keys($albero['conti'])) : [];
        $nomi = $piani->keyBy('id');

        $voci = [];
        foreach ($mie as $voceId => $questo) {
            // Una voce a cui questo piano non chiede niente non è sua da difendere: se gli altri la superano, lo dice il loro ricalcolo.
            // Una voce di spesa scritta con il meno ha il suo fermo (`SpeseNegativeNelPiano`): un piano nuovo non la deve tenere affatto,
            // e quel rifiuto dice di più di questo.
            $c = $albero['conti'][$voceId];
            if ($questo === 0 || Conto::eSpesaNegativa((string) $c->tipo, (int) $c->importo)) {
                continue;
            }
            $altri = [];
            $totale = $questo;
            // Un centesimo per ogni piano che arriva alla voce dividendo l'importo di un capitolo: è l'arrotondamento del motore.
            $tolleranza = isset($mappeDivise[(int) $piano->id][$voceId]) ? 1 : 0;
            foreach ($mappe as $pianoId => $mappa) {
                if ($pianoId === (int) $piano->id || ($mappa[$voceId] ?? 0) === 0) {
                    continue;
                }
                $p = $nomi[$pianoId];
                $altri[] = ['id' => $pianoId, 'nome' => (string) $p->nome, 'stato' => $p->stato instanceof \UnitEnum ? $p->stato->value : (string) $p->stato, 'importo' => $mappa[$voceId]];
                $totale += $mappa[$voceId];
                $tolleranza += isset($mappeDivise[$pianoId][$voceId]) ? 1 : 0;
            }
            $preventivo = $this->preventivoDellaVoce($voceId, $albero);
            $spesoVoce = $this->spesoDellaVoce($voceId, $albero, $speso);
            $tetto = max($preventivo, $spesoVoce);
            $voci[$voceId] = [
                'voce_id' => $voceId,
                'voce' => $this->nomeDellaVoce($voceId, $albero),
                'preventivo' => $preventivo,
                'speso' => $spesoVoce,
                'tetto' => $tetto,
                'questo' => $questo,
                'spostato' => (int) ($spostato[(int) $piano->id][$voceId] ?? 0),
                'altri' => $altri,
                'totale' => $totale,
                'oltre' => $totale - $tetto > $tolleranza ? $totale - $tetto : 0,
            ];
        }

        return ['voci' => $voci, 'raggiunte' => $raggiunteDelPiano];
    }

    /**
     * Quanto il piano dà a ogni voce, come lo addebiterebbe il motore (`CalcoloQuoteService::processaConti`): stesso ordine delle
     * righe, stessa divisione di un capitolo fra i sottoconti, stesse righe saltate.
     *
     * @param  array<int, int>  $spostato  quanto ogni riga ha ricevuto (+) o ceduto (−) con «Sposta spesa»
     * @param  array<int, list<int>>  $raggiunte  per ogni riga del piano, le voci a cui arriva
     * @return array<int, int> voce => centesimi
     */
    private function assegnazioni(PianoRate $piano, Collection $righe, array $albero, array $impegnate, array $spostato, array &$raggiunte, array &$divise): array
    {
        $mappa = [];
        $fatte = [];
        $at = $piano->created_at;

        // Gli importi che il piano scrive sulle sue righe, senza gli spostamenti di «Sposta spesa». Vuoto = la voce intera.
        $importi = [];
        foreach ($righe as $r) {
            $importi[(int) $r->conto_id] = $r->importo === null ? null : (int) $r->importo - (int) ($spostato[(int) $r->conto_id] ?? 0);
        }

        if ($righe->isEmpty()) {
            foreach ($albero['radici'] as $radiceId) {
                if (! isset($impegnate[$radiceId]) && ! isset($fatte[$radiceId])) {
                    $this->intera($radiceId, $albero, $importi, $impegnate, $at, $mappa, $fatte, $raggiunte, $radiceId, $divise);
                }
            }

            return $mappa;
        }

        foreach ($righe->sortBy('conto_id') as $r) {
            $id = (int) $r->conto_id;
            if (! isset($albero['conti'][$id]) || isset($fatte[$id])) {
                continue;
            }
            $raggiunte[$id] ??= [];
            if ($importi[$id] === null) {
                $this->intera($id, $albero, $importi, [], $at, $mappa, $fatte, $raggiunte, $id, $divise);
            } else {
                $this->parte($id, $importi[$id], $albero, $at, $mappa, $fatte, $raggiunte, $id, $divise);
            }
        }

        return $mappa;
    }

    /** La voce con il suo importo (la riga vuota del piano, o il piano senza voci): il suo importo, poi i sottoconti. */
    private function intera(int $id, array $albero, array $importi, array $impegnate, $at, array &$mappa, array &$fatte, array &$raggiunte, int $riga, array &$divise): void
    {
        $fatte[$id] = true;
        $conto = $albero['conti'][$id];
        if ((int) $conto->importo !== 0) {
            $mappa[$id] = ($mappa[$id] ?? 0) + abs((int) $conto->importo);
            $raggiunte[$riga][] = $id;
        }
        foreach ($albero['figli'][$id] ?? [] as $figlio) {
            if (isset($impegnate[$figlio]) || isset($fatte[$figlio])) {
                continue;
            }
            // Un sottoconto con una riga sua nel piano, raggiunto dal capitolo: il motore usa l'importo della sua riga.
            if (array_key_exists($figlio, $importi) && $importi[$figlio] !== null) {
                $this->parte($figlio, $importi[$figlio], $albero, $at, $mappa, $fatte, $raggiunte, $riga, $divise);
            } else {
                $this->intera($figlio, $albero, $importi, $impegnate, $at, $mappa, $fatte, $raggiunte, $riga, $divise);
            }
        }
    }

    /** La voce con l'importo scritto dal piano: alla voce se ha le tabelle o non ha sottoconti, altrimenti diviso fra i sottoconti. */
    private function parte(int $id, int $importo, array $albero, $at, array &$mappa, array &$fatte, array &$raggiunte, int $riga, array &$divise, bool $daUnCapitolo = false): void
    {
        $fatte[$id] = true;
        $conto = $albero['conti'][$id];
        $figli = $albero['figli'][$id] ?? [];

        if ($conto->tabelle > 0 || $figli === []) {
            $mappa[$id] = ($mappa[$id] ?? 0) + abs($importo);
            $raggiunte[$riga][] = $id;
            if ($daUnCapitolo) {
                $divise[$id] = true;
            }

            return;
        }

        // I sottoconti che esistevano quando il piano è nato; se nessuno, e l'importo c'è, tutti quelli di adesso.
        $nati = $at === null ? $figli : array_values(array_filter($figli, fn ($f) => $albero['conti'][$f]->created_at === null || $albero['conti'][$f]->created_at <= $at->toDateTimeString()));
        if ($nati === [] && $importo > 0) {
            $nati = $figli;
        }
        if ($nati === []) {
            return;
        }

        $totaleFigli = (int) array_sum(array_map(fn ($f) => (int) $albero['conti'][$f]->importo, $nati));
        $quanti = count($nati);
        $assegnato = 0;
        foreach ($nati as $i => $f) {
            if ($i === $quanti - 1) {
                $quota = $importo - $assegnato;
            } elseif ($totaleFigli > 0) {
                $quota = (int) round($importo * ((int) $albero['conti'][$f]->importo / $totaleFigli));
            } else {
                $quota = (int) round($importo / $quanti);
            }
            $assegnato += $quota;
            if (! isset($fatte[$f])) {
                $this->parte($f, $quota, $albero, $at, $mappa, $fatte, $raggiunte, $riga, $divise, true);
            }
        }
    }

    /**
     * Le voci del piano dei conti della gestione: importo, tipo, se hanno tabelle, la data di nascita e i sottoconti.
     *
     * @return array{conti: array<int, object>, figli: array<int, list<int>>, radici: list<int>}
     */
    private function albero(int $gestioneId, int $pianoContoId): array
    {
        if (isset($this->alberi[$gestioneId])) {
            return $this->alberi[$gestioneId];
        }

        $conti = DB::table('conti')->where('piano_conto_id', $pianoContoId)->orderBy('id')
            ->select(['id', 'parent_id', 'nome', 'tipo', 'importo', 'created_at'])
            ->selectSub(DB::table('conto_tabella_millesimale')->whereColumn('conto_tabella_millesimale.conto_id', 'conti.id')->selectRaw('count(*)'), 'tabelle')
            ->get()->keyBy('id')->all();
        $figli = [];
        $radici = [];
        foreach ($conti as $id => $c) {
            if ($c->parent_id === null) {
                $radici[] = (int) $id;
            } else {
                $figli[(int) $c->parent_id][] = (int) $id;
            }
        }

        return $this->alberi[$gestioneId] = ['conti' => $conti, 'figli' => $figli, 'radici' => $radici];
    }

    /** Il preventivo della voce in valore assoluto, come lo legge il motore; un capitolo a zero vale la somma dei suoi sottoconti. */
    private function preventivoDellaVoce(int $id, array $albero): int
    {
        $proprio = abs((int) $albero['conti'][$id]->importo);
        if ($proprio !== 0 || empty($albero['figli'][$id])) {
            return $proprio;
        }

        return (int) array_sum(array_map(fn ($f) => $this->preventivoDellaVoce($f, $albero), $albero['figli'][$id]));
    }

    /** Lo speso della voce nell'esercizio, con quello dei suoi sottoconti (come il modulo di creazione, `getSpesoRealeRicorsivo`). */
    private function spesoDellaVoce(int $id, array $albero, array $speso): int
    {
        $totale = (int) ($speso[$id] ?? 0);
        foreach ($albero['figli'][$id] ?? [] as $f) {
            $totale += $this->spesoDellaVoce($f, $albero, $speso);
        }

        return max(0, $totale);
    }

    /** «Manutenzione > Ascensore» per un sottoconto, il nome per una voce di primo livello: come la lista del modulo di creazione. */
    private function nomeDellaVoce(int $id, array $albero): string
    {
        $c = $albero['conti'][$id];
        $padre = $c->parent_id !== null ? ($albero['conti'][(int) $c->parent_id] ?? null) : null;

        return $padre !== null ? $padre->nome.' > '.$c->nome : (string) $c->nome;
    }

    /**
     * Quanto ogni riga di ogni piano ha ricevuto (+) o ceduto (−) con «Sposta spesa», storni compresi (uno storno è uno spostamento
     * al contrario, `BudgetMovementService::reverseMovement`).
     *
     * @param  list<int>  $pianiIds
     * @return array<int, array<int, int>> piano => voce => centesimi
     */
    private function spostatoConSpostaSpesa(array $pianiIds): array
    {
        if ($pianiIds === []) {
            return [];
        }
        $netto = [];
        foreach (DB::table('budget_movements')->whereIn('piano_rate_id', $pianiIds)->get(['piano_rate_id', 'source_conto_id', 'destination_conto_id', 'amount']) as $m) {
            $p = (int) $m->piano_rate_id;
            $netto[$p][(int) $m->destination_conto_id] = ($netto[$p][(int) $m->destination_conto_id] ?? 0) + (int) $m->amount;
            $netto[$p][(int) $m->source_conto_id] = ($netto[$p][(int) $m->source_conto_id] ?? 0) - (int) $m->amount;
        }

        return $netto;
    }

    /**
     * L'esercizio del piano: quello che ha salvato; se non ne ha uno, quello della pagina da cui si crea o si ricalcola, altrimenti quello
     * dedotto. Un piano del 2026 ricalcolato dall'elenco del 2027 resta del 2026, come nella generazione (`GeneratePianoRateAction`, Coda 438):
     * con l'esercizio della pagina si confronterebbe con i piani del 2027, che chiedono di nuovo la voce intera.
     */
    private function esercizioDi(PianoRate $piano, ?Esercizio $esercizio): ?Esercizio
    {
        if ($piano->esercizio_id !== null && ($suo = Esercizio::find($piano->esercizio_id)) !== null) {
            return $suo;
        }

        return $esercizio ?? $this->competenza->esercizioDelPiano($piano);
    }

    /**
     * I piani attivi della gestione del piano che sono del suo esercizio, in qualunque stato e anche straordinari: quelli che per il motore
     * impegnano le voci di un piano senza voci scelte (`CalcoloQuoteService::altriPianiAttiviDelloStessoEsercizio`). Con il piano stesso.
     *
     * @return list<int>
     */
    private function attiviDellEsercizio(PianoRate $piano, ?int $esercizioId): array
    {
        return PianoRate::where('gestione_id', $piano->gestione_id)->where('attivo', true)->orderBy('id')->get()
            ->filter(fn (PianoRate $p) => (int) $p->id === (int) $piano->id || $this->esercizioDi($p, null)?->id === $esercizioId)
            ->map(fn (PianoRate $p) => (int) $p->id)->values()->all();
    }

    /** La riga sotto la voce, nella pagina del piano. */
    private function fraseDellaCopertura(array $altri, int $totale, int $preventivo, int $speso, int $tetto, int $oltre, string $esito): string
    {
        $f = fn (int $c) => MoneyHelper::format($c);
        $elenco = implode(', ', array_map(fn ($a) => '«'.$a['nome'].'» ('.$f($a['importo']).')', $altri));
        $base = $tetto > $preventivo
            ? sprintf('un fabbisogno di %s (preventivo %s, fatture registrate %s)', $f($tetto), $f($preventivo), $f($speso))
            : 'un preventivo di '.$f($preventivo);

        return sprintf('Anche %s %s. In tutto %s su %s: %s', count($altri) === 1 ? 'nel piano' : 'nei piani', $elenco, $f($totale), $base, match ($esito) {
            'oltre' => sprintf('%s oltre, cioè chiesti due volte ai condòmini.', $f($oltre)),
            'sotto' => sprintf('%s non sono in nessun piano.', $f($tetto - $totale)),
            default => 'la somma torna.',
        });
    }
}
