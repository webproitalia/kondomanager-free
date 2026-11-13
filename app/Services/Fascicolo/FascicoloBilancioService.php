<?php

namespace App\Services\Fascicolo;

use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\FascicoloBilancio;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Services\Documenti\ArchivioDocumenti;
use App\Services\Gestionale\RegistroContabilitaService;
use App\Services\Gestionale\SpesaPerVoceService;
use App\Services\Gestionale\StatoPatrimonialePaginaService;
use App\Settings\AspettoSettings;
use App\Settings\PrintSettings;
use App\Support\Tema;
use Illuminate\Support\Facades\DB;

/**
 * Il FASCICOLO DI BILANCIO (Web Pro Italia, 13/11/2026): un documento solo, da mandare ai condòmini con la convocazione
 * dell'assemblea, che mette insieme ciò che l'art. 1130-bis c.c. chiede al rendiconto — registro di contabilità,
 * riepilogo finanziario, nota sintetica esplicativa — con il riparto, la situazione di ogni condòmino, le fatture da
 * pagare e il preventivo dell'anno dopo, dietro una copertina con il nome dello studio.
 *
 * ⚠️ **Qui non si calcola niente di nuovo.** Ogni numero viene dallo stesso servizio che alimenta la sua pagina e la sua
 * stampa singola (`StatoPatrimonialePaginaService`, `RegistroContabilitaService`, `SpesaPerVoceService`, le quote dei
 * piani rate): il fascicolo e le stampe singole non possono dire due cose diverse, perché leggono la stessa fonte.
 * L'unica cosa che il fascicolo aggiunge è il testo dello studio (la nota) e l'impaginazione.
 *
 * Tutti gli importi di questo servizio sono in **centesimi interi**.
 */
class FascicoloBilancioService
{
    /** Le sezioni, nell'ordine in cui compaiono. */
    public const SEZIONI = [
        'nota' => 'Nota sintetica esplicativa',
        'spese' => 'Rendiconto delle spese',
        'condomini' => 'Situazione dei condòmini',
        'patrimoniale' => 'Rendiconto di cassa e stato patrimoniale',
        'fatture' => 'Fatture da pagare',
        'registro' => 'Registro di contabilità',
        'preventivo' => 'Bilancio preventivo',
        'rate' => 'Rate per condòmino',
    ];

    public const TIPI = ['consuntivo', 'preventivo'];

    private const DI_SERIE = [
        'consuntivo' => ['nota', 'spese', 'condomini', 'patrimoniale', 'fatture', 'registro', 'preventivo', 'rate'],
        'preventivo' => ['nota', 'preventivo', 'rate'],
    ];

    public function __construct(
        private readonly StatoPatrimonialePaginaService $patrimoniale,
        private readonly RegistroContabilitaService $registro,
        private readonly SpesaPerVoceService $spesaPerVoce,
    ) {}

    /**
     * Tutto ciò che serve a stampare il fascicolo di un esercizio.
     *
     * @param  array{tipo?: ?string, sezioni?: ?array, bozza?: bool}  $opzioni  scelte di questa stampa, che vincono su quelle salvate
     * @return array<string, mixed>
     */
    public function costruisci(Condominio $condominio, Esercizio $esercizio, ?FascicoloBilancio $fascicolo = null, array $opzioni = []): array
    {
        $tipo = $this->tipo($esercizio, $opzioni['tipo'] ?? $fascicolo?->tipo);

        // Il preventivo di un fascicolo consuntivo è quello dell'esercizio DOPO (se c'è); in un fascicolo preventivo è il suo.
        $esercizioPreventivo = $tipo === 'preventivo' ? $esercizio : $this->esercizioSuccessivo($esercizio);

        $richieste = $opzioni['sezioni'] ?? $fascicolo?->sezioni ?? self::DI_SERIE[$tipo];
        $richieste = array_values(array_intersect(array_keys(self::SEZIONI), (array) $richieste));

        // Lo stato patrimoniale serve alle sezioni del consuntivo (e ai numeri sotto la nota): in un fascicolo solo
        // preventivo non c'è niente da leggere, e si risparmia la lettura di tutti i registri.
        $pagina = array_intersect($richieste, ['spese', 'patrimoniale', 'fatture']) || ($tipo === 'consuntivo' && in_array('nota', $richieste, true))
            ? $this->patrimoniale->costruisci($condominio, $esercizio)
            : null;

        $dati = [];
        foreach ($richieste as $chiave) {
            $dati[$chiave] = match ($chiave) {
                'nota' => ['testo' => trim((string) $fascicolo?->nota)],
                'spese' => $this->spese($esercizio, $pagina['debiti']['fatture'] ?? []),
                'condomini' => $this->condomini($esercizio),
                'patrimoniale' => $this->soloPatrimoniale($pagina),
                'fatture' => $this->fatture($pagina),
                'registro' => $this->righeRegistro($esercizio, $pagina),
                'preventivo' => $esercizioPreventivo ? $this->preventivo($esercizioPreventivo) : null,
                'rate' => $esercizioPreventivo ? $this->rate($esercizioPreventivo) : null,
            };
        }

        // Una sezione senza niente da dire non si stampa: un titolo sopra una tabella vuota fa pensare a un errore.
        $sezioni = [];
        foreach ($richieste as $chiave) {
            if ($chiave !== 'nota' && ! $this->haContenuto($chiave, $dati[$chiave])) {
                continue;
            }
            $sezioni[] = ['chiave' => $chiave, 'titolo' => $this->titoloSezione($chiave, $esercizio, $esercizioPreventivo)];
        }

        $anno = self::etichettaAnno($esercizio);
        $annoPreventivo = $esercizioPreventivo ? self::etichettaAnno($esercizioPreventivo) : null;
        $haConsuntivo = $tipo === 'consuntivo';
        $haPreventivo = in_array('preventivo', array_column($sezioni, 'chiave'), true);

        $titoli = [];
        if ($haConsuntivo) {
            $titoli[] = 'Bilancio consuntivo '.$anno;
        }
        if ($haPreventivo) {
            $titoli[] = 'Bilancio preventivo '.$annoPreventivo;
        }

        $stampe = app(PrintSettings::class);
        $foto = $stampe->fascicolo_copertina_path
            ? app(ArchivioDocumenti::class)->percorsoLocalePubblico($stampe->fascicolo_copertina_path)
            : null;

        return [
            'tipo' => $tipo,
            'bozza' => (bool) ($opzioni['bozza'] ?? false),
            'condominio' => [
                'nome' => (string) $condominio->nome,
                'indirizzo' => self::indirizzo($condominio),
                'codice_fiscale' => $condominio->codice_fiscale ?: null,
            ],
            'esercizio' => [
                'nome' => (string) $esercizio->nome,
                'dal' => $esercizio->data_inizio?->format('d/m/Y'),
                'al' => $esercizio->data_fine?->format('d/m/Y'),
                'anno' => $anno,
                'stato' => (string) $esercizio->stato,
            ],
            'esercizio_preventivo' => $esercizioPreventivo ? [
                'nome' => (string) $esercizioPreventivo->nome,
                'dal' => $esercizioPreventivo->data_inizio?->format('d/m/Y'),
                'al' => $esercizioPreventivo->data_fine?->format('d/m/Y'),
                'anno' => $annoPreventivo,
            ] : null,
            'copertina' => [
                'sopratitolo' => trim((string) $stampe->fascicolo_sopratitolo) ?: null,
                'titoli' => trim((string) $fascicolo?->titolo) !== '' ? [trim((string) $fascicolo->titolo)] : $titoli,
                'sottotitolo' => trim((string) $fascicolo?->sottotitolo) ?: $this->sottotitoloDiSerie(array_column($sezioni, 'chiave')),
                'studio' => trim((string) $stampe->fascicolo_studio) ?: null,
                'foto' => $foto,
                // Quanto è alta la foto a tutta larghezza (210 mm): la copertina la appoggia sulla fascia in fondo.
                'foto_altezza_mm' => self::altezzaFoto($foto),
            ],
            'colori' => self::colori(),
            'sezioni' => $sezioni,
            'dati' => $dati,
            'numeri' => $tipo === 'preventivo' ? $this->numeriDelPreventivo($dati) : ($pagina ? $this->numeri($pagina, $dati) : []),
            'piede' => trim($condominio->nome.' – Bilancio '.($haConsuntivo ? $anno : $annoPreventivo)),
            'stampato_il' => now()->format('d/m/Y'),
        ];
    }

    /** L'altezza in millimetri della foto di copertina stampata larga quanto il foglio; al massimo 150 (il resto si taglia). */
    private static function altezzaFoto(?string $percorso): ?float
    {
        if (! $percorso || ! is_file($percorso)) {
            return null;
        }

        $misure = @getimagesize($percorso);
        if (! $misure || $misure[0] <= 0) {
            return null;
        }

        return round(min(150, max(60, 210 * $misure[1] / $misure[0])), 1);
    }

    /** Il tipo di fascicolo: quello chiesto, altrimenti consuntivo se l'esercizio ha già dei movimenti (o è chiuso). */
    public function tipo(Esercizio $esercizio, ?string $chiesto = null): string
    {
        if (in_array($chiesto, self::TIPI, true)) {
            return $chiesto;
        }

        $haMovimenti = DB::table('scritture_contabili')->where('esercizio_id', $esercizio->id)->whereNull('deleted_at')->exists();

        return $haMovimenti || $esercizio->stato === 'chiuso' ? 'consuntivo' : 'preventivo';
    }

    /** Le sezioni di serie di un tipo: servono alla pagina per proporre le caselle già spuntate. */
    public static function sezioniDiSerie(string $tipo): array
    {
        return self::DI_SERIE[$tipo] ?? self::DI_SERIE['consuntivo'];
    }

    /** «2025» per un esercizio solare, «2025/2026» per uno a cavallo di due anni. */
    public static function etichettaAnno(Esercizio $esercizio): string
    {
        $da = $esercizio->data_inizio?->format('Y');
        $a = $esercizio->data_fine?->format('Y');

        return $da === $a || $a === null ? (string) $da : $da.'/'.$a;
    }

    private function esercizioSuccessivo(Esercizio $esercizio): ?Esercizio
    {
        if ($esercizio->data_fine === null) {
            return null;
        }

        return Esercizio::where('condominio_id', $esercizio->condominio_id)
            ->whereDate('data_inizio', '>', $esercizio->data_fine)
            ->orderBy('data_inizio')
            ->first();
    }

    private static function indirizzo(Condominio $condominio): string
    {
        $citta = trim(implode(' ', array_filter([$condominio->cap, $condominio->comune])));
        if ($condominio->provincia) {
            $citta .= ' ('.$condominio->provincia.')';
        }

        return trim(implode(' – ', array_filter([trim((string) $condominio->indirizzo), trim($citta)])));
    }

    private function titoloSezione(string $chiave, Esercizio $esercizio, ?Esercizio $preventivo): string
    {
        $anno = self::etichettaAnno($esercizio);

        return match ($chiave) {
            'spese' => 'Rendiconto delle spese '.$anno,
            'preventivo' => 'Bilancio preventivo '.($preventivo ? self::etichettaAnno($preventivo) : ''),
            'rate' => 'Rate '.($preventivo ? self::etichettaAnno($preventivo) : '').' per condòmino',
            default => self::SEZIONI[$chiave],
        };
    }

    private function sottotitoloDiSerie(array $chiavi): string
    {
        $parti = [];
        if (in_array('spese', $chiavi, true)) {
            $parti[] = 'rendiconto';
        }
        if (in_array('patrimoniale', $chiavi, true)) {
            $parti[] = 'stato patrimoniale';
        }
        if (in_array('registro', $chiavi, true)) {
            $parti[] = 'registro di contabilità';
        }
        if (! $parti && in_array('rate', $chiavi, true)) {
            $parti[] = 'spese previste e rate';
        }

        $testo = implode(', ', $parti);
        if (count($parti) > 1) {
            $testo = implode(', ', array_slice($parti, 0, -1)).' e '.end($parti);
        }

        return ucfirst($testo);
    }

    private function haContenuto(string $chiave, mixed $dati): bool
    {
        return match ($chiave) {
            'spese' => ! empty($dati['gestioni']),
            'condomini' => ! empty($dati['righe']),
            'patrimoniale' => ! empty($dati['casse']) || ! empty($dati['attivo']['gruppi']) || ! empty($dati['passivo']['gruppi']),
            'fatture' => ! empty($dati['righe']),
            'registro' => ! empty($dati['righe']),
            'preventivo' => ! empty($dati['gestioni']),
            'rate' => ! empty($dati['piani']),
            default => true,
        };
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────────────────────────
    // Rendiconto delle spese: per ogni voce il preventivo, lo speso e la parte di speso ancora da pagare.
    // ─────────────────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * @param  array<int, array{id:int, residuo:int, nota_credito:bool}>  $fattureAperte  le fatture ancora da pagare (dallo stato patrimoniale)
     */
    private function spese(Esercizio $esercizio, array $fattureAperte): array
    {
        $speso = $this->spesaPerVoce->perEsercizio($esercizio);
        $daPagare = $this->daPagarePerVoce($fattureAperte);

        $gestioni = [];
        $totale = ['preventivo' => 0, 'speso' => 0, 'da_pagare' => 0];

        foreach ($esercizio->gestioni()->orderBy('gestioni.id')->get() as $gestione) {
            $piano = PianoConto::where('gestione_id', $gestione->id)->first();
            if (! $piano) {
                continue;
            }

            $gruppi = [];
            $entrate = [];
            $somma = ['preventivo' => 0, 'speso' => 0, 'da_pagare' => 0];

            foreach ($this->capitoli($piano) as $capitolo) {
                $voci = [];
                foreach ($capitolo['voci'] as $voce) {
                    $segno = Conto::segnoDelTipo((string) $voce->tipo);
                    $riga = [
                        'nome' => (string) $voce->nome,
                        'preventivo' => abs((int) ($voce->importo ?? 0)),
                        // Lo speso è dare − avere: positivo per una spesa, negativo per un'entrata.
                        'speso' => $segno > 0 ? (int) ($speso[$voce->id] ?? 0) : -(int) ($speso[$voce->id] ?? 0),
                        'da_pagare' => (int) ($daPagare[$voce->id] ?? 0),
                    ];
                    if ($riga['preventivo'] === 0 && $riga['speso'] === 0 && $riga['da_pagare'] === 0) {
                        continue;
                    }
                    if ($segno > 0) {
                        $voci[] = $riga;
                        foreach (['preventivo', 'speso', 'da_pagare'] as $k) {
                            $somma[$k] += $riga[$k];
                        }
                    } else {
                        $entrate[] = $riga;
                    }
                }
                if ($voci) {
                    $gruppi[] = ['capitolo' => $capitolo['nome'], 'voci' => $voci];
                }
            }

            if (! $gruppi && ! $entrate) {
                continue;
            }

            $gestioni[] = [
                'nome' => (string) $gestione->nome,
                'tipo' => (string) $gestione->tipo,
                'gruppi' => $gruppi,
                'entrate' => $entrate,
                'totale' => $somma,
            ];
            foreach (['preventivo', 'speso', 'da_pagare'] as $k) {
                $totale[$k] += $somma[$k];
            }
        }

        return ['gestioni' => $gestioni, 'totale' => $totale];
    }

    /**
     * Le voci di un piano dei conti raccolte per capitolo. Una voce di primo livello senza sottovoci è un capitolo di sé stessa
     * (nome del capitolo vuoto): la tabella la stampa come riga semplice.
     *
     * @return list<array{nome: ?string, voci: list<Conto>}>
     */
    private function capitoli(PianoConto $piano): array
    {
        $radici = Conto::with('sottoconti')->where('piano_conto_id', $piano->id)->whereNull('parent_id')->orderBy('id')->get();
        $fuori = [];

        foreach ($radici as $conto) {
            if ($conto->sottoconti->isEmpty()) {
                $fuori[] = ['nome' => null, 'voci' => [$conto]];
            } else {
                $fuori[] = ['nome' => (string) $conto->nome, 'voci' => $conto->sottoconti->sortBy('id')->values()->all()];
            }
        }

        return $fuori;
    }

    /**
     * Quanto dello speso di ogni voce è ancora da pagare: il residuo di ogni fattura aperta, diviso fra le sue righe in
     * proporzione al loro importo. L'ultima riga prende il resto, così la somma delle voci è il residuo al centesimo.
     *
     * @return array<int, int>  voce di spesa => centesimi
     */
    private function daPagarePerVoce(array $fattureAperte): array
    {
        $residui = [];
        foreach ($fattureAperte as $f) {
            if (! empty($f['nota_credito']) || (int) $f['residuo'] <= 0) {
                continue;
            }
            $residui[(int) $f['id']] = (int) $f['residuo'];
        }
        if (! $residui) {
            return [];
        }

        $righe = DB::table('righe_fattura')
            ->whereIn('fattura_passiva_id', array_keys($residui))
            ->whereNotNull('conto_id')
            ->groupBy('fattura_passiva_id', 'conto_id')
            ->select('fattura_passiva_id', 'conto_id', DB::raw('SUM(COALESCE(importo_imponibile, 0) + COALESCE(importo_iva, 0)) as importo'))
            ->orderBy('fattura_passiva_id')->orderBy('conto_id')
            ->get()
            ->groupBy('fattura_passiva_id');

        $perVoce = [];
        foreach ($righe as $fatturaId => $sue) {
            $totale = (int) $sue->sum('importo');
            $residuo = $residui[(int) $fatturaId];
            if ($totale <= 0) {
                continue;
            }
            $assegnato = 0;
            foreach ($sue->values() as $i => $riga) {
                $parte = $i === $sue->count() - 1 ? $residuo - $assegnato : (int) round($residuo * (int) $riga->importo / $totale);
                $assegnato += $parte;
                $perVoce[(int) $riga->conto_id] = ($perVoce[(int) $riga->conto_id] ?? 0) + $parte;
            }
        }

        return $perVoce;
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────────────────────────
    // Situazione dei condòmini: quanto ognuno doveva (per gestione), quanto ha versato, quanto resta.
    // ─────────────────────────────────────────────────────────────────────────────────────────────────────────────

    private function condomini(Esercizio $esercizio): array
    {
        // I piani in bozza non sono ancora una richiesta ai condòmini: qui entrano solo quelli approvati.
        $quote = DB::table('rate_quote as q')
            ->join('rate as r', 'r.id', '=', 'q.rata_id')
            ->join('piani_rate as p', 'p.id', '=', 'r.piano_rate_id')
            ->join('gestioni as g', 'g.id', '=', 'p.gestione_id')
            ->leftJoin('immobili as i', 'i.id', '=', 'q.immobile_id')
            ->leftJoin('anagrafiche as a', 'a.id', '=', 'q.anagrafica_id')
            ->whereIn('p.id', $this->pianiDi($esercizio)->pluck('id'))
            ->where('p.stato', '!=', 'bozza')
            ->groupBy('q.immobile_id', 'q.anagrafica_id', 'g.id', 'g.nome', 'i.interno', 'i.nome', 'a.nome')
            ->select('q.immobile_id', 'q.anagrafica_id', 'g.id as gestione_id', 'g.nome as gestione', 'i.interno', 'i.nome as unita', 'a.nome as persona',
                DB::raw('SUM(q.importo) as dovuto'), DB::raw('SUM(COALESCE(q.importo_pagato, 0)) as versato'))
            ->get();

        if ($quote->isEmpty()) {
            return ['gestioni' => [], 'righe' => [], 'totale' => null];
        }

        $gestioni = $quote->sortBy('gestione_id')->pluck('gestione', 'gestione_id')->all();
        $righe = [];

        foreach ($quote as $q) {
            $chiave = $q->immobile_id.'|'.$q->anagrafica_id;
            $righe[$chiave] ??= [
                'unita' => trim((string) ($q->interno ?: $q->unita)),
                'persona' => (string) $q->persona,
                'per_gestione' => array_fill_keys(array_keys($gestioni), 0),
                'dovuto' => 0,
                'versato' => 0,
            ];
            $righe[$chiave]['per_gestione'][$q->gestione_id] += (int) $q->dovuto;
            $righe[$chiave]['dovuto'] += (int) $q->dovuto;
            $righe[$chiave]['versato'] += (int) $q->versato;
        }

        $righe = array_values($righe);
        usort($righe, fn ($a, $b) => strnatcasecmp($a['unita'], $b['unita']) ?: strcasecmp($a['persona'], $b['persona']));

        $totale = ['per_gestione' => array_fill_keys(array_keys($gestioni), 0), 'dovuto' => 0, 'versato' => 0, 'saldo' => 0];
        foreach ($righe as &$r) {
            $r['saldo'] = $r['dovuto'] - $r['versato'];
            foreach ($r['per_gestione'] as $g => $v) {
                $totale['per_gestione'][$g] += $v;
            }
            $totale['dovuto'] += $r['dovuto'];
            $totale['versato'] += $r['versato'];
            $totale['saldo'] += $r['saldo'];
        }
        unset($r);

        return ['gestioni' => $gestioni, 'righe' => $righe, 'totale' => $totale, 'al' => now()->format('d/m/Y')];
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────────────────────────
    // Rendiconto di cassa, stato patrimoniale, fatture da pagare, registro: dai servizi dei registri, così come sono.
    // ─────────────────────────────────────────────────────────────────────────────────────────────────────────────

    private function soloPatrimoniale(?array $pagina): array
    {
        if (! $pagina) {
            return [];
        }

        return [
            'al' => self::dataIt($pagina['data'] ?? null),
            'casse' => $pagina['riepilogo']['righe'] ?? [],
            'casse_totale' => $pagina['riepilogo']['totale'] ?? null,
            'attivo' => $pagina['situazione']['attivo'] ?? ['gruppi' => [], 'totale' => 0],
            'passivo' => $pagina['situazione']['passivo'] ?? ['gruppi' => [], 'totale' => 0],
            'costi' => (int) ($pagina['situazione']['costi'] ?? 0),
            'ricavi' => (int) ($pagina['situazione']['ricavi'] ?? 0),
            'quadra' => (bool) ($pagina['situazione']['quadra'] ?? true),
        ];
    }

    private function fatture(?array $pagina): array
    {
        $righe = [];
        foreach ($pagina['debiti']['fatture'] ?? [] as $f) {
            $righe[] = [
                'data' => self::dataIt($f['data'] ?? null),
                'fornitore' => (string) ($f['fornitore'] ?? ''),
                'numero' => (string) ($f['numero'] ?? ''),
                'scadenza' => self::dataIt($f['scadenza'] ?? null),
                'residuo' => (int) ($f['residuo'] ?? 0) * (! empty($f['nota_credito']) ? -1 : 1),
            ];
        }

        return [
            'al' => self::dataIt($pagina['data'] ?? null),
            'righe' => $righe,
            'totale' => (int) ($pagina['debiti']['totale_residui'] ?? array_sum(array_column($righe, 'residuo'))),
            'erario' => (int) ($pagina['debiti']['saldo_erario'] ?? 0),
        ];
    }

    private function righeRegistro(Esercizio $esercizio, ?array $pagina): array
    {
        $righe = [];
        $entrate = 0;
        $uscite = 0;

        foreach ($this->registro->registro($esercizio) as $r) {
            $righe[] = [
                'numero' => $r['numero'] ?? null,
                'data' => self::dataIt($r['data'] ?? null, corta: true),
                'descrizione' => trim((string) ($r['descrizione'] ?? '')),
                'controparte' => trim((string) ($r['controparte'] ?? '')),
                'entrata' => $r['entrata'] !== null ? (int) $r['entrata'] : null,
                'uscita' => $r['uscita'] !== null ? (int) $r['uscita'] : null,
                'saldo' => (int) ($r['saldo_progressivo'] ?? 0),
                'stornata' => ! empty($r['stornata']),
            ];
            $entrate += (int) ($r['entrata'] ?? 0);
            $uscite += (int) ($r['uscita'] ?? 0);
        }

        $finale = $righe ? end($righe)['saldo'] : 0;

        return [
            'righe' => $righe,
            'iniziale' => $finale - $entrate + $uscite,
            'entrate' => $entrate,
            'uscite' => $uscite,
            'finale' => $finale,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────────────────────────
    // Preventivo e rate dell'esercizio a cui il fascicolo guarda avanti.
    // ─────────────────────────────────────────────────────────────────────────────────────────────────────────────

    private function preventivo(Esercizio $esercizio): array
    {
        $gestioni = [];
        $totale = 0;

        foreach ($esercizio->gestioni()->orderBy('gestioni.id')->get() as $gestione) {
            $piano = PianoConto::where('gestione_id', $gestione->id)->first();
            if (! $piano) {
                continue;
            }

            $tabelle = DB::table('conto_tabella_millesimale as ct')
                ->join('tabelle as t', 't.id', '=', 'ct.tabella_id')
                ->join('conti as c', 'c.id', '=', 'ct.conto_id')
                ->where('c.piano_conto_id', $piano->id)
                ->orderBy('t.nome')
                ->get(['ct.conto_id', 't.nome'])
                ->groupBy('conto_id')
                ->map(fn ($righe) => $righe->pluck('nome')->unique()->implode(', '));

            $gruppi = [];
            $spese = 0;
            $entrate = 0;

            foreach ($this->capitoli($piano) as $capitolo) {
                $voci = [];
                $somma = 0;
                foreach ($capitolo['voci'] as $voce) {
                    if ($voce->is_tecnico) {
                        continue;
                    }
                    $importo = abs((int) ($voce->importo ?? 0));
                    if ($importo === 0) {
                        continue;
                    }
                    $entrata = Conto::segnoDelTipo((string) $voce->tipo) < 0;
                    $voci[] = ['nome' => (string) $voce->nome, 'importo' => $entrata ? -$importo : $importo, 'tabella' => $tabelle[$voce->id] ?? null];
                    $somma += $entrata ? -$importo : $importo;
                    $entrata ? $entrate += $importo : $spese += $importo;
                }
                if ($voci) {
                    $gruppi[] = ['capitolo' => $capitolo['nome'], 'voci' => $voci, 'totale' => $somma];
                }
            }

            if (! $gruppi) {
                continue;
            }

            $gestioni[] = [
                'nome' => (string) $gestione->nome,
                'tipo' => (string) $gestione->tipo,
                'gruppi' => $gruppi,
                'spese' => $spese,
                'entrate' => $entrate,
                'totale' => $spese - $entrate,
            ];
            $totale += $spese - $entrate;
        }

        return ['gestioni' => $gestioni, 'totale' => $totale];
    }

    /**
     * Le rate di ogni piano dell'esercizio, condòmino per condòmino. Entrano anche i piani in bozza: in un preventivo le rate
     * sono una proposta all'assemblea, e la stampa lo dice.
     */
    private function rate(Esercizio $esercizio): array
    {
        $piani = [];

        foreach ($this->pianiDi($esercizio) as $piano) {
            $rate = DB::table('rate')->where('piano_rate_id', $piano->id)->orderBy('numero_rata')->orderBy('data_scadenza')
                ->get(['id', 'numero_rata', 'data_scadenza', 'importo_totale']);
            if ($rate->isEmpty()) {
                continue;
            }

            $quote = DB::table('rate_quote as q')
                ->leftJoin('immobili as i', 'i.id', '=', 'q.immobile_id')
                ->leftJoin('anagrafiche as a', 'a.id', '=', 'q.anagrafica_id')
                ->whereIn('q.rata_id', $rate->pluck('id'))
                ->groupBy('q.rata_id', 'q.immobile_id', 'q.anagrafica_id', 'i.interno', 'i.nome', 'a.nome')
                ->select('q.rata_id', 'q.immobile_id', 'q.anagrafica_id', 'i.interno', 'i.nome as unita', 'a.nome as persona', DB::raw('SUM(q.importo) as importo'))
                ->get();

            $righe = [];
            foreach ($quote as $q) {
                $chiave = $q->immobile_id.'|'.$q->anagrafica_id;
                $righe[$chiave] ??= ['unita' => trim((string) ($q->interno ?: $q->unita)), 'persona' => (string) $q->persona, 'rate' => [], 'totale' => 0];
                $righe[$chiave]['rate'][$q->rata_id] = ($righe[$chiave]['rate'][$q->rata_id] ?? 0) + (int) $q->importo;
                $righe[$chiave]['totale'] += (int) $q->importo;
            }
            $righe = array_values($righe);
            usort($righe, fn ($a, $b) => strnatcasecmp($a['unita'], $b['unita']) ?: strcasecmp($a['persona'], $b['persona']));

            $colonne = [];
            $totali = [];
            foreach ($rate as $r) {
                $colonne[] = [
                    'id' => (int) $r->id,
                    'etichetta' => (int) $r->numero_rata === 0 ? 'Saldi' : 'Rata '.$r->numero_rata,
                    'scadenza' => self::dataIt($r->data_scadenza, corta: true),
                ];
                $totali[(int) $r->id] = array_sum(array_map(fn ($riga) => $riga['rate'][$r->id] ?? 0, $righe));
            }

            $piani[] = [
                'nome' => (string) $piano->nome,
                'bozza' => $piano->stato === 'bozza',
                'colonne' => $colonne,
                'righe' => $righe,
                'totali' => $totali,
                'totale' => array_sum(array_column($righe, 'totale')),
            ];
        }

        return ['piani' => $piani];
    }

    /** In un fascicolo preventivo i numeri che contano sono due: quanto si prevede di spendere e quanto si chiede con le rate. */
    private function numeriDelPreventivo(array $dati): array
    {
        $numeri = [];

        if (isset($dati['preventivo']['totale'])) {
            $numeri[] = ['voce' => 'Spese previste', 'importo' => (int) $dati['preventivo']['totale']];
        }
        foreach ($dati['rate']['piani'] ?? [] as $piano) {
            $numeri[] = ['voce' => 'Totale delle rate – '.$piano['nome'], 'importo' => (int) $piano['totale']];
        }

        return $numeri;
    }

    /**
     * I piani rate di un esercizio. Di norma il piano dice il suo esercizio; i piani nati prima di quella colonna (o
     * dal condominio dimostrativo) non lo dicono, e allora vale la gestione: sono dell'esercizio a cui la gestione è legata.
     *
     * @return \Illuminate\Support\Collection<int, PianoRate>
     */
    private function pianiDi(Esercizio $esercizio)
    {
        $gestioni = DB::table('esercizio_gestione')->where('esercizio_id', $esercizio->id)->pluck('gestione_id');

        return PianoRate::where('condominio_id', $esercizio->condominio_id)
            ->where(fn ($q) => $q->where('esercizio_id', $esercizio->id)
                ->orWhere(fn ($w) => $w->whereNull('esercizio_id')->whereIn('gestione_id', $gestioni)))
            ->orderBy('id')
            ->get();
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────────────────────────
    // I numeri dell'esercizio in poche righe, per la nota e per la pagina.
    // ─────────────────────────────────────────────────────────────────────────────────────────────────────────────

    private function numeri(array $pagina, array $dati): array
    {
        $numeri = [];

        if (isset($dati['spese']['totale'])) {
            $numeri[] = ['voce' => 'Spese dell\'esercizio', 'importo' => (int) $dati['spese']['totale']['speso']];
        }
        $cassa = $pagina['riepilogo']['totale'] ?? null;
        if ($cassa) {
            $numeri[] = ['voce' => 'Entrate in cassa e in banca', 'importo' => (int) $cassa['entrate']];
            $numeri[] = ['voce' => 'Uscite da cassa e banca', 'importo' => (int) $cassa['uscite']];
            $numeri[] = ['voce' => 'Disponibilità alla fine', 'importo' => (int) $cassa['finale']];
        }
        $numeri[] = ['voce' => 'Crediti verso i condòmini', 'importo' => (int) ($pagina['sintesi']['crediti_condomini'] ?? 0)];
        $numeri[] = ['voce' => 'Fatture ancora da pagare', 'importo' => (int) ($pagina['debiti']['totale_residui'] ?? 0)];

        return $numeri;
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────────────────────────
    // Colori: quelli scelti dallo studio in Impostazioni → Aspetto, schiariti per fondi e righe.
    // ─────────────────────────────────────────────────────────────────────────────────────────────────────────────

    /** @return array<string, string> colori esadecimali per la stampa */
    public static function colori(?string $chiave = null): array
    {
        $chiave ??= app(AspettoSettings::class)->colore;
        // «Grafite» sullo schermo è quasi nero: su una copertina a tutta pagina serve un grigio-blu leggibile.
        $primario = $chiave === 'grafite' || ! isset(Tema::COLORI[$chiave]) ? '#3f5468' : Tema::COLORI[$chiave]['campione'];

        $corpo = self::mescola($primario, '#ffffff', 0.22);

        return [
            'primario' => $primario,
            'scuro' => self::mescola($primario, '#000000', 0.45),
            'copertina' => $corpo,
            'testo_copertina' => self::luminosita($corpo) > 0.5 ? self::mescola($primario, '#000000', 0.68) : '#ffffff',
            'titolo' => self::mescola($primario, '#ffffff', 0.12),
            'bordo' => self::mescola($primario, '#ffffff', 0.55),
            'tenue' => self::mescola($primario, '#ffffff', 0.88),
            'fascia' => '#f7d85a',
            'testo_fascia' => '#3a3a1e',
            'totale' => '#dcf1d8',
        ];
    }

    private static function mescola(string $a, string $b, float $quota): string
    {
        [$r1, $g1, $b1] = sscanf($a, '#%02x%02x%02x');
        [$r2, $g2, $b2] = sscanf($b, '#%02x%02x%02x');

        return sprintf('#%02x%02x%02x',
            (int) round($r1 + ($r2 - $r1) * $quota),
            (int) round($g1 + ($g2 - $g1) * $quota),
            (int) round($b1 + ($b2 - $b1) * $quota));
    }

    private static function luminosita(string $colore): float
    {
        [$r, $g, $b] = sscanf($colore, '#%02x%02x%02x');

        return (0.2126 * $r + 0.7152 * $g + 0.0722 * $b) / 255;
    }

    private static function dataIt(?string $iso, bool $corta = false): ?string
    {
        if (! $iso || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $iso, $m)) {
            return $iso ?: null;
        }

        return $m[3].'/'.$m[2].'/'.($corta ? substr($m[1], 2) : $m[1]);
    }
}
