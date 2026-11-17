<?php

namespace App\Listeners\Assemblee;

use App\Events\Registrazioni\RegistrazioneConclusa;
use App\Models\Assemblee\Assemblea;
use App\Services\Assemblee\VerbaleService;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Quando la registrazione di un'assemblea è pronta, la sua bozza per punti entra nel verbale (Web Pro Italia, 17/11/2026).
 *
 * La registrazione porta `bozza_punti` = `[{numero, discussione, delibera}]`, scritta dal riassunto della trascrizione. Qui la si passa a
 * `VerbaleService::precompilaDaBozza`, che riempie SOLO i campi ancora vuoti dei punti: quello che la persona ha già scritto resta com'è.
 * È una bozza da rileggere, non il verbale: presenze, voti ed esiti li segna la persona nella pagina dell'assemblea, e il verbale lo
 * approva lei.
 *
 * Non deve mai far fallire l'elaborazione della registrazione: se il verbale è già approvato, o qualcosa va storto, la trascrizione resta
 * e lo si scrive nel registro dell'applicazione.
 */
class PrecompilaVerbaleDallaRegistrazione
{
    public function __construct(private VerbaleService $verbali) {}

    public function handle(RegistrazioneConclusa $evento): void
    {
        $registrazione = $evento->registrazione;
        $bozza = $registrazione->bozza_punti;

        if (! $registrazione->assemblea_id || ! is_array($bozza) || $bozza === []) {
            return;
        }

        try {
            $assemblea = Assemblea::find($registrazione->assemblea_id);

            // Un'assemblea di un altro condominio non si tocca, qualunque cosa dica la registrazione.
            if ($assemblea === null || (int) $assemblea->condominio_id !== (int) $registrazione->condominio_id) {
                return;
            }

            $this->verbali->precompilaDaBozza($assemblea, $bozza, 'registrazione n. '.$registrazione->id);
        } catch (ValidationException $e) {
            Log::info('Assemblee: la bozza della registrazione non entra nel verbale, che è già approvato', [
                'assemblea_id' => $registrazione->assemblea_id, 'registrazione_id' => $registrazione->id,
            ]);
        } catch (Throwable $e) {
            Log::warning('Assemblee: la bozza della registrazione non è entrata nel verbale', [
                'assemblea_id' => $registrazione->assemblea_id, 'registrazione_id' => $registrazione->id, 'errore' => $e->getMessage(),
            ]);
        }
    }
}
