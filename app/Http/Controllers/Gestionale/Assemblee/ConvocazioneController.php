<?php

namespace App\Http\Controllers\Gestionale\Assemblee;

use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\Assemblee\AggiornaConvocazioneRequest;
use App\Http\Requests\Gestionale\Assemblee\GeneraConvocazioneRequest;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\VerbaleModello;
use App\Models\Condominio;
use App\Services\Assemblee\AssembleaService;
use App\Services\Assemblee\ConvocazioneService;
use App\Services\Assemblee\DestinatariAssemblea;
use App\Services\Assemblee\InviiAssemblea;
use App\Services\Assemblee\SegnapostiConvocazione;
use App\Services\Assemblee\VerbaleService;
use App\Traits\HandleFlashMessages;
use App\Traits\HasCondomini;
use App\Traits\HasEsercizio;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * L'avviso di convocazione dell'assemblea e il foglio delle firme (Web Pro Italia, 17/11/2026): la pagina per generare
 * il testo dal modello dello studio, correggerlo e stamparlo (l'avviso generico, uno per persona in un PDF solo, lo
 * .zip con un PDF per persona) e la stampa del foglio delle firme. Gli invii (per email, o la spedizione registrata a
 * mano) stanno in {@see InviiAssembleaController}.
 *
 * Le stampe non scrivono niente: si rifanno dal testo salvato ogni volta. Gli errori di una stampa tornano come testo
 * semplice, perché la risposta si apre in una scheda del browser (e un agente AI ne legge il motivo).
 */
class ConvocazioneController extends Controller
{
    use GuardieAssemblea, HandleFlashMessages, HasCondomini, HasEsercizio;

    public function __construct(
        private readonly ConvocazioneService $convocazione,
        private readonly DestinatariAssemblea $destinatari,
        private readonly InviiAssemblea $invii,
        private readonly AssembleaService $assemblee,
        private readonly VerbaleService $verbali,
    ) {}

    /**
     * La pagina della convocazione. È anche la lettura per un agente AI: il testo, quello che il modello darebbe
     * adesso, gli avvisi (i cinque giorni), l'anteprima «chi riceve che cosa» con lo stato degli invii, i segnaposto.
     */
    public function show(Request $request, Condominio $condominio, Assemblea $assemblea): Response
    {
        $this->assicuraAssemblea($condominio, $assemblea);
        $assemblea->load(['condominio', 'tabella:id,nome', 'esercizio:id,nome', 'verbale', 'punti', 'modelloConvocazione:id,nome']);
        $modelli = VerbaleModello::where('tipo', VerbaleModello::CONVOCAZIONE)->orderByDesc('predefinito')->orderBy('nome')->get(['id', 'nome', 'descrizione', 'predefinito']);

        return Inertia::render('gestionale/assemblee/ConvocazioneEdit', $this->datiDellaTestata($condominio) + [
            'assemblea' => $this->assemblee->datiAssemblea($assemblea),
            'punti' => $assemblea->punti->map(fn ($p) => ['numero' => (int) $p->numero, 'titolo' => $p->titolo, 'descrizione' => $p->descrizione])->values(),
            'convocazione' => [
                'testo' => $assemblea->convocazione_testo,
                'modello_id' => $assemblea->convocazione_modello_id ? (int) $assemblea->convocazione_modello_id : null,
                'modello' => $assemblea->modelloConvocazione?->nome,
                'collegamento_videoconferenza' => $assemblea->collegamento_videoconferenza,
                'modificabile' => ! $assemblea->bloccata(),
            ],
            'modelli' => $modelli->map(fn (VerbaleModello $m) => ['id' => (int) $m->id, 'nome' => $m->nome, 'descrizione' => $m->descrizione, 'predefinito' => (bool) $m->predefinito])->values(),
            // Che cosa darebbe adesso il modello usato (o il predefinito): la pagina lo mostra accanto al testo, per confrontarli.
            'proposta' => $this->convocazione->compila($assemblea, VerbaleModello::delTipo(VerbaleModello::CONVOCAZIONE, $assemblea->convocazione_modello_id)),
            'avvisi' => $this->convocazione->avvisi($assemblea),
            'invio' => $this->invii->anteprima($assemblea, InviiAssemblea::CONVOCAZIONE, $request->only('indirizzi')),
            'segnaposti' => ['semplici' => SegnapostiConvocazione::ELENCO, 'punti' => SegnapostiConvocazione::PUNTI, 'destinatario' => SegnapostiConvocazione::DESTINATARIO],
            'puo' => AssembleaController::permessi($request),
            // Le funzioni con cui un agente AI lavora la convocazione e gli invii (`funzioni` è un nome del layout, che il ponte degli agenti toglie).
            'per_gli_agenti' => AssembleaController::funzioniPerGliAgenti(),
        ]);
    }

    /** Il testo della convocazione e il collegamento della videoconferenza: si cambia solo quello che si manda. */
    public function update(AggiornaConvocazioneRequest $request, Condominio $condominio, Assemblea $assemblea): RedirectResponse
    {
        $this->assicuraAssemblea($condominio, $assemblea);
        $this->verbali->assicuraModificabile($assemblea);
        $dati = $request->validated();

        if (array_key_exists('testo', $dati)) {
            $assemblea->convocazione_testo = filled($dati['testo']) ? (string) $dati['testo'] : null;
        }
        if (array_key_exists('collegamento_videoconferenza', $dati)) {
            $assemblea->collegamento_videoconferenza = filled($dati['collegamento_videoconferenza']) ? trim((string) $dati['collegamento_videoconferenza']) : null;
        }
        $assemblea->save();

        return back(fallback: $this->indirizzo($condominio, $assemblea))->with($this->flashSuccess('Convocazione salvata.'));
    }

    /** Genera il testo dal modello scelto (o dal predefinito). Sopra un testo che c'è già, solo con `sovrascrivi`. */
    public function genera(GeneraConvocazioneRequest $request, Condominio $condominio, Assemblea $assemblea): RedirectResponse
    {
        $this->assicuraAssemblea($condominio, $assemblea);
        $dati = $request->validated();
        $modello = VerbaleModello::delTipo(VerbaleModello::CONVOCAZIONE, $dati['modello_id'] ?? null);

        $this->convocazione->genera($assemblea, $modello, (bool) ($dati['sovrascrivi'] ?? false));
        $nome = ($modello ?? VerbaleModello::predefinito(VerbaleModello::CONVOCAZIONE))?->nome ?? 'di serie';

        return back(fallback: $this->indirizzo($condominio, $assemblea))
            ->with($this->flashSuccess('Testo della convocazione generato dal modello «'.$nome.'». Ora lo puoi correggere liberamente.'));
    }

    /**
     * Il PDF dell'avviso. Senza parametri, quello generico (il modulo di delega in bianco); con `?persona=ID`, quello
     * di una persona fra gli aventi diritto, con il suo nome e il suo indirizzo in alto.
     */
    public function stampa(Request $request, Condominio $condominio, Assemblea $assemblea)
    {
        $this->assicuraAssemblea($condominio, $assemblea);

        if ($request->filled('persona')) {
            $persona = collect($this->destinatari->persone($assemblea))->firstWhere('id', (int) $request->query('persona'));
            abort_if($persona === null || ! is_numeric($request->query('persona')), 404);

            return $this->pdf($this->convocazione->pdf($assemblea, [$persona]), $this->convocazione->nomeFile($assemblea, 'convocazione', \Illuminate\Support\Str::slug($persona['nome'])));
        }

        return $this->pdf($this->convocazione->pdf($assemblea), $this->convocazione->nomeFile($assemblea));
    }

    /** Un PDF solo con l'avviso di ogni avente diritto, uno dopo l'altro: da stampare e imbustare. */
    public function stampaPerPersona(Condominio $condominio, Assemblea $assemblea)
    {
        $this->assicuraAssemblea($condominio, $assemblea);
        $persone = $this->destinatari->persone($assemblea);
        if ($persone === []) {
            return self::rifiuto('Avvisi non stampati. Non risulta nessun avente diritto: controlla i titolari delle unità del condominio.');
        }

        return $this->pdf($this->convocazione->pdf($assemblea, $persone), $this->convocazione->nomeFile($assemblea, 'convocazione', 'per-persona'));
    }

    /** L'archivio .zip: un PDF per persona e l'elenco degli indirizzi email. */
    public function zip(Condominio $condominio, Assemblea $assemblea)
    {
        $this->assicuraAssemblea($condominio, $assemblea);

        try {
            $esito = $this->convocazione->archivio($assemblea);
        } catch (ValidationException $e) {
            return self::rifiuto('Archivio non fatto. '.collect($e->errors())->flatten()->first());
        } catch (\RuntimeException $e) {
            return self::rifiuto('Archivio non fatto. '.$e->getMessage());
        }

        // Lo .zip è un file temporaneo: va al browser letto a pezzi, e si cancella dopo.
        return response()->download($esito['zip'], $esito['nome'], ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }

    /** Il foglio delle firme, da stampare e portare in assemblea. */
    public function foglioFirme(Condominio $condominio, Assemblea $assemblea)
    {
        $this->assicuraAssemblea($condominio, $assemblea);

        return $this->pdf($this->convocazione->pdfFoglioFirme($assemblea), $this->convocazione->nomeFile($assemblea, 'foglio-firme'));
    }

    private function pdf(string $contenuto, string $nome)
    {
        return response($contenuto)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="'.$nome.'"');
    }

    private static function rifiuto(string $frase)
    {
        return response($frase, 422)->header('Content-Type', 'text/plain; charset=UTF-8');
    }

    private function indirizzo(Condominio $condominio, Assemblea $assemblea): string
    {
        return route('admin.gestionale.assemblee.convocazione.show', ['condominio' => $condominio->id, 'assemblea' => $assemblea->id]);
    }
}
