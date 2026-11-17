<?php

namespace App\Http\Controllers\Gestionale\Assemblee;

use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\Assemblee\SalvaModelloVerbaleRequest;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\VerbaleModello;
use App\Models\Condominio;
use App\Services\Assemblee\CompilatoreModello;
use App\Services\Assemblee\ConvocazioneService;
use App\Services\Assemblee\ModelliDiSerie;
use App\Services\Assemblee\ModelloCanonico;
use App\Services\Assemblee\SegnapostiConvocazione;
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
 *
 * Dal 17/11/2026 la pagina porta quattro tipi di modello (`VerbaleModello::TIPI`): il verbale, l'avviso di convocazione,
 * il testo dell'email della convocazione e quello della trasmissione del verbale. Ognuno ha il suo predefinito, i suoi
 * segnaposto (quelli del verbale, o quelli della convocazione) e il suo modello di serie da cui ripartire.
 */
class ModelliVerbaleController extends Controller
{
    use GuardieAssemblea, HandleFlashMessages, HasCondomini, HasEsercizio;

    public function index(Request $request, Condominio $condominio): Response
    {
        return Inertia::render('gestionale/assemblee/ModelliVerbale', $this->datiDellaTestata($condominio) + [
            'modelli' => VerbaleModello::with('autore:id,name')->orderByDesc('predefinito')->orderBy('nome')->get()->map(fn (VerbaleModello $m) => [
                'id' => (int) $m->id,
                'tipo' => (string) $m->tipo,
                'nome' => $m->nome,
                'descrizione' => $m->descrizione,
                'corpo' => $m->corpo,
                'predefinito' => (bool) $m->predefinito,
                'autore' => $m->autore?->name,
                'cambiato_il' => $m->updated_at?->setTimezone(config('app.user_timezone', 'Europe/Rome'))->format('Y-m-d'),
                'sconosciuti' => CompilatoreModello::sconosciuti((string) $m->corpo, self::chiavi((string) $m->tipo), ['punti' => true]),
            ])->values(),
            // I tipi di modello, ognuno con il suo nome, i suoi segnaposto e il modello di serie da cui parte un modello nuovo.
            'tipi' => collect(VerbaleModello::TIPI)->map(fn (string $nome, string $tipo) => [
                'tipo' => $tipo,
                'nome' => $nome,
                'segnaposti' => self::segnaposti($tipo),
                'canonico' => ModelliDiSerie::corpo($tipo),
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
                'tipo' => $dati['tipo'] ?? VerbaleModello::VERBALE,
                'nome' => trim($dati['nome']),
                'descrizione' => filled($dati['descrizione'] ?? null) ? trim($dati['descrizione']) : null,
                'corpo' => $dati['corpo'],
                'predefinito' => false,
                'created_by' => $request->user()?->id,
            ]);
            // Il primo modello di un tipo è il suo predefinito.
            if (! empty($dati['predefinito']) || VerbaleModello::where('tipo', $modello->tipo)->count() === 1) {
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

        // Senza predefinito la pagina proporrebbe il primo a caso: il predefinito passa al primo che resta, dello stesso tipo.
        if ($eraPredefinito && ($altro = VerbaleModello::where('tipo', $esistente->tipo)->orderBy('id')->first())) {
            self::rendiPredefinito($altro);
        }

        return back(fallback: route('admin.gestionale.assemblee.modelli.index', ['condominio' => $condominio->id]))
            ->with($this->flashSuccess('Modello eliminato. I verbali già scritti non cambiano.'));
    }

    /**
     * Prova un corpo di modello su un'assemblea di questo condominio, senza salvare niente: il testo che uscirebbe e i
     * segnaposto che il programma non conosce.
     */
    public function prova(Request $request, Condominio $condominio, VerbaleService $verbali, ConvocazioneService $convocazione): JsonResponse
    {
        $dati = $request->validate([
            'corpo' => ['required', 'string', 'max:200000'],
            'assemblea_id' => ['required', 'integer'],
            'tipo' => ['sometimes', 'string', \Illuminate\Validation\Rule::in(array_keys(VerbaleModello::TIPI))],
        ]);
        $assemblea = Assemblea::where('condominio_id', $condominio->id)->findOrFail((int) $dati['assemblea_id']);
        $tipo = $dati['tipo'] ?? VerbaleModello::VERBALE;
        $modello = new VerbaleModello(['tipo' => $tipo, 'corpo' => $dati['corpo']]);

        // Gli altri tipi usano i segnaposto della convocazione; quelli del destinatario restano scritti, come nel testo generato.
        return response()->json([
            'testo' => $tipo === VerbaleModello::VERBALE ? $verbali->compila($assemblea, $modello) : $convocazione->compila($assemblea, $modello),
            'sconosciuti' => CompilatoreModello::sconosciuti($dati['corpo'], self::chiavi($tipo), ['punti' => true]),
        ], 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * I segnaposto di un tipo di modello, per la guida della pagina: quelli del verbale, o quelli della convocazione
     * (che valgono anche per le due email) con quelli del destinatario.
     *
     * @return array{semplici: array<string, string>, punti: array<string, string>, destinatario: array<string, string>}
     */
    private static function segnaposti(string $tipo): array
    {
        return $tipo === VerbaleModello::VERBALE
            ? ['semplici' => SegnapostiVerbale::ELENCO, 'punti' => SegnapostiVerbale::PUNTI, 'destinatario' => []]
            : ['semplici' => SegnapostiConvocazione::ELENCO, 'punti' => SegnapostiConvocazione::PUNTI, 'destinatario' => SegnapostiConvocazione::DESTINATARIO];
    }

    /** Le chiavi che un modello di quel tipo può usare: le altre sono «sconosciute», e la pagina le segnala. */
    private static function chiavi(string $tipo): array
    {
        return $tipo === VerbaleModello::VERBALE
            ? array_merge(array_keys(SegnapostiVerbale::ELENCO), array_keys(SegnapostiVerbale::PUNTI))
            : SegnapostiConvocazione::chiavi();
    }

    /** Il modello dell'indirizzo: i modelli sono dello studio, non di un condominio; se non c'è, 404. */
    private function assicuraModello(string|int $id): VerbaleModello
    {
        abort_unless(is_numeric($id), 404);

        return VerbaleModello::findOrFail((int) $id);
    }

    private static function rendiPredefinito(VerbaleModello $modello): void
    {
        // Un predefinito per tipo: quello del verbale non toglie quello della convocazione.
        VerbaleModello::where('id', '!=', $modello->id)->where('tipo', $modello->tipo)->where('predefinito', true)->update(['predefinito' => false]);
        $modello->forceFill(['predefinito' => true])->save();
    }
}
