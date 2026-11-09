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

/** Impostazioni → Aspetto (Web Pro Italia, 09/11/2026): colore principale e angoli dell'installazione. */
class ImpostazioniAspettoController extends Controller
{
    use HandleFlashMessages;

    public function index(AspettoSettings $aspetto, GeneralSettings $settings): Response
    {
        Gate::authorize('manage', $settings);

        return Inertia::render('impostazioni/impostazioniAspetto', [
            'aspetto' => ['colore' => $aspetto->colore, 'angoli' => $aspetto->angoli],
            'scelte'  => Tema::scelte(),
        ]);
    }

    public function store(Request $request, AspettoSettings $aspetto, GeneralSettings $settings): RedirectResponse
    {
        Gate::authorize('manage', $settings);

        $dati = $request->validate([
            'colore' => ['required', 'string', Rule::in(array_keys(Tema::COLORI))],
            'angoli' => ['required', 'string', Rule::in(array_keys(Tema::ANGOLI))],
        ]);

        $aspetto->colore = $dati['colore'];
        $aspetto->angoli = $dati['angoli'];
        $aspetto->save();

        return back()->with($this->flashSuccess(__('impostazioni.aspetto.salvato')));
    }
}
