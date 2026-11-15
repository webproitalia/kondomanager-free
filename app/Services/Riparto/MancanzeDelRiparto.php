<?php

namespace App\Services\Riparto;

use App\Enums\RuoloAnagraficaImmobile;
use App\Helpers\MoneyHelper;
use App\Models\Gestionale\PianoRate;
use Illuminate\Support\Facades\DB;

/**
 * Che cosa manca perché il motore ripartisca una voce su qualcuno, detto con i nomi (Web Pro Italia, 15/11/2026).
 *
 * La generazione si ferma, giustamente, quando una voce non ha una tabella, una tabella non ha millesimi o un'unità non ha un
 * titolare per il ruolo richiesto: il motore registra ogni caso fra gli scoperti (`CalcoloQuoteService::getScoperti()`, più le righe
 * senza millesimo). Ma quando nessuna quota si poteva calcolare il messaggio diceva le tre cause possibili, sempre le stesse, senza
 * dire quale: lo studio doveva cercarla a mano fra tabelle e unità, e un agente non sapeva da dove cominciare.
 *
 * Qui gli scoperti diventano un elenco per causa: che cosa manca, dove (la voce, la tabella, l'unità per nome, mai per id), e che
 * cosa fare. Il messaggio nomina al più dieci casi per causa e dice quanti sono in tutto; `errori()` li dà tutti, uno per riga, con la
 * chiave `quote.{causa}`, così chi lavora per un agente li corregge uno alla volta.
 *
 * Lo usano due rifiuti: la generazione senza nessuna quota (`NessunaQuotaCalcolataException`) e il cancello degli scoperti visto da un
 * agente (`ScopertiNonAccettatiException::fraseDegliScoperti`), che prima gli arrivava senza una parola.
 */
class MancanzeDelRiparto
{
    /** Quanti casi per causa nomina il messaggio: gli altri si contano. */
    public const ESEMPI = 10;

    /**
     * Le cause, nell'ordine in cui si correggono: prima la voce, poi la tabella, poi le unità. Per ognuna il titolo (singolare e
     * plurale) e il rimedio.
     */
    private const CAUSE = [
        'piano_senza_voci' => ['il piano non ha voci, perché tutte le voci della gestione sono già in un altro piano attivo', 'il piano non ha voci, perché tutte le voci della gestione sono già in altri piani attivi', 'scegli le voci del piano, ciascuna con la sua parte, oppure togli le voci dall\'altro piano'],
        'voci_senza_importo' => ['voce senza importo', 'voci senza importo', 'scrivi il preventivo della voce nel piano dei conti, o un importo nel piano'],
        'voci_senza_tabella' => ['voce senza tabella millesimale', 'voci senza tabella millesimale', 'collega una tabella alla voce dalla sua scheda, nel piano dei conti'],
        'coefficienti_sotto_il_cento' => ['voce le cui tabelle non coprono tutta la spesa', 'voci le cui tabelle non coprono tutta la spesa', 'nella scheda della voce le percentuali delle tabelle collegate devono fare 100'],
        'tabelle_senza_millesimi' => ['tabella senza millesimi', 'tabelle senza millesimi', 'inserisci i millesimi delle unità nella tabella (Tabelle millesimali)'],
        'millesimi_non_compilati' => ['unità senza millesimo in una tabella', 'unità senza millesimo in una tabella', 'compila il millesimo dell\'unità nella tabella'],
        'ripartizioni_sotto_il_cento' => ['voce con le quote per ruolo sotto il 100%', 'voci con le quote per ruolo sotto il 100%', 'nella scheda della voce le percentuali di proprietario, inquilino e usufruttuario devono fare 100'],
        'unita_senza_titolare' => ['unità senza un titolare per il ruolo richiesto', 'unità senza un titolare per il ruolo richiesto', 'aggiungi un titolare attivo all\'unità dalla sua scheda (Anagrafiche), con il ruolo che la voce chiede'],
        'titolari_fuori_periodo' => ['unità senza titolare in una parte del periodo', 'unità senza titolare in una parte del periodo', 'controlla le date di inizio e di fine dei titolari dell\'unità: devono coprire il periodo del piano'],
        'altro' => ['quota senza destinatario', 'quote senza destinatario', 'controlla la voce, la sua tabella e i titolari delle unità'],
    ];

    /**
     * Le cause degli scoperti, con i nomi. Con `$piano`, se gli scoperti non spiegano niente, anche le voci del piano senza importo.
     *
     * @param  list<array<string, mixed>>  $scoperti  gli scoperti del motore (anche già arricchiti con i nomi), con le righe senza millesimo
     * @return list<array{causa: string, titolo: string, rimedio: string, totale: int, elementi: list<string>}>
     */
    public function perGliScoperti(array $scoperti, ?PianoRate $piano = null): array
    {
        $immobili = $this->nomi('immobili', array_column($scoperti, 'immobile_id'));
        $conti = $this->nomi('conti', array_column($scoperti, 'conto_id'));
        $tabelle = $this->nomi('tabelle', array_column($scoperti, 'tabella_id'));
        $nomeUnita = fn ($id) => $immobili[(int) $id] ?? 'un\'unità';
        $nomeVoce = fn ($id) => '«'.($conti[(int) $id] ?? 'una voce').'»';
        $nomeTabella = fn ($id) => '«'.($tabelle[(int) $id] ?? 'una tabella').'»';

        // Per causa, per chiave: ogni caso una volta sola, con le voci in cui torna (un'unità senza titolare torna in ogni voce).
        $casi = [];
        $voci = [];
        foreach ($scoperti as $s) {
            $motivo = $s['motivo'] ?? null;
            $immobileId = isset($s['immobile_id']) ? (int) $s['immobile_id'] : null;
            $contoId = isset($s['conto_id']) ? (int) $s['conto_id'] : null;
            $tabellaId = isset($s['tabella_id']) ? (int) $s['tabella_id'] : null;
            $ruolo = $s['ruolo_richiesto'] ?? null;

            [$causa, $chiave, $testo] = match (true) {
                $motivo === 'conto_senza_tabella' => ['voci_senza_tabella', "v{$contoId}", $nomeVoce($contoId).(($s['importo'] ?? 0) > 0 ? ', '.MoneyHelper::format((int) $s['importo']) : '')],
                $motivo === 'tabella_senza_millesimi' => ['tabelle_senza_millesimi', "t{$tabellaId}", $nomeTabella($tabellaId).' '.$this->statoDellaTabella($tabellaId)],
                $motivo === 'millesimo_non_compilato' => ['millesimi_non_compilati', "u{$immobileId}t{$tabellaId}", $nomeUnita($immobileId).' nella tabella '.$nomeTabella($tabellaId)],
                $motivo === 'coefficienti_sotto_il_cento' => ['coefficienti_sotto_il_cento', "v{$contoId}", $nomeVoce($contoId)],
                $motivo === 'ripartizioni_sotto_il_cento' => ['ripartizioni_sotto_il_cento', "v{$contoId}t{$tabellaId}", $nomeVoce($contoId).' sulla tabella '.$nomeTabella($tabellaId)],
                in_array($motivo, ['titolari_fuori_competenza', 'giorni_senza_titolare'], true) => ['titolari_fuori_periodo', "u{$immobileId}r{$ruolo}",
                    $nomeUnita($immobileId).' ('.$this->ruolo($ruolo).(isset($s['giorni']) && $s['giorni'] ? ', '.$s['giorni'].' giorni senza titolare' : '').')'],
                $motivo === null && $immobileId !== null => ['unita_senza_titolare', "u{$immobileId}r{$ruolo}", $nomeUnita($immobileId).' ('.$this->ruolo($ruolo).')'],
                default => ['altro', md5(json_encode($s)), $contoId !== null ? $nomeVoce($contoId) : 'una quota'],
            };

            $casi[$causa][$chiave] ??= $testo;
            if ($contoId !== null && in_array($causa, ['unita_senza_titolare', 'titolari_fuori_periodo', 'millesimi_non_compilati', 'tabelle_senza_millesimi'], true)) {
                $voci[$causa][$chiave][$contoId] = $nomeVoce($contoId);
            }
        }

        // La stessa mancanza in più voci si dice una volta, con le voci in cui torna.
        foreach ($voci as $causa => $perChiave) {
            foreach ($perChiave as $chiave => $nomiVoci) {
                $casi[$causa][$chiave] .= (count($nomiVoci) === 1 ? ', voce ' : ', voci ').implode(', ', array_values($nomiVoci));
            }
        }

        if ($casi === [] && $piano !== null && $piano->tipo !== 'straordinario') {
            [$senzaImporto, $pianiCheLeTengono] = $this->vociDelPianoSenzaImporto($piano);
            foreach ($senzaImporto as $id => $nome) {
                $casi['voci_senza_importo']["v{$id}"] = '«'.$nome.'»';
            }
            foreach ($pianiCheLeTengono as $id => $nome) {
                $casi['piano_senza_voci']["p{$id}"] = '«'.$nome.'»';
            }
        }

        $cause = [];
        foreach (self::CAUSE as $causa => [$uno, $tanti, $rimedio]) {
            if (empty($casi[$causa])) {
                continue;
            }
            $elementi = array_values($casi[$causa]);
            $cause[] = [
                'causa' => $causa,
                // Il piano senza voci non è un conteggio: gli elementi sono i piani che tengono le voci.
                'titolo' => $causa === 'piano_senza_voci' ? (count($elementi) === 1 ? $uno : $tanti) : count($elementi).' '.(count($elementi) === 1 ? $uno : $tanti),
                'rimedio' => $rimedio,
                'totale' => count($elementi),
                'elementi' => $elementi,
            ];
        }

        return $cause;
    }

    /**
     * «1 voce senza tabella millesimale: «Pulizie», € 400,00. Collega una tabella … 2 unità senza un titolare …: Interno 1 (proprietario),
     * voce «Pulizie»; …»: una frase per causa, con al più dieci casi e quanti sono gli altri, e il suo rimedio. Senza parentesi annidate:
     * i casi stanno dopo i due punti, separati dal punto e virgola. Vuota se non ce ne sono.
     *
     * @param  list<array{titolo: string, rimedio: string, totale: int, elementi: list<string>}>  $cause
     */
    public static function frase(array $cause): string
    {
        return implode(' ', array_map(function (array $c) {
            $mostrati = array_slice($c['elementi'], 0, self::ESEMPI);
            $altri = $c['totale'] - count($mostrati);

            return sprintf('%s: %s%s. %s.', self::maiuscola($c['titolo']), implode('; ', $mostrati),
                $altri > 0 ? sprintf('; e %s', $altri === 1 ? 'un altro caso' : "altri {$altri} casi") : '', self::maiuscola($c['rimedio']));
        }, $cause));
    }

    private static function maiuscola(string $testo): string
    {
        return mb_strtoupper(mb_substr($testo, 0, 1)).mb_substr($testo, 1);
    }

    /**
     * Ogni caso su una riga, con la chiave `quote.{causa}`: per gli errori della risposta.
     *
     * @param  list<array{causa: string, rimedio: string, elementi: list<string>}>  $cause
     * @return array<string, list<string>>
     */
    public static function errori(array $cause): array
    {
        $errori = [];
        foreach ($cause as $c) {
            $errori['quote.'.$c['causa']] = array_map(fn (string $e) => $e.': '.$c['rimedio'].'.', $c['elementi']);
        }

        return $errori;
    }

    /** «(nessuna unità)», «(3 unità, millesimi a zero)», «(3 unità, nessun millesimo compilato)». */
    private function statoDellaTabella(?int $tabellaId): string
    {
        if ($tabellaId === null) {
            return '';
        }
        $q = DB::table('quote_tabella')->where('tabella_id', $tabellaId);
        $unita = (clone $q)->count();
        if ($unita === 0) {
            return '(nessuna unità)';
        }
        $compilate = (clone $q)->whereNotNull('valore')->count();

        return $compilate === 0
            ? sprintf('(%d unità, nessun millesimo compilato)', $unita)
            : sprintf('(%d unità, millesimi che sommano a zero)', $unita);
    }

    private function ruolo(?string $ruolo): string
    {
        return $ruolo === null ? 'non indicato' : mb_strtolower(RuoloAnagraficaImmobile::tryFrom($ruolo)?->label() ?? $ruolo);
    }

    /**
     * Le voci a cui il piano non chiede niente, quando nessuno scoperto spiega il piano vuoto: la riga vuota su una voce a zero (con i
     * sottoconti a zero) o l'importo zero. Per il piano senza voci scelte, le voci che il motore gli darebbe — quelle che nessun altro
     * piano attivo della gestione tiene, di qualunque esercizio (`CalcoloQuoteService::calcolaPerGestione`) — e, se non ne resta
     * nessuna, i piani che le tengono.
     *
     * @return array{0: array<int, string>, 1: array<int, string>} le voci senza importo (id => nome) e i piani che tengono le voci (id => nome)
     */
    private function vociDelPianoSenzaImporto(PianoRate $piano): array
    {
        $importoIntero = function (int $id) use (&$importoIntero): int {
            $totale = abs((int) DB::table('conti')->where('id', $id)->value('importo'));
            foreach (DB::table('conti')->where('parent_id', $id)->pluck('id') as $figlio) {
                $totale += $importoIntero((int) $figlio);
            }

            return $totale;
        };

        $righe = DB::table('piano_rate_capitoli')->join('conti', 'conti.id', '=', 'piano_rate_capitoli.conto_id')
            ->where('piano_rate_capitoli.piano_rate_id', $piano->id)
            ->get(['conti.id', 'conti.nome', 'piano_rate_capitoli.importo as nel_piano']);

        if ($righe->isNotEmpty()) {
            $senza = [];
            foreach ($righe as $r) {
                if (($r->nel_piano !== null ? abs((int) $r->nel_piano) : $importoIntero((int) $r->id)) === 0) {
                    $senza[(int) $r->id] = (string) $r->nome;
                }
            }

            return [$senza, []];
        }

        $pianoContoId = DB::table('piani_conti')->where('gestione_id', $piano->gestione_id)->value('id');
        if ($pianoContoId === null) {
            return [[], []];
        }
        $altrui = DB::table('piano_rate_capitoli')->join('piani_rate', 'piani_rate.id', '=', 'piano_rate_capitoli.piano_rate_id')
            ->where('piani_rate.gestione_id', $piano->gestione_id)->where('piani_rate.attivo', true)->where('piani_rate.id', '!=', $piano->id)
            ->get(['piano_rate_capitoli.conto_id', 'piani_rate.id as piano_id', 'piani_rate.nome as piano_nome']);
        $libere = DB::table('conti')->where('piano_conto_id', $pianoContoId)->whereNull('parent_id')
            ->whereNotIn('id', $altrui->pluck('conto_id')->all() ?: [0])->orderBy('id')->get(['id', 'nome']);

        if ($libere->isEmpty()) {
            return [[], $altrui->sortBy('piano_id')->pluck('piano_nome', 'piano_id')->map(fn ($n) => (string) $n)->all()];
        }

        return [$libere->filter(fn ($c) => $importoIntero((int) $c->id) === 0)->pluck('nome', 'id')->map(fn ($n) => (string) $n)->all(), []];
    }

    /** @return array<int, string> */
    private function nomi(string $tabella, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map(fn ($id) => $id === null ? null : (int) $id, $ids))));

        return $ids === [] ? [] : DB::table($tabella)->whereIn('id', $ids)->pluck('nome', 'id')->mapWithKeys(fn ($n, $id) => [(int) $id => (string) $n])->all();
    }
}
