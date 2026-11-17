<?php

namespace App\Http\Controllers\Gestionale\Assemblee;

use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\Assemblee\InviaComunicazioneAssembleaRequest;
use App\Http\Requests\Gestionale\Assemblee\RegistraSpedizioneAssembleaRequest;
use App\Models\Assemblee\Assemblea;
use App\Models\Condominio;
use App\Services\Assemblee\ConvocazioneService;
use App\Services\Assemblee\DestinatariAssemblea;
use App\Services\Assemblee\InviiAssemblea;
use App\Traits\HasCondomini;
use App\Traits\HasEsercizio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Gli invii dell'assemblea (Web Pro Italia, 17/11/2026): l'avviso di convocazione e il verbale approvato. `{cosa}` è
 * `convocazione` o `verbale`. Le risposte sono JSON, come quelle delle lettere: le legge la finestra della pagina, e
 * un agente AI le riceve così come sono.
 *
 * - `anteprima` (lettura): chi riceve che cosa, chi resta senza indirizzo, che cosa è già stato mandato a ognuno e
 *   come, l'oggetto e il testo di partenza, se si può mandare.
 * - `invia` (scrittura verso l'esterno): le email partono davvero. La richiesta porta sempre `conferma: true` e
 *   l'elenco delle persone; senza, risponde 422 e non parte niente. Nell'anteprima di un agente
 *   (`X-Kondo-Prova: 1`) la posta è finta e il database torna com'era.
 * - `spedizione`: registra quello che è stato spedito o consegnato fuori dal programma. Non manda niente.
 * - `annulla`: una spedizione registrata per sbaglio; la riga resta, annullata.
 * - `pdf`: l'avviso com'è partito per email a una persona (il PDF conservato).
 * - `stampaTrasmissione`: la lettera di trasmissione del verbale, per chi non ha un indirizzo email.
 */
class InviiAssembleaController extends Controller
{
    use GuardieAssemblea, HasCondomini, HasEsercizio;

    public function __construct(
        private readonly InviiAssemblea $invii,
        private readonly ConvocazioneService $convocazione,
    ) {}

    public function anteprima(Request $request, Condominio $condominio, Assemblea $assemblea, string $cosa): JsonResponse
    {
        $this->assicuraAssemblea($condominio, $assemblea);
        $this->assicuraCosa($cosa);
        $dati = $this->invii->anteprima($assemblea, $cosa, $request->only(['indirizzi', 'chi']));
        if ($cosa === InviiAssemblea::CONVOCAZIONE) {
            $dati['avvisi'] = $this->convocazione->avvisi($assemblea);
        }

        return self::json($dati);
    }

    /** L'invio per email: con la conferma esplicita e l'elenco delle persone (`InviaComunicazioneAssembleaRequest`). */
    public function invia(InviaComunicazioneAssembleaRequest $request, Condominio $condominio, Assemblea $assemblea, string $cosa): JsonResponse
    {
        $this->assicuraAssemblea($condominio, $assemblea);
        $this->assicuraCosa($cosa);

        return self::json($this->invii->invia($assemblea, $cosa, $request->validated(), $request->user()));
    }

    /** «Registra la spedizione»: raccomandata, PEC mandata fuori dal programma, consegna a mano… */
    public function spedizione(RegistraSpedizioneAssembleaRequest $request, Condominio $condominio, Assemblea $assemblea, string $cosa): JsonResponse
    {
        $this->assicuraAssemblea($condominio, $assemblea);
        $this->assicuraCosa($cosa);
        $dati = $request->validated();

        return self::json($this->invii->registraSpedizione($assemblea, $cosa, $dati['persone'], $dati['canale'], $dati['data'],
            (array) ($dati['numeri'] ?? []), $dati['nota'] ?? null, $request->user()));
    }

    /** Annulla una spedizione registrata per sbaglio. `{invio}` arriva grezzo: che sia di questa assemblea lo controlla il servizio (404). */
    public function annulla(Request $request, Condominio $condominio, Assemblea $assemblea, string|int $invio): JsonResponse
    {
        $this->assicuraAssemblea($condominio, $assemblea);
        $this->invii->annulla($assemblea, $invio, $request->user());

        return self::json(['annullata' => true]);
    }

    /** L'avviso di convocazione com'è partito per email a una persona: il PDF conservato della riga. */
    public function pdf(Condominio $condominio, Assemblea $assemblea, string|int $invio)
    {
        $this->assicuraAssemblea($condominio, $assemblea);
        $conservato = $this->invii->pdfConservato($this->invii->rigaDellAssemblea($assemblea, $invio));
        abort_if($conservato === null, 404);

        return response($conservato['pdf'])
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="'.$conservato['nome'].'"');
    }

    /**
     * La lettera di trasmissione del verbale, da stampare: per le persone date (`persone[]`) o, senza, per chi fra gli
     * assenti (`chi=tutti`: fra tutti) non ha un indirizzo email. Solo con il verbale approvato.
     */
    public function stampaTrasmissione(Request $request, Condominio $condominio, Assemblea $assemblea)
    {
        $this->assicuraAssemblea($condominio, $assemblea);
        $persone = $request->has('persone') ? array_values(array_filter((array) $request->query('persone'), 'is_numeric')) : null;
        $chi = $request->query('chi') === DestinatariAssemblea::TUTTI ? DestinatariAssemblea::TUTTI : DestinatariAssemblea::ASSENTI;

        try {
            $pdf = $this->invii->pdfDiTrasmissione($assemblea, $persone, $chi);
        } catch (ValidationException $e) {
            return response('Lettere non stampate. '.collect($e->errors())->flatten()->first(), 422)->header('Content-Type', 'text/plain; charset=UTF-8');
        }

        return response($pdf)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="'.$this->convocazione->nomeFile($assemblea, 'trasmissione-verbale').'"');
    }

    private function assicuraCosa(string $cosa): void
    {
        abort_unless(array_key_exists($cosa, InviiAssemblea::TIPI), 404);
    }

    private static function json(array $dati): JsonResponse
    {
        return response()->json($dati, 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
