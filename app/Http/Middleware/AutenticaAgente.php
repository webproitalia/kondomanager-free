<?php

namespace App\Http\Middleware;

use App\Models\AzioneAgente;
use App\Models\ChiaveAgente;
use App\Support\Agenti\DiscoInProva;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Support\Header;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * AGENTI AI (Web Pro Italia, 09/11/2026): un agente usa il gestionale con una chiave al posto del login.
 *
 * L'agente chiama gli STESSI indirizzi del browser (`GET /admin/gestionale/5/fatture`, `POST .../movimenti-rate`)
 * con l'intestazione `Authorization: Bearer kag_…`. Questo middleware, in testa al gruppo `web`:
 *
 *  1. riconosce la chiave (solo il prefisso `kag_`: ogni altra richiesta passa intatta) e mette nella guardia `web`
 *     l'utente a cui appartiene. Da lì in poi valgono i suoi ruoli e permessi, i middleware delle rotte, i
 *     FormRequest e le policy, come per una persona. Nessuna logica del gestionale è duplicata qui.
 *  2. usa una sessione in memoria (driver `array`): niente righe in `sessions`, niente cookie che restano. I cookie
 *     della richiesta si scartano, così una chiamata con chiave non cavalca mai la sessione di un browser.
 *  3. passa la protezione dei moduli (CSRF) come una richiesta della stessa origine (`Sec-Fetch-Site: same-origin`,
 *     che PreventRequestForgery di Laravel 13 accetta): la chiave viaggia in un'intestazione, che una pagina di un
 *     altro sito non può mandare, e senza cookie non c'è niente da contraffare.
 *  4. chiede le pagine come JSON (Inertia) e risponde con una busta sola, leggibile da un agente: `ok`, `stato`,
 *     `dati` (le props della pagina, senza i dati condivisi del layout), `avvisi`, `errori`, `rimanda_a`.
 *     I file (PDF, XML, Excel) passano come sono.
 *  5. con `X-Kondo-Prova: 1` esegue una scrittura dentro una transazione che poi annulla, con posta, notifiche e
 *     code finte: dice se andrebbe a buon fine e con quali avvisi, senza lasciare traccia nel database.
 *  6. registra in `agenti_azioni` ogni scrittura e ogni richiesta rifiutata, letture comprese (metodo, percorso,
 *     rotta, esito e, se è un rifiuto, la frase del rifiuto e i nomi dei campi sbagliati; mai i dati inviati).
 *
 * Cosa un agente NON può fare, qualunque sia il suo utente: accesso e password, 2FA, profilo, utenti, ruoli e
 * permessi, impostazioni (chiavi degli agenti comprese), backup e ripristino, aggiornamenti, installazione, log,
 * cancellazione di un condominio o di un esercizio, e ogni rotta che chiede di ridigitare la password.
 * Un'eccezione, dal 15/11/2026: eliminare un condominio con tutto il suo contenuto (`condomini.elimina-tutto`), ma
 * solo con una chiave a cui l'amministratore ha acceso il permesso. Vedi `CON_PERMESSO`.
 */
class AutenticaAgente
{
    public const ATTRIBUTO = 'kondo.agente';

    /** Rotte vietate agli agenti (nome esatto o prefisso con «*»). */
    public const VIETATE = [
        'login', 'logout', 'register', 'home', 'salute', 'appearance',
        'password.*', 'verification.*', 'two-factor.*', 'profile.*', 'invito.*', 'install.*',
        'ripristino.*', 'system.*', 'impostazioni', 'impostazioni.*', 'utenti.*', 'users.*', 'ruoli.*',
        'logs.*', 'storage.*', 'livewire.*', 'default-livewire.*', 'generated::*',
        'admin.settings.mail.*', 'condomini.destroy', 'condomini.dimostrativo.elimina',
        'admin.gestionale.esercizi.destroy',
    ];

    /** Dati che il layout condivide su ogni pagina: per un agente sono rumore, si tolgono dalla busta. */
    private const CONDIVISI = [
        'name', 'version', 'is_demo', 'funzioni', 'locale', 'paginazione', 'auth', 'flash', 'csrf_token',
        'back_url', 'inbox_count', 'system_update', 'errors', 'ziggy', 'sidebarOpen',
    ];

    private const AVVISI_FLASH = [
        'message', 'success', 'error', 'warning', 'info', 'scoperti_warning', 'destinatari_warning',
        'suggerimento_crediti', 'esito_emissione', 'passaggio_registrato',
    ];

    /**
     * Gli avvisi che sono un cancello: la scrittura si è fermata e aspetta una presa d'atto. L'agente li riceve con una frase che dice
     * che cosa manca e come procedere (la pagina li mostra in un riquadro, che l'agente non vede), e la risposta non è `ok`.
     */
    private const CANCELLI = ['scoperti_warning', 'destinatari_warning'];

    /**
     * Rotte che un agente può usare solo se la sua chiave ha un permesso in più, acceso dall'amministratore in
     * Impostazioni → Agenti AI (15/11/2026). `condomini.destroy` resta vietata: questa è l'altra porta, quella che
     * chiede di riscrivere il nome e salva una copia prima di cancellare.
     */
    public const CON_PERMESSO = [
        'condomini.elimina-tutto' => 'puo_eliminare',
    ];

    public const RICHIESTE_AL_MINUTO = 240;

    public function handle(Request $request, Closure $next): Response
    {
        $testo = (string) $request->bearerToken();

        if (! str_starts_with($testo, ChiaveAgente::PREFISSO)) {
            return $next($request);
        }

        $inizio = microtime(true);
        $chiave = ChiaveAgente::trova($testo);

        if (! $chiave || ! $chiave->attiva()) {
            return $this->errore(401, 'Chiave non valida, scaduta o revocata.');
        }

        $utente = $chiave->user;

        if (! $utente || $utente->suspended()) {
            return $this->errore(403, 'L\'utente di questa chiave non è attivo.');
        }

        $rotta = $request->route();
        $nome = $rotta?->getName() ?? '';

        $scrive = ! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true);
        $prova = $scrive && filter_var($request->header('X-Kondo-Prova', false), FILTER_VALIDATE_BOOLEAN);

        // Un rifiuto dato qui, prima ancora di entrare nel gestionale, finisce nel registro come gli altri.
        $rifiuta = function (int $stato, string $messaggio) use ($chiave, $request, $nome, $prova, $inizio): JsonResponse {
            $this->registra($chiave, $request, $nome, $stato, $prova, $inizio, $messaggio);

            return $this->errore($stato, $messaggio, ['rotta' => $nome]);
        };

        if ($this->vietata($nome) || in_array('password.recente', $rotta?->gatherMiddleware() ?? [], true)) {
            return $rifiuta(403, 'Questa funzione non è disponibile agli agenti: va fatta da una persona nel gestionale.');
        }

        if ($scrive && $chiave->sola_lettura) {
            return $rifiuta(403, 'Questa chiave è di sola lettura: può consultare, non modificare.');
        }

        $permesso = self::CON_PERMESSO[$nome] ?? null;

        if ($permesso !== null && ! $chiave->{$permesso}) {
            return $rifiuta(403, 'Questa chiave non ha il permesso di eliminare un condominio. Lo accende l\'amministratore in Impostazioni → Agenti AI: dillo alla persona per cui lavori.');
        }

        if (! $this->entroIlLimite($chiave)) {
            return $rifiuta(429, 'Troppe richieste in un minuto: riprova tra poco.');
        }

        // Sessione in memoria e niente cookie del browser: vedi il punto 2.
        config(['session.driver' => 'array']);
        $request->cookies->replace([]);
        $request->attributes->set(self::ATTRIBUTO, $chiave);

        Auth::shouldUse('web');
        Auth::guard('web')->setUser($utente);

        // Le pagine come JSON: vedi il punto 4. La versione degli asset è quella che il middleware di Inertia
        // si aspetta, altrimenti una GET risponderebbe 409 «ricarica la pagina».
        $request->headers->set(Header::INERTIA, 'true');
        $request->headers->set(Header::VERSION, (string) app(HandleInertiaRequests::class)->version($request));
        $request->headers->set('Accept', 'application/json');
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');
        $request->headers->set('Sec-Fetch-Site', 'same-origin');

        if ($prova) {
            $risposta = $this->inProva($request, $next);
        } else {
            $risposta = $next($request);
        }

        $busta = $this->busta($request, $risposta, $prova);

        if ($scrive || $busta->getStatusCode() >= 400) {
            [$motivo, $campi] = $this->motivoDa($busta);
            $this->registra($chiave, $request, $nome, $busta->getStatusCode(), $prova, $inizio, $motivo, $campi);
        }

        if (! $chiave->ultimo_uso_at || $chiave->ultimo_uso_at->lt(now()->subMinute())) {
            $chiave->forceFill(['ultimo_uso_at' => now()])->saveQuietly();
        }

        return $busta;
    }

    public static function vietata(string $nome): bool
    {
        if ($nome === '') {
            return false;
        }

        foreach (self::VIETATE as $regola) {
            if ($regola === $nome || (str_ends_with($regola, '*') && str_starts_with($nome, substr($regola, 0, -1)))) {
                return true;
            }
        }

        return false;
    }

    private function inProva(Request $request, Closure $next): Response
    {
        Mail::fake();
        Notification::fake();
        Queue::fake();
        $dischi = $this->dischiInProva();

        // Si torna al livello di partenza, non a zero: in produzione è la stessa cosa (si parte da zero), nei test
        // la richiesta gira già dentro la transazione del test, che non va annullata insieme all'anteprima.
        $livello = DB::transactionLevel();
        DB::beginTransaction();

        try {
            return $next($request);
        } finally {
            while (DB::transactionLevel() > $livello) {
                DB::rollBack();
            }
            foreach ($dischi as $nome) {
                Storage::forgetDisk($nome);
            }
        }
    }

    /**
     * In anteprima i file veri non si toccano: ogni disco configurato viene sostituito, per questa richiesta, da uno
     * che non cancella, non sposta e non sovrascrive (vedi `DiscoInProva`). Il rollback del database da solo non
     * basta: un documento «eliminato» in anteprima perdeva il file e teneva la riga.
     *
     * @return list<string> i dischi sostituiti, da rimettere a posto alla fine
     */
    private function dischiInProva(): array
    {
        $sostituiti = [];

        foreach (array_keys((array) config('filesystems.disks', [])) as $nome) {
            try {
                $vero = Storage::disk($nome);

                if ($vero instanceof \Illuminate\Filesystem\FilesystemAdapter && ! $vero instanceof DiscoInProva) {
                    Storage::set($nome, new DiscoInProva($vero->getDriver(), $vero->getAdapter(), $vero->getConfig()));
                    $sostituiti[] = $nome;
                }
            } catch (Throwable) {
                // Un disco configurato ma non raggiungibile (S3 senza credenziali) non serve a questa richiesta.
            }
        }

        return $sostituiti;
    }

    /** La risposta del gestionale, tradotta in una busta sola per l'agente. I file passano come sono. */
    private function busta(Request $request, Response $risposta, bool $prova): Response
    {
        $stato = $risposta->getStatusCode();
        $tipo = (string) $risposta->headers->get('Content-Type', '');
        $base = ['ok' => $stato < 400, 'stato' => $stato, 'prova' => $prova];

        // Pagina Inertia (una GET, o una scrittura che risponde con una pagina).
        if ($risposta->headers->get(Header::INERTIA) === 'true' && str_contains($tipo, 'json')) {
            $pagina = json_decode((string) $risposta->getContent(), true) ?: [];
            $props = $pagina['props'] ?? [];

            $corpo = array_replace($base, [
                'tipo'   => 'pagina',
                'pagina' => $pagina['component'] ?? null,
                'url'    => $pagina['url'] ?? null,
                'avvisi' => $this->avvisiDa($props['flash'] ?? []),
                'errori' => ($props['errors'] ?? []) ?: null,
                'dati'   => array_diff_key($props, array_flip(self::CONDIVISI)),
            ]);

            if (! empty($pagina['deferredProps'])) {
                $corpo['differite'] = $pagina['deferredProps'];
            }

            return $this->json($corpo, $stato);
        }

        // Rimando: l'esito sta negli avvisi della sessione (il «salvato» del modulo) e negli errori, se ce ne sono.
        if ($risposta->isRedirection() || $stato === 409 && $risposta->headers->has(Header::LOCATION)) {
            $verso = $risposta->headers->get('Location') ?: $risposta->headers->get(Header::LOCATION);
            $sessione = $request->hasSession() ? $request->session() : null;
            $avvisi = [];

            foreach (self::AVVISI_FLASH as $chiave) {
                $valore = $sessione?->get($chiave);
                if ($valore !== null && $valore !== '' && $valore !== []) {
                    $avvisi = array_merge($avvisi, $this->avvisiDa([$chiave => $valore]));
                }
            }

            $errori = $sessione?->get('errors');
            $errori = $errori instanceof \Illuminate\Support\ViewErrorBag ? $errori->getBag('default')->toArray() : ($errori['default']['messages'] ?? null);
            // I due cancelli del piano rate (quote scoperte, destinatari cambiati) fermano la scrittura e chiedono una decisione: non
            // sono un esito riuscito. Fino al 15/11/2026 arrivavano con `ok: true` e senza una frase, e l'agente credeva il piano creato.
            $haErrori = ! empty($errori) || collect($avvisi)->contains(fn ($a) => in_array($a['tipo'], ['error', 'danger', ...self::CANCELLI], true));

            return $this->json(array_replace($base, [
                'ok'        => ! $haErrori,
                'stato'     => $haErrori ? 422 : 200,
                'tipo'      => 'esito',
                'rimanda_a' => $verso ? $this->percorso($verso) : null,
                'avvisi'    => $avvisi,
                'errori'    => $errori ?: null,
            ]), $haErrori ? 422 : 200);
        }

        // JSON del gestionale (ricerche, conteggi, errori di validazione).
        if (str_contains($tipo, 'json')) {
            $dati = json_decode((string) $risposta->getContent(), true);

            // Il gestionale scrive il motivo in `message` (Laravel) o, in alcuni controller, in `errore`/`error`.
            $motivo = is_array($dati) ? ($dati['message'] ?? $dati['errore'] ?? $dati['error'] ?? null) : null;
            $motivo = is_string($motivo) ? $motivo : ($motivo ? json_encode($motivo, JSON_UNESCAPED_UNICODE) : null);

            if ($stato === 422 && is_array($dati)) {
                return $this->json(array_replace($base, ['tipo' => 'esito', 'avvisi' => [['tipo' => 'error', 'testo' => $motivo ?? 'Dati non validi.']], 'errori' => $dati['errors'] ?? null]), 422);
            }

            if ($stato >= 400) {
                return $this->json(array_replace($base, ['tipo' => 'errore', 'messaggio' => $motivo]), $stato);
            }

            return $this->json(array_replace($base, ['tipo' => 'dati', 'dati' => $dati]), $stato);
        }

        // Una pagina HTML o un errore senza JSON: per un agente non vale niente, ne resta lo stato.
        if (str_contains($tipo, 'text/html') || $stato >= 400) {
            return $this->json(array_replace($base, ['tipo' => 'errore', 'messaggio' => $stato >= 400 ? Response::$statusTexts[$stato] ?? 'Errore' : 'Risposta non prevista (HTML).']), $stato >= 400 ? $stato : 502);
        }

        $risposta->headers->set('X-Kondo-Agente', '1');

        return $risposta;
    }

    private function avvisiDa(array $flash): array
    {
        $avvisi = [];

        foreach ($flash as $chiave => $valore) {
            if ($valore === null || $valore === '' || $valore === []) {
                continue;
            }

            if (in_array($chiave, self::CANCELLI, true) && is_array($valore)) {
                $avvisi[] = ['tipo' => $chiave, 'testo' => $chiave === 'scoperti_warning'
                    ? \App\Exceptions\Gestionale\ScopertiNonAccettatiException::fraseDegliScoperti($valore)
                    : \App\Exceptions\Gestionale\DestinatariCambiatiException::fraseDeiCambiamenti($valore), 'dati' => $valore];
            } elseif (is_array($valore) && isset($valore['message'])) {
                $avvisi[] = ['tipo' => $valore['type'] ?? $chiave, 'testo' => is_string($valore['message']) ? $valore['message'] : json_encode($valore['message'])];
            } elseif (is_string($valore)) {
                $avvisi[] = ['tipo' => $chiave === 'message' ? 'info' : $chiave, 'testo' => $valore];
            } else {
                $avvisi[] = ['tipo' => $chiave, 'testo' => null, 'dati' => $valore];
            }
        }

        return $avvisi;
    }

    private function percorso(string $url): string
    {
        $parti = parse_url($url);

        return ($parti['path'] ?? '/').(isset($parti['query']) ? '?'.$parti['query'] : '');
    }

    /**
     * Il contatore delle richieste sta nella cache. Se la cache inciampa (il 14/11/2026 due richieste nello stesso
     * istante si sono bloccate a vicenda sulla cache a database, e una è caduta con un errore del server) la
     * richiesta passa lo stesso: meglio una richiesta non contata che un lavoro interrotto a metà.
     */
    private function entroIlLimite(ChiaveAgente $chiave): bool
    {
        try {
            return (bool) RateLimiter::attempt('agente:'.$chiave->id, (int) config('agenti.richieste_al_minuto', self::RICHIESTE_AL_MINUTO), fn () => true, 60);
        } catch (Throwable $e) {
            Log::warning('Agenti: contatore delle richieste non aggiornato', ['errore' => $e->getMessage()]);

            return true;
        }
    }

    /**
     * Perché il gestionale ha detto di no: la frase del rifiuto e i NOMI dei campi sbagliati. Mai i valori inviati.
     *
     * @return array{0:?string,1:?string}
     */
    private function motivoDa(Response $busta): array
    {
        if ($busta->getStatusCode() < 400) {
            return [null, null];
        }

        $dati = json_decode((string) $busta->getContent(), true);

        if (! is_array($dati)) {
            return [null, null];
        }

        $errori = is_array($dati['errori'] ?? null) ? $dati['errori'] : [];
        $motivo = $dati['messaggio'] ?? null;

        if (! is_string($motivo) || $motivo === '') {
            $rosso = collect($dati['avvisi'] ?? [])->first(fn ($a) => in_array($a['tipo'] ?? '', ['error', 'danger', ...self::CANCELLI], true) && is_string($a['testo'] ?? null));
            $primo = collect($errori)->flatten()->first(fn ($v) => is_string($v));
            $motivo = $rosso['testo'] ?? $primo;
        }

        return [is_string($motivo) ? $motivo : null, $errori ? implode(', ', array_keys($errori)) : null];
    }

    private function registra(ChiaveAgente $chiave, Request $request, string $nome, int $stato, bool $prova, float $inizio, ?string $motivo = null, ?string $campi = null): void
    {
        try {
            AzioneAgente::create([
                'chiave_id' => $chiave->id,
                'metodo'    => $request->method(),
                'percorso'  => Str::limit('/'.ltrim($request->path(), '/'), 490, ''),
                'rotta'     => $nome ?: null,
                'stato'     => $stato,
                'motivo'    => $motivo !== null ? Str::limit($motivo, 390, '…') : null,
                'campi'     => $campi !== null ? Str::limit($campi, 390, '…') : null,
                'prova'     => $prova,
                'durata_ms' => (int) round((microtime(true) - $inizio) * 1000),
            ]);
        } catch (Throwable $e) {
            Log::warning('Agenti: registro non scritto', ['errore' => $e->getMessage()]);
        }
    }

    private function json(array $corpo, int $stato): JsonResponse
    {
        return response()->json($corpo, $stato, ['X-Kondo-Agente' => '1'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function errore(int $stato, string $messaggio, array $extra = []): JsonResponse
    {
        return $this->json(['ok' => false, 'stato' => $stato, 'tipo' => 'errore', 'messaggio' => $messaggio] + $extra, $stato);
    }
}
