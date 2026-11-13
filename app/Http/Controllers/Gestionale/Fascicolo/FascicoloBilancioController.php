<?php

namespace App\Http\Controllers\Gestionale\Fascicolo;

use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\Fascicolo\UpdateFascicoloBilancioRequest;
use App\Http\Resources\Condominio\CondominioResource;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\FascicoloBilancio;
use App\Services\Fascicolo\FascicoloBilancioService;
use App\Services\PDF\PdfService;
use App\Traits\HandleFlashMessages;
use App\Traits\HasCondomini;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Mpdf\Mpdf;

/**
 * Il fascicolo di bilancio di un esercizio (Web Pro Italia, 13/11/2026): la pagina dove lo studio scrive la nota e
 * sceglie le sezioni, e la stampa del PDF unico. Il controller non calcola niente: i numeri li dà
 * `FascicoloBilancioService`, che li legge dagli stessi servizi delle stampe singole.
 */
class FascicoloBilancioController extends Controller
{
    use HandleFlashMessages;
    use HasCondomini;

    /** Le sezioni che cominciano su una pagina nuova (le altre proseguono sotto la precedente). Lo usa anche la vista. */
    public const SALTO_PRIMA = ['spese', 'patrimoniale', 'registro', 'preventivo'];

    public function __construct(private readonly FascicoloBilancioService $servizio) {}

    public function index(Condominio $condominio, Esercizio $esercizio): Response
    {
        $this->controlla($condominio, $esercizio);

        $fascicolo = FascicoloBilancio::where('esercizio_id', $esercizio->id)->first();
        $dati = $this->servizio->costruisci($condominio, $esercizio, $fascicolo);

        return Inertia::render('gestionale/movimenti/fascicoloBilancio/Show', [
            'condominio' => $condominio,
            'condomini' => CondominioResource::collection($this->getCondomini())->resolve(),
            'esercizio' => $esercizio,
            'esercizi' => $condominio->esercizi()->orderByDesc('data_inizio')->get(),
            'fascicolo' => [
                'tipo' => $fascicolo?->tipo,
                'titolo' => $fascicolo?->titolo,
                'sottotitolo' => $fascicolo?->sottotitolo,
                'nota' => $fascicolo?->nota,
                'sezioni' => $fascicolo?->sezioni,
                'aggiornato_il' => $fascicolo?->updated_at?->format('d/m/Y H:i'),
            ],
            // Che cosa uscirebbe adesso: il tipo deciso, le sezioni che hanno qualcosa da dire, i titoli di copertina, i numeri.
            'anteprima' => [
                'tipo' => $dati['tipo'],
                'sezioni' => $dati['sezioni'],
                'copertina' => ['titoli' => $dati['copertina']['titoli'], 'sottotitolo' => $dati['copertina']['sottotitolo'], 'studio' => $dati['copertina']['studio'], 'ha_foto' => $dati['copertina']['foto'] !== null],
                'numeri' => $dati['numeri'],
                'esercizio_preventivo' => $dati['esercizio_preventivo'],
            ],
            'opzioni' => [
                'tipi' => FascicoloBilancioService::TIPI,
                'sezioni' => FascicoloBilancioService::SEZIONI,
                'di_serie' => [
                    'consuntivo' => FascicoloBilancioService::sezioniDiSerie('consuntivo'),
                    'preventivo' => FascicoloBilancioService::sezioniDiSerie('preventivo'),
                ],
            ],
        ]);
    }

    public function update(UpdateFascicoloBilancioRequest $request, Condominio $condominio, Esercizio $esercizio)
    {
        $this->controlla($condominio, $esercizio);

        $dati = $request->validated();

        FascicoloBilancio::updateOrCreate(
            ['esercizio_id' => $esercizio->id],
            [
                'tipo' => $dati['tipo'] ?? null,
                'titolo' => self::vuotoANull($dati['titolo'] ?? null),
                'sottotitolo' => self::vuotoANull($dati['sottotitolo'] ?? null),
                'nota' => self::vuotoANull($dati['nota'] ?? null),
                // Nessuna sezione scelta = quelle di serie: un fascicolo vuoto non è una scelta, è una dimenticanza.
                'sezioni' => ! empty($dati['sezioni']) ? array_values($dati['sezioni']) : null,
                'aggiornato_da' => $request->user()?->id,
            ],
        );

        return back()->with($this->flashSuccess('Fascicolo di bilancio salvato.'));
    }

    /**
     * Il PDF. Si impagina due volte: la prima per sapere a che pagina cade ogni sezione, la seconda con il sommario
     * compilato. Le righe del sommario sono le stesse nelle due passate, quindi l'impaginazione non si sposta.
     *
     * Con `?bozza=1` porta la filigrana «BOZZA» e la data sulla copertina. `?tipo=` e `?sezioni[]=` valgono per quella
     * stampa soltanto, senza cambiare quello che è salvato.
     */
    public function stampa(Request $request, Condominio $condominio, Esercizio $esercizio, PdfService $pdf)
    {
        $this->controlla($condominio, $esercizio);

        $fascicolo = FascicoloBilancio::where('esercizio_id', $esercizio->id)->first();

        $opzioni = ['bozza' => $request->boolean('bozza')];
        if (in_array($request->query('tipo'), FascicoloBilancioService::TIPI, true)) {
            $opzioni['tipo'] = $request->query('tipo');
        }
        if (is_array($request->query('sezioni')) && $request->query('sezioni')) {
            $opzioni['sezioni'] = $request->query('sezioni');
        }

        $dati = $this->servizio->costruisci($condominio, $esercizio, $fascicolo, $opzioni);
        $dati['senza_firma'] = true;

        $config = [
            'default_font' => 'inter',
            'orientation' => 'P',
            'margin_left' => 14,
            'margin_right' => 14,
            'margin_top' => 15,
            'margin_bottom' => 17,
            'margin_header' => 0,
            'margin_footer' => 7,
        ];
        if ($dati['bozza']) {
            $config += ['showWatermarkText' => true, 'watermarkText' => 'BOZZA', 'watermarkTextAlpha' => 0.07];
        }

        $prima = $pdf->generate('pdf.gestionale.fascicolo_bilancio', $dati + ['pagine' => []], $config);
        $pagine = self::pagine($prima, $dati['sezioni']);
        $mpdf = $pdf->generate('pdf.gestionale.fascicolo_bilancio', $dati + ['pagine' => $pagine], $config);

        $nomeFile = PdfService::nomeFile(
            $dati['bozza'] ? 'bozza-fascicolo-bilancio' : 'fascicolo-bilancio',
            $condominio->nome,
            str_replace('/', '-', FascicoloBilancioService::etichettaAnno($esercizio)),
            $dati['tipo'] === 'preventivo' ? 'preventivo' : null,
        );

        return response($mpdf->Output($nomeFile, \Mpdf\Output\Destination::STRING_RETURN))
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="'.$nomeFile.'"');
    }

    /**
     * Da che pagina a che pagina va ogni sezione, letto dai segnalibri che la vista mette in testa a ciascuna.
     *
     * @param  list<array{chiave: string, titolo: string}>  $sezioni
     * @return array<string, array{0: int, 1: int}>
     */
    private static function pagine(Mpdf $mpdf, array $sezioni): array
    {
        $inizio = [];
        foreach ($mpdf->BMoutlines as $segnalibro) {
            foreach ($sezioni as $s) {
                if (($segnalibro['t'] ?? null) === $s['titolo'] && ! isset($inizio[$s['chiave']])) {
                    $inizio[$s['chiave']] = (int) $segnalibro['p'];
                }
            }
        }

        $ultima = (int) $mpdf->page;
        $pagine = [];
        $chiavi = array_column($sezioni, 'chiave');

        foreach ($chiavi as $i => $chiave) {
            if (! isset($inizio[$chiave])) {
                continue;
            }
            $prossima = $chiavi[$i + 1] ?? null;
            if ($prossima === null || ! isset($inizio[$prossima])) {
                $fine = $ultima;
            } else {
                // Se la sezione dopo comincia su una pagina nuova, questa finisce alla pagina prima; altrimenti finisce
                // sulla stessa pagina in cui comincia l'altra.
                $fine = in_array($prossima, self::SALTO_PRIMA, true) ? $inizio[$prossima] - 1 : $inizio[$prossima];
            }
            $pagine[$chiave] = [$inizio[$chiave], max($inizio[$chiave], $fine)];
        }

        return $pagine;
    }

    private function controlla(Condominio $condominio, Esercizio $esercizio): void
    {
        if ($esercizio->condominio_id !== $condominio->id) {
            abort(403, 'L\'esercizio non appartiene a questo condominio.');
        }
    }

    private static function vuotoANull(?string $testo): ?string
    {
        $testo = $testo === null ? null : trim($testo);

        return $testo === '' ? null : $testo;
    }
}
