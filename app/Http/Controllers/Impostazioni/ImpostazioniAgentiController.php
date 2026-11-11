<?php

namespace App\Http\Controllers\Impostazioni;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\AzioneAgente;
use App\Models\ChiaveAgente;
use App\Models\User;
use App\Settings\GeneralSettings;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Impostazioni → Agenti AI (Web Pro Italia, 09/11/2026): le chiavi con cui un agente usa il gestionale.
 *
 * Una chiave appartiene a un utente amministratore o collaboratore e ne eredita i permessi; può essere di sola
 * consultazione. Il testo si mostra una volta sola, nella risposta alla creazione (mai nella sessione). Le chiavi
 * non si cancellano: si revocano, così il registro delle loro azioni resta leggibile.
 */
class ImpostazioniAgentiController extends Controller
{
    use HandleFlashMessages;

    public function index(GeneralSettings $settings, ?string $chiaveNuova = null): Response
    {
        Gate::authorize('manage', $settings);

        $chiavi = ChiaveAgente::with('user:id,name,email')->latest()->get()->map(fn (ChiaveAgente $c) => [
            'id'            => $c->id,
            'nome'          => $c->nome,
            'inizio'        => $c->inizio,
            'utente'        => $c->user ? ['nome' => $c->user->name, 'email' => $c->user->email] : null,
            'sola_lettura'  => $c->sola_lettura,
            'creata'        => $c->created_at?->toIso8601String(),
            'ultimo_uso'    => $c->ultimo_uso_at?->toIso8601String(),
            'scade'         => $c->scade_at?->toIso8601String(),
            'revocata'      => $c->revocata_at?->toIso8601String(),
            'attiva'        => $c->attiva(),
        ]);

        $azioni = AzioneAgente::with('chiave:id,nome')->latest('created_at')->limit(50)->get()->map(fn (AzioneAgente $a) => [
            'quando' => $a->created_at?->toIso8601String(),
            'chiave' => $a->chiave?->nome,
            'metodo' => $a->metodo,
            'rotta'  => $a->rotta,
            'percorso' => $a->percorso,
            'stato'  => $a->stato,
            'prova'  => $a->prova,
        ]);

        return Inertia::render('impostazioni/impostazioniAgenti', [
            'chiavi'      => $chiavi,
            'azioni'      => $azioni,
            'utenti'      => $this->utentiAmmessi()->map(fn (User $u) => ['id' => $u->id, 'nome' => $u->name, 'email' => $u->email])->values(),
            'mcp'         => [
                'indirizzo' => config('agenti.mcp_url') ? rtrim((string) config('agenti.mcp_url'), '/') : null,
                'kondo'     => rtrim(url('/'), '/'),
            ],
            'chiaveNuova' => $chiaveNuova,
        ]);
    }

    public function store(Request $request, GeneralSettings $settings): Response
    {
        Gate::authorize('manage', $settings);

        $dati = $request->validate([
            'nome'         => ['required', 'string', 'max:100'],
            'user_id'      => ['required', 'integer', Rule::in($this->utentiAmmessi()->pluck('id')->all())],
            'sola_lettura' => ['required', 'boolean'],
            'scade_giorni' => ['nullable', 'integer', Rule::in([30, 90, 365])],
        ]);

        [, $testo] = ChiaveAgente::genera(
            $dati['nome'],
            User::findOrFail($dati['user_id']),
            (bool) $dati['sola_lettura'],
            $request->user(),
            ! empty($dati['scade_giorni']) ? now()->addDays((int) $dati['scade_giorni']) : null,
        );

        return $this->index($settings, $testo);
    }

    public function destroy(int $chiave, GeneralSettings $settings): RedirectResponse
    {
        Gate::authorize('manage', $settings);

        $trovata = ChiaveAgente::findOrFail($chiave);
        $trovata->forceFill(['revocata_at' => now()])->save();

        return redirect()->route('impostazioni.agenti')->with($this->flashSuccess(__('agenti.revocata', ['nome' => $trovata->nome])));
    }

    /**
     * Le persone a nome delle quali può agire una chiave. Fuori gli utenti tecnici dei collegamenti (email
     * `@app.invalid`, per esempio «Portineria (app condòmini)»): non sono persone, e una chiave a loro nome
     * firmerebbe col nome sbagliato tutto quello che fa l'assistente (11/11/2026: era il primo dell'elenco,
     * quindi già scelto). Chi sta creando la chiave è in cima: è il caso normale.
     */
    private function utentiAmmessi()
    {
        $io = auth()->id();

        return User::role([Role::AMMINISTRATORE->value, Role::COLLABORATORE->value])
            ->whereNull('suspended_at')
            ->where('email', 'not like', '%@app.invalid')
            ->orderByRaw('id = ? desc', [$io])
            ->orderBy('name')
            ->get(['id', 'name', 'email']);
    }
}
