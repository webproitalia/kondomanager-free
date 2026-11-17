<?php

namespace App\Http\Controllers\Gestionale\Assemblee;

use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\Assemblee\SalvaModelloVerbaleRequest;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\VerbaleModello;
use App\Models\Condominio;
use App\Services\Assemblee\CompilatoreModello;
use App\Services\Assemblee\ModelloCanonico;
use App\Services\Assemblee\SegnapostiVerbale;
use App\Services\Assemblee\VerbaleService;
use App\Traits\HandleFlashMessages;
use App\Traits\HasCondomini;
use App\Traits\HasEsercizio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * I modelli di verbale dello studio (Web Pro Italia, 17/11/2026): l'elenco, la scrittura con la guida dei segnaposto,
 * la prova su un'assemblea vera, il predefinito. Valgono per tutti i condomini: la pagina sta nella sezione Assemblee
 * di ogni condominio perché è lì che servono, e da lì li apre anche un agente AI (le impostazioni gli sono vietate).
 *
 * Un modello si cambia e si cancella quando si vuole: i verbali già generati hanno il loro testo e non cambiano.
 */
class ModelliVerbaleController extends Controller
{
    use GuardieAssemblea, HandleFlashMessages, HasCondomini, HasEsercizio;

    public function index(Request $request, Condominio $condominio): Response
    {
        return Inertia::render('gestionale/assemblee/ModelliVerbale', $this->datiDellaTestata($condominio) + [
            'modelli' => VerbaleModello::with('autore:id,name')->orderByDesc('predefinito')->orderBy('nome')->get()->map(fn (VerbaleModello $m) => [
                'id' => (int) $m->id,
                'nome' => $m->nome,
                'descrizione' => $m->descrizione,
                'corpo' => $m->corpo,
                'predefinito' => (bool) $m->predefinito,
                'autore' => $m->autore?->name,
                'cambiato_il' => $m->updated_at?->setTimezone(config('app.user_timezone', 'Europe/Rome'))->format('Y-m-d'),
                'sconosciuti' => CompilatoreModello::sconosciuti((string) $m->corpo, array_merge(array_keys(SegnapostiVerbale::ELENCO), array_keys(SegnapostiVerbale::PUNTI)), ['punti' => true]),
            ])->values(),
            'assemblee' => Assemblea::where('condominio_id', $condominio->id)->orderByDesc('id')->limit(30)->get(['id', 'titolo'])->map(fn ($a) => ['id' => (int) $a->id, 'titolo' => $a->titolo])->values(),
            'segnaposti' => ['semplici' => SegnapostiVerbale::ELENCO, 'punti' => SegnapostiVerbale::PUNTI],
            'canonico' => ModelloCanonico::CORPO,
            'puo' => AssembleaController::permessi($request),
        ]);
    }

    public function store(SalvaModelloVerbaleRequest $request, Condominio $condominio): RedirectResponse
    {
        $dati = $request->validated();

        $modello = DB::transaction(function () use ($dati, $request) {
            $modello = VerbaleModello::create([
                'nome' => trim($dati['nome']),
                'descrizione' => filled($dati['descrizione'] ?? null) ? trim($dati['descrizione']) : null,
                'corpo' => $dati['corpo'],
                'predefinito' => false,
                'created_by' => $request->user()?->id,
            ]);
            if (! empty($dati['predefinito']) || VerbaleModello::count() === 1) {
                self::rendiPredefinito($modello);
            }

            return $modello;
        });

        return back(fallback: route('admin.gestionale.assemblee.modelli.index', ['condominio' => $condominio->id]))
            ->with($this->flashSuccess('Modello «'.$modello->nome.'» salvato.'));
    }

    public function update(SalvaModelloVerbaleRequest $request, Condominio $condominio, string|int $modello): RedirectResponse
    {
        $esistente = $this->assicuraModello($modello);
        $dati = $request->validated();

        DB::transaction(function () use ($esistente, $dati) {
            foreach (['nome', 'corpo'] as $campo) {
                if (array_key_exists($campo, $dati)) {
                    $esistente->{$campo} = $campo === 'nome' ? trim($dati[$campo]) : $dati[$campo];
                }
            }
            if (array_key_exists('descrizione', $dati)) {
                $esistente->descrizione = filled($dati['descrizione']) ? trim($dati['descrizione']) : null;
            }
            $esistente->save();
            if (! empty($dati['predefinito'])) {
                self::rendiPredefinito($esistente);
            }
        });

        return back(fallback: route('admin.gestionale.assemblee.modelli.index', ['condominio' => $condominio->id]))
            ->with($this->flashSuccess('Modello «'.$esistente->nome.'» salvato. I verbali già scritti non cambiano.'));
    }

    public function destroy(Condominio $condominio, string|int $modello): RedirectResponse
    {
        $esistente = $this->assicuraModello($modello);
        $eraPredefinito = $esistente->predefinito;
        $esistente->delete();

        // Senza predefinito la pagina del verbale proporrebbe il primo a caso: il predefinito passa al primo che resta.
        if ($eraPredefinito && ($altro = VerbaleModello::orderBy('id')->first())) {
            self::rendiPredefinito($altro);
        }

        return back(fallback: route('admin.gestionale.assemblee.modelli.index', ['condominio' => $condominio->id]))
            ->with($this->flashSuccess('Modello eliminato. I verbali già scritti non cambiano.'));
    }

    /**
     * Prova un corpo di modello su un'assemblea di questo condominio, senza salvare niente: il testo che uscirebbe e i
     * segnaposto che il programma non conosce.
     */
    public function prova(Request $request, Condominio $condominio, VerbaleService $verbali): JsonResponse
    {
        $dati = $request->validate([
            'corpo' => ['required', 'string', 'max:200000'],
            'assemblea_id' => ['required', 'integer'],
        ]);
        $assemblea = Assemblea::where('condominio_id', $condominio->id)->findOrFail((int) $dati['assemblea_id']);
        $modello = new VerbaleModello(['corpo' => $dati['corpo']]);

        return response()->json([
            'testo' => $verbali->compila($assemblea, $modello),
            'sconosciuti' => CompilatoreModello::sconosciuti($dati['corpo'], array_merge(array_keys(SegnapostiVerbale::ELENCO), array_keys(SegnapostiVerbale::PUNTI)), ['punti' => true]),
        ], 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Il modello dell'indirizzo: i modelli sono dello studio, non di un condominio; se non c'è, 404. */
    private function assicuraModello(string|int $id): VerbaleModello
    {
        abort_unless(is_numeric($id), 404);

        return VerbaleModello::findOrFail((int) $id);
    }

    private static function rendiPredefinito(VerbaleModello $modello): void
    {
        VerbaleModello::where('id', '!=', $modello->id)->where('predefinito', true)->update(['predefinito' => false]);
        $modello->forceFill(['predefinito' => true])->save();
    }
}
