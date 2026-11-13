<?php

namespace App\Http\Controllers\Impostazioni;

use App\Http\Controllers\Controller;
use App\Settings\PrintSettings;
use App\Http\Requests\Settings\UpdatePrintSettingsRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use App\Services\Documenti\ArchivioDocumenti;
use App\Traits\HandleFlashMessages;
use Inertia\Inertia;
use Inertia\Response;

class ImpostazioniStampeController extends Controller
{
    use HandleFlashMessages;

    /**
     * Show the print settings form.
     */
    public function index(PrintSettings $settings): Response
    {
        Gate::authorize('manage', $settings);

        $archivio = app(ArchivioDocumenti::class);

        return Inertia::render('impostazioni/impostazioniStampe', [
            'nota_legale_stampe' => (string) $settings->nota_legale_stampe,
            'firma_stampe_url'   => $settings->firma_stampe_path ? $archivio->urlPubblico($settings->firma_stampe_path) : null,
            // Il limite lo decide il server, non noi: il testo d'aiuto scriveva «max 2MB» a mano in
            // quattro lingue, e su uno spazio web che ne accetta uno prometteva il doppio.
            'limiteFirma'        => \App\Support\LimiteCaricamento::etichetta(2.0),
            // Il fascicolo di bilancio: nome dello studio in fondo alla copertina, riga sopra il titolo, foto.
            'fascicolo_studio'        => (string) $settings->fascicolo_studio,
            'fascicolo_sopratitolo'   => (string) $settings->fascicolo_sopratitolo,
            'fascicolo_copertina_url' => $settings->fascicolo_copertina_path ? $archivio->urlPubblico($settings->fascicolo_copertina_path) : null,
            'limiteCopertina'         => \App\Support\LimiteCaricamento::etichetta(5.0),
        ]);
    }

    /**
     * Update the print settings.
     */
    public function store(UpdatePrintSettingsRequest $request, PrintSettings $settings): RedirectResponse
    {
        Gate::authorize('manage', $settings);

        try {
            $validated = $request->validated();

            $settings->nota_legale_stampe = $validated['nota_legale_stampe'] ?? null;
            
            $archivio = app(ArchivioDocumenti::class);

            if ($request->hasFile('firma_stampe')) {
                $archivio->eliminaPubblico($settings->firma_stampe_path);
                $path = $archivio->salvaPubblico($request->file('firma_stampe'), 'settings/signatures');
                $settings->firma_stampe_path = $path;
            } elseif (!empty($validated['delete_firma_stampe']) && $settings->firma_stampe_path) {
                $archivio->eliminaPubblico($settings->firma_stampe_path);
                $settings->firma_stampe_path = null;
            }
            
            // Il fascicolo di bilancio. I due testi si cambiano solo se arrivano: così chi salva la pagina da una
            // versione vecchia del modulo non li svuota.
            if (array_key_exists('fascicolo_studio', $validated)) {
                $settings->fascicolo_studio = trim((string) $validated['fascicolo_studio']) ?: null;
            }
            if (array_key_exists('fascicolo_sopratitolo', $validated)) {
                $settings->fascicolo_sopratitolo = trim((string) $validated['fascicolo_sopratitolo']) ?: null;
            }
            if ($request->hasFile('fascicolo_copertina')) {
                $archivio->eliminaPubblico($settings->fascicolo_copertina_path);
                $settings->fascicolo_copertina_path = $archivio->salvaPubblico($request->file('fascicolo_copertina'), 'settings/fascicolo');
            } elseif (! empty($validated['delete_fascicolo_copertina']) && $settings->fascicolo_copertina_path) {
                $archivio->eliminaPubblico($settings->fascicolo_copertina_path);
                $settings->fascicolo_copertina_path = null;
            }

            $settings->save();
        } catch (\Exception $e) {
            return redirect()->back()->with(
                $this->flashError(__('impostazioni.error_save_general_settings'))
            );
        }

        return redirect()->back()->with(
            $this->flashSuccess(__('impostazioni.success_save_print_settings'))
        );
    }
}
