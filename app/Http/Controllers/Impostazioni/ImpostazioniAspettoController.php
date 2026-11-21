<?php

namespace App\Http\Controllers\Impostazioni;

use App\Http\Controllers\Controller;
use App\Settings\AspettoSettings;
use App\Settings\GeneralSettings;
use App\Support\Tema;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Impostazioni → Aspetto (Web Pro Italia, 09/11/2026): colore principale e angoli dell'installazione; dal 18/11/2026 anche dove sta il menu e dove si aprono le schede; dal 21/11/2026 lo stile dell'area di lavoro. */
class ImpostazioniAspettoController extends Controller
{
    use HandleFlashMessages;

    public function index(AspettoSettings $aspetto, GeneralSettings $settings): Response
    {
        Gate::authorize('manage', $settings);

        return Inertia::render('impostazioni/impostazioniAspetto', [
            // Le schede: la scelta salvata, anche con il menu in alto (dove non vale): tornando al menu laterale è quella.
            'aspetto' => ['colore' => $aspetto->colore, 'angoli' => $aspetto->angoli, 'menu' => Tema::menu(),
                'schede' => isset(Tema::SCHEDE[$aspetto->schede]) ? $aspetto->schede : 'lato',
                // Lo stile: come le schede, la scelta salvata anche con il menu in alto (dove non vale).
                'stile' => isset(Tema::STILE[$aspetto->stile]) ? $aspetto->stile : 'professionale'],
            'scelte'  => Tema::scelte(),
        ]);
    }

    public function store(Request $request, AspettoSettings $aspetto, GeneralSettings $settings): RedirectResponse
    {
        Gate::authorize('manage', $settings);

        $dati = $request->validate([
            'colore' => ['required', 'string', Rule::in(array_keys(Tema::COLORI))],
            'angoli' => ['required', 'string', Rule::in(array_keys(Tema::ANGOLI))],
            // Facoltativo: una pagina aperta prima dell'aggiornamento salva colore e angoli e non tocca il menu.
            'menu'   => ['sometimes', 'required', 'string', Rule::in(array_keys(Tema::MENU))],
            'schede' => ['sometimes', 'required', 'string', Rule::in(array_keys(Tema::SCHEDE))],
            'stile'  => ['sometimes', 'required', 'string', Rule::in(array_keys(Tema::STILE))],
        ]);

        $aspetto->colore = $dati['colore'];
        $aspetto->angoli = $dati['angoli'];
        if (isset($dati['menu'])) {
            $aspetto->menu = $dati['menu'];
        }
        if (isset($dati['schede'])) {
            $aspetto->schede = $dati['schede'];
        }
        if (isset($dati['stile'])) {
            $aspetto->stile = $dati['stile'];
        }
        $aspetto->save();

        return back()->with($this->flashSuccess(__('impostazioni.aspetto.salvato')));
    }
}
