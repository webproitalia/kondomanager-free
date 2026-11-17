<?php

namespace App\Http\Controllers\Gestionale\Assemblee;

use App\Enums\Permission;
use App\Helpers\DateHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\Assemblee\SalvaAssembleaRequest;
use App\Http\Requests\Gestionale\Assemblee\SvolgimentoAssembleaRequest;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\AssembleaPunto;
use App\Models\Assemblee\AssembleaVoto;
use App\Models\Condominio;
use App\Models\Tabella;
use App\Services\Assemblee\AssembleaService;
use App\Services\Assemblee\SegnapostiVerbale;
use App\Services\Assemblee\VerbaleService;
use App\Traits\HandleFlashMessages;
use App\Traits\HasCondomini;
use App\Traits\HasEsercizio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Le assemblee del condominio (Web Pro Italia, 17/11/2026): l'elenco, la nuova assemblea con l'ordine del giorno, la
 * pagina dell'assemblea (presenze e voti, da usare in sala), lo svolgimento (stato, convocazione, presidente,
 * segretario, ore, registrazione) e i dati interi in una lettura sola per un agente AI (`dati`).
 */
class AssembleaController extends Controller
{
    use GuardieAssemblea, HandleFlashMessages, HasCondomini, HasEsercizio;

    public function __construct(
        private readonly AssembleaService $servizio,
        private readonly VerbaleService $verbali,
    ) {}

    public function index(Request $request, Condominio $condominio): Response
    {
        $assemblee = Assemblea::where('condominio_id', $condominio->id)
            ->with(['verbale:id,assemblea_id,stato,versione', 'tabella:id,nome'])
            ->withCount(['punti', 'presenze'])
            ->orderByDesc('data_prima_convocazione')->orderByDesc('id')
            ->get()
            ->map(fn (Assemblea $a) => [
                'id' => (int) $a->id,
                'titolo' => $a->titolo,
                'tipo' => $a->tipo,
                'stato' => $a->stato,
                'data_prima_convocazione' => $a->data_prima_convocazione?->format('Y-m-d H:i'),
                'data_seconda_convocazione' => $a->data_seconda_convocazione?->format('Y-m-d H:i'),
                'convocazione_tenuta' => $a->convocazione_tenuta,
                'luogo' => $a->luogo,
                'punti' => (int) $a->punti_count,
                'presenze' => (int) $a->presenze_count,
                'verbale' => $a->verbale ? ['stato' => $a->verbale->stato, 'versione' => (int) $a->verbale->versione] : null,
            ]);

        return Inertia::render('gestionale/assemblee/AssembleeList', $this->datiDellaTestata($condominio) + [
            'assemblee' => $assemblee,
            'puo' => self::permessi($request),
        ]);
    }

    public function create(Request $request, Condominio $condominio): Response
    {
        return Inertia::render('gestionale/assemblee/AssembleaForm', $this->datiDelModulo($condominio) + [
            'assemblea' => null,
            'punti' => [],
        ]);
    }

    public function store(SalvaAssembleaRequest $request, Condominio $condominio): RedirectResponse
    {
        $dati = $request->validated();

        $assemblea = DB::transaction(function () use ($dati, $condominio, $request) {
            $assemblea = Assemblea::create(array_merge(self::campiAssemblea($dati), [
                'condominio_id' => $condominio->id,
                'stato' => 'bozza',
                'created_by' => $request->user()?->id,
            ]));
            $this->salvaPunti($assemblea, $dati['punti'] ?? []);

            return $assemblea;
        });

        return to_route('admin.gestionale.assemblee.show', ['condominio' => $condominio->id, 'assemblea' => $assemblea->id])
            ->with($this->flashSuccess('Assemblea creata. Quando è il momento, prepara l\'elenco degli aventi diritto dalle presenze.'));
    }

    public function show(Request $request, Condominio $condominio, Assemblea $assemblea): Response
    {
        $this->assicuraAssemblea($condominio, $assemblea);

        return Inertia::render('gestionale/assemblee/AssembleaShow', $this->datiDellaTestata($condominio) + [
            'fotografia' => $this->servizio->fotografia($assemblea),
            // Il delegato si cerca in rubrica: le persone del condominio (i delegati di solito sono condòmini, familiari, inquilini).
            'persone' => $condominio->anagrafiche()->orderBy('nome')->get(['anagrafiche.id', 'anagrafiche.nome'])
                ->map(fn ($p) => ['id' => (int) $p->id, 'nome' => (string) $p->nome])->values(),
            'maggioranze' => AssembleaService::maggioranze(),
            'puo' => self::permessi($request),
            // Le registrazioni di questa assemblea (Web Pro Italia, 17/11/2026): la pagina ha il registratore e dice a che punto sono.
            'registrazioni' => app(\App\Services\Registrazioni\Assemblee::class)->registrazioniDi((int) $assemblea->id),
        ]);
    }

    public function edit(Request $request, Condominio $condominio, Assemblea $assemblea): Response
    {
        $this->assicuraAssemblea($condominio, $assemblea);

        return Inertia::render('gestionale/assemblee/AssembleaForm', $this->datiDelModulo($condominio) + [
            'assemblea' => $this->servizio->datiAssemblea($assemblea->load(['tabella:id,nome', 'esercizio:id,nome', 'verbale'])),
            'punti' => $assemblea->punti()->withCount('voti')->get()->map(fn (AssembleaPunto $p) => [
                'id' => (int) $p->id,
                'titolo' => $p->titolo,
                'descrizione' => $p->descrizione,
                'maggioranza' => $p->maggioranza,
                'voti' => (int) $p->voti_count,
            ])->values(),
        ]);
    }

    public function update(SalvaAssembleaRequest $request, Condominio $condominio, Assemblea $assemblea): RedirectResponse
    {
        $this->assicuraAssemblea($condominio, $assemblea);
        $this->verbali->assicuraModificabile($assemblea);
        $dati = $request->validated();

        DB::transaction(function () use ($assemblea, $dati) {
            $assemblea->fill(self::campiAssemblea($dati, $dati))->save();
            if (array_key_exists('punti', $dati)) {
                $this->salvaPunti($assemblea, $dati['punti']);
            }
        });

        return to_route('admin.gestionale.assemblee.show', ['condominio' => $condominio->id, 'assemblea' => $assemblea->id])
            ->with($this->flashSuccess('Assemblea salvata.'));
    }

    public function destroy(Request $request, Condominio $condominio, Assemblea $assemblea): RedirectResponse
    {
        $this->assicuraAssemblea($condominio, $assemblea);
        $this->verbali->assicuraModificabile($assemblea);

        $voti = AssembleaVoto::whereIn('assemblea_punto_id', $assemblea->punti()->pluck('id'))->count();
        if ($voti > 0 || $assemblea->verbale()->whereNotNull('documento_id')->exists()) {
            return back()->with($this->flashError('Questa assemblea ha già dei voti o un verbale approvato: non si elimina. Se non si è tenuta, lasciala e scrivilo nelle note.'));
        }

        $assemblea->delete();

        return to_route('admin.gestionale.assemblee.index', ['condominio' => $condominio->id])
            ->with($this->flashSuccess('Assemblea eliminata.'));
    }

    /**
     * Tutto dell'assemblea in una risposta sola, per un agente AI: l'assemblea, i punti con i voti e i conteggi, le
     * presenze, la costituzione, gli avvisi, il verbale, i segnaposto dei modelli e le funzioni per scrivere.
     */
    public function dati(Condominio $condominio, Assemblea $assemblea): JsonResponse
    {
        $this->assicuraAssemblea($condominio, $assemblea);

        return response()->json($this->servizio->fotografia($assemblea) + [
            'segnaposti' => ['semplici' => SegnapostiVerbale::ELENCO, 'punti' => SegnapostiVerbale::PUNTI],
            'funzioni' => self::funzioniPerGliAgenti(),
        ], 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Stato, convocazione tenuta, presidente, segretario, ore, registrazione. */
    public function svolgimento(SvolgimentoAssembleaRequest $request, Condominio $condominio, Assemblea $assemblea): RedirectResponse
    {
        $this->assicuraAssemblea($condominio, $assemblea);
        $this->verbali->assicuraModificabile($assemblea);
        $dati = $request->validated();
        $adesso = DateHelper::adessoUtente()->format('H:i');

        $campi = array_intersect_key($dati, array_flip(['stato', 'convocazione_tenuta', 'presidente', 'segretario', 'ora_inizio', 'ora_fine', 'registrata']));
        foreach (['presidente', 'segretario'] as $nome) {
            if (array_key_exists($nome, $campi)) {
                $campi[$nome] = filled($campi[$nome]) ? trim((string) $campi[$nome]) : null;
            }
        }
        if (($dati['adesso'] ?? null) === 'inizia') {
            $campi['stato'] = 'in_corso';
            $campi['ora_inizio'] = $campi['ora_inizio'] ?? ($assemblea->ora_inizio ? substr($assemblea->ora_inizio, 0, 5) : $adesso);
        }
        if (($dati['adesso'] ?? null) === 'chiudi') {
            $campi['stato'] = 'chiusa';
            $campi['ora_fine'] = $campi['ora_fine'] ?? ($assemblea->ora_fine ? substr($assemblea->ora_fine, 0, 5) : $adesso);
        }

        $assemblea->fill($campi)->save();

        $messaggio = match ($dati['adesso'] ?? null) {
            'inizia' => 'Assemblea iniziata alle '.substr((string) $assemblea->ora_inizio, 0, 5).'.',
            'chiudi' => 'Assemblea chiusa alle '.substr((string) $assemblea->ora_fine, 0, 5).'. Ora si può scrivere il verbale.',
            default => 'Salvato.',
        };

        return back(fallback: route('admin.gestionale.assemblee.show', ['condominio' => $condominio->id, 'assemblea' => $assemblea->id]))
            ->with($this->flashSuccess($messaggio));
    }

    // --- Di supporto ---------------------------------------------------------------------------------------------

    /** I campi dell'assemblea dalla richiesta; in modifica solo quelli mandati. */
    private static function campiAssemblea(array $dati, ?array $soloQuesti = null): array
    {
        $campi = ['tipo', 'titolo', 'esercizio_id', 'data_prima_convocazione', 'data_seconda_convocazione', 'data_avviso', 'luogo', 'modalita', 'tabella_id', 'note'];
        $fuori = [];
        foreach ($campi as $c) {
            if ($soloQuesti !== null && ! array_key_exists($c, $soloQuesti)) {
                continue;
            }
            $valore = $dati[$c] ?? null;
            $fuori[$c] = is_string($valore) ? (trim($valore) === '' ? null : trim($valore)) : $valore;
        }

        return $fuori;
    }

    /**
     * L'ordine del giorno, nell'ordine in cui arriva: i punti con `id` si correggono e si rinumerano, quelli senza si
     * aggiungono, quelli che mancano si tolgono. Un punto con dei voti non si toglie.
     */
    private function salvaPunti(Assemblea $assemblea, array $punti): void
    {
        $esistenti = $assemblea->punti()->withCount('voti')->get()->keyBy('id');
        $tenuti = [];

        foreach (array_values($punti) as $i => $p) {
            $id = isset($p['id']) ? (int) $p['id'] : null;
            if ($id !== null && ! $esistenti->has($id)) {
                throw ValidationException::withMessages(["punti.{$i}.id" => 'Questo punto non è dell\'assemblea.']);
            }
            $tenuti[] = $id;
        }

        // `reject` e non `except`: sulla collezione di Eloquent `except()` rinumera le chiavi, e gli id si perdono.
        $daTogliere = $esistenti->reject(fn (AssembleaPunto $p) => in_array((int) $p->id, $tenuti, true));
        $conVoti = $daTogliere->filter(fn (AssembleaPunto $p) => $p->voti_count > 0);
        if ($conVoti->isNotEmpty()) {
            throw ValidationException::withMessages(['punti' => 'Il punto «'.$conVoti->first()->titolo.'» ha già dei voti: per toglierlo azzera prima i voti dalla pagina dell\'assemblea.']);
        }
        AssembleaPunto::whereIn('id', $daTogliere->pluck('id'))->delete();

        foreach (array_values($punti) as $i => $p) {
            $valori = [
                'numero' => $i + 1,
                'titolo' => trim((string) $p['titolo']),
                'descrizione' => filled($p['descrizione'] ?? null) ? trim((string) $p['descrizione']) : null,
                'maggioranza' => $p['maggioranza'],
            ];
            $id = isset($p['id']) ? (int) $p['id'] : null;
            if ($id !== null) {
                $esistenti[$id]->fill($valori)->save();
            } else {
                $assemblea->punti()->create($valori);
            }
        }
    }

    private function datiDelModulo(Condominio $condominio): array
    {
        $tabelle = Tabella::where('condominio_id', $condominio->id)->orderBy('nome')->get(['id', 'nome', 'tipo', 'quota']);
        $esercizio = $this->getEsercizioCorrente($condominio);

        return $this->datiDellaTestata($condominio) + [
            'tabelle' => $tabelle->map(fn (Tabella $t) => ['id' => (int) $t->id, 'nome' => $t->nome, 'quota' => $t->quota])->values(),
            'esercizi' => $condominio->esercizi()->orderByDesc('data_inizio')->get(['id', 'nome', 'stato'])->map(fn ($e) => ['id' => (int) $e->id, 'nome' => $e->nome, 'stato' => $e->stato])->values(),
            'maggioranze' => AssembleaService::maggioranze(),
            'proposta' => [
                'tabella_id' => self::tabellaProposta($tabelle),
                'esercizio_id' => $esercizio?->id,
            ],
        ];
    }

    /**
     * La tabella che si propone per i conteggi: di norma la generale di proprietà. Si cerca per nome («generale»,
     * «proprietà») fra le tabelle in millesimi; se non c'è, la prima in millesimi. La persona la cambia.
     */
    private static function tabellaProposta($tabelle): ?int
    {
        $millesimi = $tabelle->where('quota', 'millesimi');
        $perNome = $millesimi->first(fn (Tabella $t) => preg_match('/general|propriet/iu', (string) $t->nome) === 1);

        return ($perNome ?? $millesimi->first() ?? $tabelle->first())?->id;
    }

    /** Che cosa può fare chi guarda la pagina. Il ruolo amministratore può tutto, come sulle rotte. */
    public static function permessi(Request $request): array
    {
        $utente = $request->user();
        $puo = fn (Permission $p) => $utente !== null && ($utente->hasRole(\App\Enums\Role::AMMINISTRATORE->value) || rescue(fn () => $utente->hasPermissionTo($p->value), false, false));

        return [
            'gestire' => $puo(Permission::MANAGE_ASSEMBLEE),
            'approvare' => $puo(Permission::APPROVE_VERBALI),
        ];
    }

    /** Le funzioni con cui un agente scrive l'assemblea e il verbale: i nomi delle rotte, che il catalogo descrive per esteso. */
    public static function funzioniPerGliAgenti(): array
    {
        return [
            'leggere' => 'admin.gestionale.assemblee.dati',
            'svolgimento' => 'admin.gestionale.assemblee.svolgimento (PUT: stato, convocazione_tenuta, presidente, segretario, ora_inizio, ora_fine, registrata)',
            'presenza' => 'admin.gestionale.assemblee.presenze.update (PUT: tipo, delegato_anagrafica_id, delegato_nome, anagrafica_id, ora_arrivo, ora_uscita, segna)',
            'voti' => 'admin.gestionale.assemblee.punti.voti (PUT: tutti, voti[{presenza_id, voto}], azzera)',
            'punto' => 'admin.gestionale.assemblee.punti.update (PUT: discussione, delibera, esito)',
            'bozza_dei_punti' => 'admin.gestionale.assemblee.verbale.precompila (POST: punti[{numero, discussione, delibera}], fonte): riempie solo i campi vuoti',
            'genera_verbale' => 'admin.gestionale.assemblee.verbale.genera (POST: modello_id, sovrascrivi)',
            'scrivi_verbale' => 'admin.gestionale.assemblee.verbale.update (PUT: testo, intestazione, apertura, chiusura, punti[{id, discussione, delibera}])',
            'manda_in_approvazione' => 'admin.gestionale.assemblee.verbale.invia (POST)',
            'modelli' => 'admin.gestionale.assemblee.modelli.store / .update (nome, descrizione, corpo, predefinito)',
            'approvare' => 'solo una persona, dal gestionale: agli agenti è vietato',
        ];
    }
}
