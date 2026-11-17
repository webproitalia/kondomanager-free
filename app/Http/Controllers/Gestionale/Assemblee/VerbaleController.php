<?php

namespace App\Http\Controllers\Gestionale\Assemblee;

use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\Assemblee\AggiornaVerbaleRequest;
use App\Http\Requests\Gestionale\Assemblee\GeneraVerbaleRequest;
use App\Http\Requests\Gestionale\Assemblee\InviaVerbaleRequest;
use App\Http\Requests\Gestionale\Assemblee\PrecompilaVerbaleRequest;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\AssembleaPunto;
use App\Models\Assemblee\Verbale;
use App\Models\Assemblee\VerbaleModello;
use App\Models\Condominio;
use App\Services\Assemblee\AssembleaService;
use App\Services\Assemblee\SegnapostiVerbale;
use App\Services\Assemblee\VerbaleService;
use App\Services\Documenti\ArchivioDocumenti;
use App\Traits\HandleFlashMessages;
use App\Traits\HasCondomini;
use App\Traits\HasEsercizio;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Il verbale dell'assemblea (Web Pro Italia, 17/11/2026): la pagina per scriverlo e rileggerlo, la generazione da un
 * modello dello studio, la bozza dei punti da un assistente, gli stati (bozza → da approvare → approvato, e la nuova
 * versione) e la stampa. L'approvazione e la nuova versione le fa solo una persona: le due rotte sono vietate agli
 * agenti AI (`AutenticaAgente::VIETATE`).
 */
class VerbaleController extends Controller
{
    use GuardieAssemblea, HandleFlashMessages, HasCondomini, HasEsercizio;

    public function __construct(
        private readonly VerbaleService $verbali,
        private readonly AssembleaService $assemblee,
    ) {}

    public function show(Request $request, Condominio $condominio, Assemblea $assemblea): Response
    {
        $this->assicuraAssemblea($condominio, $assemblea);
        $fotografia = $this->assemblee->fotografia($assemblea);
        $modelli = VerbaleModello::where('tipo', VerbaleModello::VERBALE)->orderByDesc('predefinito')->orderBy('nome')->get(['id', 'nome', 'descrizione', 'predefinito']);

        return Inertia::render('gestionale/assemblee/VerbaleEdit', $this->datiDellaTestata($condominio) + [
            'fotografia' => $fotografia,
            'modelli' => $modelli->map(fn (VerbaleModello $m) => ['id' => (int) $m->id, 'nome' => $m->nome, 'descrizione' => $m->descrizione, 'predefinito' => (bool) $m->predefinito])->values(),
            // Che cosa darebbe adesso il modello del verbale (o il predefinito): la pagina lo mostra accanto al testo, per confrontarli.
            'proposta' => $this->verbali->compila($assemblea, VerbaleModello::delTipo(VerbaleModello::VERBALE, $assemblea->verbale?->modello_id)),
            'segnaposti' => ['semplici' => SegnapostiVerbale::ELENCO, 'punti' => SegnapostiVerbale::PUNTI],
            'puo' => AssembleaController::permessi($request),
            // «Manda il verbale» (17/11/2026): chi lo riceve (di serie gli assenti), chi resta senza indirizzo, che cosa è già stato mandato.
            // Si può mandare solo un verbale approvato: prima la pagina dice soltanto perché no.
            'invio' => app(\App\Services\Assemblee\InviiAssemblea::class)->anteprima($assemblea, \App\Services\Assemblee\InviiAssemblea::VERBALE, $request->only(['indirizzi', 'chi'])),
        ]);
    }

    /** Il testo del verbale, i testi facoltativi e, se ci sono, discussione e delibera dei punti. */
    public function update(AggiornaVerbaleRequest $request, Condominio $condominio, Assemblea $assemblea): RedirectResponse
    {
        $this->assicuraAssemblea($condominio, $assemblea);
        $this->verbali->assicuraModificabile($assemblea);
        $verbale = $this->verbali->verbaleDi($assemblea);
        if ($verbale->stato !== Verbale::BOZZA) {
            throw ValidationException::withMessages(['verbale' => 'Il verbale è in attesa di approvazione: ritiralo prima di cambiarlo.']);
        }
        $dati = $request->validated();
        // Un agente AI che risalva il testo identico non lo ha riletto: non deve bastare a far passare il controllo dei dati cambiati
        // (`dati_riletti` all'invio resta la sua dichiarazione esplicita). Per la persona «Salva il testo» vale come «ho riletto».
        $rilettoDavvero = ! ($request->attributes->has(\App\Http\Middleware\AutenticaAgente::ATTRIBUTO)
            && array_key_exists('testo', $dati) && trim((string) $dati['testo']) === trim((string) $verbale->testo));

        DB::transaction(function () use ($verbale, $dati, $assemblea, $rilettoDavvero) {
            foreach (['testo', 'intestazione', 'apertura', 'chiusura'] as $campo) {
                if (array_key_exists($campo, $dati)) {
                    $verbale->{$campo} = filled($dati[$campo]) ? (string) $dati[$campo] : null;
                }
            }
            $verbale->save();

            if (! empty($dati['punti'])) {
                $punti = AssembleaPunto::where('assemblea_id', $assemblea->id)->get()->keyBy('id');
                foreach ($dati['punti'] as $i => $p) {
                    $punto = $punti->get((int) $p['id']);
                    if ($punto === null) {
                        throw ValidationException::withMessages(["punti.{$i}.id" => 'Questo punto non è dell\'assemblea.']);
                    }
                    foreach (['discussione', 'delibera'] as $campo) {
                        if (array_key_exists($campo, $p)) {
                            $punto->{$campo} = filled($p[$campo]) ? trim((string) $p[$campo]) : null;
                        }
                    }
                    $punto->save();
                }
            }

            // Chi salva il testo lo ha scritto o riletto con i dati di adesso: se ne fotografa l'impronta, e l'invio in
            // approvazione dirà se poi i dati sono cambiati. Salvare solo i testi dei punti o di contorno non la tocca.
            if (array_key_exists('testo', $dati) && $rilettoDavvero) {
                $this->verbali->segnaTestoRiletto($assemblea);
            }
        });

        return back(fallback: $this->indirizzo($condominio, $assemblea))->with($this->flashSuccess('Verbale salvato.'));
    }

    /** Genera il testo dal modello scelto (o dal predefinito). Sopra un testo che c'è già, solo con `sovrascrivi`. */
    public function genera(GeneraVerbaleRequest $request, Condominio $condominio, Assemblea $assemblea): RedirectResponse
    {
        $this->assicuraAssemblea($condominio, $assemblea);
        $dati = $request->validated();
        $modello = VerbaleModello::delTipo(VerbaleModello::VERBALE, $dati['modello_id'] ?? null);

        $verbale = $this->verbali->genera($assemblea, $modello, (bool) ($dati['sovrascrivi'] ?? false));

        return back(fallback: $this->indirizzo($condominio, $assemblea))
            ->with($this->flashSuccess('Testo del verbale generato dal modello «'.($verbale->modello?->nome ?? 'di serie').'». Ora lo puoi correggere liberamente.'));
    }

    /** Una bozza di discussione e delibera per i punti: riempie solo i campi vuoti. */
    public function precompila(PrecompilaVerbaleRequest $request, Condominio $condominio, Assemblea $assemblea): RedirectResponse
    {
        $this->assicuraAssemblea($condominio, $assemblea);
        $dati = $request->validated();

        $riempiti = $this->verbali->precompilaDaBozza($assemblea, $dati['punti'], (string) ($dati['fonte'] ?? 'assistente'));

        return back(fallback: $this->indirizzo($condominio, $assemblea))->with($this->flashSuccess(match ($riempiti) {
            0 => 'Nessun campo riempito: i punti avevano già il loro testo, o la bozza non ne aveva.',
            1 => 'Riempito 1 campo vuoto dei punti. Quello che era già scritto non è cambiato.',
            default => "Riempiti {$riempiti} campi vuoti dei punti. Quello che era già scritto non è cambiato.",
        }));
    }

    /**
     * Manda il verbale in approvazione, dopo i controlli sul testo e sui dati. `dati_riletti` e `esiti_confermati` sono
     * le due conferme esplicite di `InviaVerbaleRequest`.
     */
    public function invia(InviaVerbaleRequest $request, Condominio $condominio, Assemblea $assemblea): RedirectResponse
    {
        $this->assicuraAssemblea($condominio, $assemblea);
        $dati = $request->validated();
        $this->verbali->inviaInApprovazione($assemblea, (bool) ($dati['dati_riletti'] ?? false), $dati['esiti_confermati'] ?? []);

        return back(fallback: $this->indirizzo($condominio, $assemblea))->with($this->flashSuccess('Verbale mandato in approvazione. Lo approva una persona con il permesso «Approva verbali».'));
    }

    public function ritira(Condominio $condominio, Assemblea $assemblea): RedirectResponse
    {
        $this->assicuraAssemblea($condominio, $assemblea);
        $this->verbali->ritira($assemblea);

        return back(fallback: $this->indirizzo($condominio, $assemblea))->with($this->flashSuccess('Verbale tornato in bozza.'));
    }

    /** L'approvazione: solo una persona (rotta vietata agli agenti). Il PDF entra nell'archivio del condominio. */
    public function approva(Request $request, Condominio $condominio, Assemblea $assemblea): RedirectResponse
    {
        $this->assicuraAssemblea($condominio, $assemblea);
        $verbale = $this->verbali->approva($assemblea, $request->user());

        return back(fallback: $this->indirizzo($condominio, $assemblea))
            ->with($this->flashSuccess('Verbale approvato: il PDF è nell\'archivio dei documenti del condominio («'.($verbale->documento?->name ?? 'Verbale').'»). Non si modifica più.'));
    }

    /** Una nuova versione di un verbale approvato (rotta vietata agli agenti). */
    public function riapri(Condominio $condominio, Assemblea $assemblea): RedirectResponse
    {
        $this->assicuraAssemblea($condominio, $assemblea);
        $verbale = $this->verbali->riapri($assemblea);

        return back(fallback: $this->indirizzo($condominio, $assemblea))
            ->with($this->flashSuccess('Aperta la versione '.$verbale->versione.' del verbale. Il PDF approvato prima resta nell\'archivio.'));
    }

    /** Il PDF: quello approvato dall'archivio, oppure la bozza con la filigrana. `?bozza=1` stampa sempre la bozza. */
    public function stampa(Request $request, Condominio $condominio, Assemblea $assemblea)
    {
        $this->assicuraAssemblea($condominio, $assemblea);
        $verbale = $this->verbali->verbaleDi($assemblea);
        $archivio = app(ArchivioDocumenti::class);

        if ($verbale->approvato() && ! $request->boolean('bozza') && $verbale->documento && ($contenuto = $archivio->leggi($verbale->documento->path)) !== null) {
            $nome = $this->verbali->nomeFile($assemblea, false);
        } else {
            $contenuto = $this->verbali->pdf($assemblea, true);
            $nome = $this->verbali->nomeFile($assemblea, true);
        }

        return response($contenuto)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="'.$nome.'"');
    }

    private function indirizzo(Condominio $condominio, Assemblea $assemblea): string
    {
        return route('admin.gestionale.assemblee.verbale.show', ['condominio' => $condominio->id, 'assemblea' => $assemblea->id]);
    }
}
