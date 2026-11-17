<?php

namespace App\Http\Controllers\Gestionale\Registrazioni;

use App\Helpers\DateHelper;
use App\Http\Controllers\Controller;
use App\Http\Resources\Condominio\CondominioResource;
use App\Jobs\Registrazioni\ElaboraRegistrazione;
use App\Models\Condominio;
use App\Models\Registrazione;
use App\Services\Registrazioni\ArchivioAudio;
use App\Services\Registrazioni\Assemblee;
use App\Services\Registrazioni\Deepgram;
use App\Services\Registrazioni\Gemini;
use App\Services\Registrazioni\Vocabolario;
use App\Support\LimiteCaricamento;
use App\Traits\HandleFlashMessages;
use App\Traits\HasCondomini;
use App\Traits\HasEsercizio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Le registrazioni delle riunioni del condominio (Web Pro Italia, 17/11/2026). Una registrazione non è sempre un'assemblea; quando lo è,
 * porta `assemblea_id` e la pagina dell'assemblea incorpora lo stesso registratore (`RegistratoreAssemblea.vue`).
 *
 * Dal browser: `avvia` (con la conferma di aver avvisato i presenti), un `segmento` ogni 25 secondi (salvato e subito trascritto: il testo
 * in diretta), `termina` (mette in coda l'elaborazione finale). Il browser che si chiude non perde niente: i pezzi saliti restano, e la
 * registrazione si chiude dall'elenco con lo stesso `termina`. Agli agenti AI avviare e mandare l'audio sono vietati (`AutenticaAgente`):
 * leggono elenco e dettaglio, collegano un'assemblea, chiudono o riprovano.
 *
 * `{registrazione}` arriva grezzo: che sia di questo condominio lo controlla `assicuraRegistrazione()` (404), come per le dispense.
 * Le risposte: una pagina Inertia (o un rimando con l'avviso) per il browser e per gli agenti; JSON per il registratore, che usa axios.
 */
class RegistrazioneController extends Controller
{
    use HandleFlashMessages, HasCondomini, HasEsercizio;

    public function __construct(
        private ArchivioAudio $archivio,
        private Deepgram $deepgram,
        private Gemini $gemini,
        private Assemblee $assemblee,
    ) {}

    public function index(Condominio $condominio): Response
    {
        $registrazioni = Registrazione::where('condominio_id', $condominio->id)
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(fn (Registrazione $r) => $this->riga($r))
            ->values();

        return Inertia::render('gestionale/registrazioni/Registrazioni', [
            'condominio' => ['id' => $condominio->id, 'nome' => $condominio->nome],
            'condomini' => CondominioResource::collection($this->getCondomini())->resolve(),
            'esercizio' => $this->getEsercizioCorrente($condominio),
            'registrazioni' => $registrazioni,
            'servizio' => $this->servizioDati(),
        ]);
    }

    public function show(Condominio $condominio, string|int $registrazione): Response
    {
        $r = $this->assicuraRegistrazione($condominio, $registrazione);
        $r->loadMissing('autore:id,name');
        $byte = $this->archivio->byte($r);
        $fine = $r->finita_at ?? $r->iniziata_at ?? $r->created_at;

        return Inertia::render('gestionale/registrazioni/RegistrazioneShow', [
            'condominio' => ['id' => $condominio->id, 'nome' => $condominio->nome],
            'condomini' => CondominioResource::collection($this->getCondomini())->resolve(),
            'esercizio' => $this->getEsercizioCorrente($condominio),
            'registrazione' => $this->riga($r) + [
                'trascrizione' => $r->trascrizione,
                'trascrizione_live' => $r->trascrizione_live,
                'riassunto' => $r->riassunto,
                'bozza_punti' => $r->bozza_punti,
                'avviso_presenti_at' => $r->avviso_presenti_at?->toIso8601String(),
                // Le date scritte nel fuso dello studio, come il titolo: il browser le scriverebbe nel suo.
                'avviso_presenti_quando' => $r->avviso_presenti_at?->copy()->timezone(config('app.user_timezone', 'Europe/Rome'))->format('d/m/Y \a\l\l\e H:i'),
                'audio_cancellato_il' => $r->audio_cancellato_at?->copy()->timezone(config('app.user_timezone', 'Europe/Rome'))->format('d/m/Y'),
                'finita_at' => $r->finita_at?->toIso8601String(),
                'autore' => $r->autore?->name,
                'audio' => $byte > 0,
                'audio_byte' => $byte,
                // Il giorno in cui l'audio si cancella da solo (se c'è ancora e la registrazione è chiusa).
                'audio_scade_il' => $byte > 0 && $fine && ! in_array($r->stato, [Registrazione::IN_CORSO, Registrazione::IN_ELABORAZIONE], true)
                    ? $fine->copy()->addDays($this->giorniAudio())->timezone(config('app.user_timezone', 'Europe/Rome'))->format('d/m/Y')
                    : null,
            ],
            'punti' => $this->assemblee->punti($r->assemblea_id),
            'assemblee' => $this->assemblee->elenco($condominio->id),
            'servizio' => $this->servizioDati(),
        ]);
    }

    /** Lo stato del servizio, per il registratore incorporato in un'altra pagina: c'è la trascrizione? E il riassunto? */
    public function servizio(Condominio $condominio): JsonResponse
    {
        return response()->json($this->servizioDati());
    }

    public function avvia(Request $request, Condominio $condominio): JsonResponse|RedirectResponse
    {
        $dati = $request->validate([
            'avviso_presenti' => ['accepted'],
            'assemblea_id' => ['nullable', 'integer', 'min:1'],
            'titolo' => ['nullable', 'string', 'max:150'],
            'formato' => ['nullable', Rule::in(Registrazione::FORMATI)],
        ], [
            'avviso_presenti.accepted' => 'Prima di registrare conferma di aver avvisato i presenti che la riunione viene registrata solo per scrivere il verbale.',
        ]);

        if (! $this->deepgram->disponibile()) {
            throw ValidationException::withMessages(['servizio' => 'La trascrizione non è attiva su questo gestionale: manca la chiave di Deepgram. Chi gestisce il server la imposta in KONDO_DEEPGRAM_KEY.']);
        }

        $assembleaId = isset($dati['assemblea_id']) ? (int) $dati['assemblea_id'] : null;
        if ($assembleaId && ! $this->assemblee->delCondominio($assembleaId, $condominio->id)) {
            throw ValidationException::withMessages(['assemblea_id' => 'L\'assemblea indicata non è di questo condominio.']);
        }

        $titolo = trim((string) ($dati['titolo'] ?? ''));
        $registrazione = Registrazione::create([
            'condominio_id' => $condominio->id,
            'assemblea_id' => $assembleaId,
            'titolo' => $titolo !== '' ? $titolo : 'Registrazione del '.DateHelper::adessoUtente()->format('d/m/Y').' alle '.DateHelper::adessoUtente()->format('H:i'),
            'stato' => Registrazione::IN_CORSO,
            'avviso_presenti_at' => now(),
            'iniziata_at' => now(),
            'formato' => $dati['formato'] ?? 'webm',
            'segmenti' => [],
            'created_by' => $request->user()?->id,
        ]);

        if ($request->header('X-Inertia')) {
            return redirect()->route('admin.gestionale.registrazioni.show', [$condominio, $registrazione->id]);
        }

        return response()->json([
            'id' => $registrazione->id,
            'titolo' => $registrazione->titolo,
            'secondi_segmento' => $this->servizioDati()['secondi_segmento'],
            'url' => route('admin.gestionale.registrazioni.show', [$condominio, $registrazione->id], false),
        ], 201);
    }

    /**
     * Un pezzo di audio: si salva PRIMA, poi si trascrive (il testo in diretta). Il numero lo dà il browser: un pezzo che arriva due volte
     * (il browser ha riprovato dopo una risposta persa) risponde con il testo già fatto e non si ritrascrive; uno che arriva fuori ordine
     * va al suo posto. Se Deepgram non risponde il pezzo resta salvato: la passata finale lo sentirà lo stesso.
     */
    public function segmento(Request $request, Condominio $condominio, string|int $registrazione, Vocabolario $vocabolario): JsonResponse
    {
        $r = $this->assicuraRegistrazione($condominio, $registrazione);

        $dati = $request->validate([
            'numero' => ['required', 'integer', 'min:1', 'max:99999'],
            'audio' => ['required', 'file', 'max:'.LimiteCaricamento::regolaMax(20.0)],
        ], [
            'audio.max' => 'Il pezzo di audio pesa più di '.LimiteCaricamento::etichetta(20.0).'.',
            'audio.uploaded' => 'Il pezzo di audio non è arrivato al server (limite di '.LimiteCaricamento::etichettaServer().').',
        ]);

        if ($r->stato !== Registrazione::IN_CORSO) {
            return response()->json(['message' => 'La registrazione è già chiusa: questo pezzo non entra.'], 409);
        }

        $numero = (int) $dati['numero'];
        $gia = ($r->segmenti ?? [])[$numero] ?? null;

        if (is_array($gia) && $this->archivio->disco()->exists($r->cartella().'/'.$this->archivio->nomePezzo($numero, $r->formato ?: 'webm'))) {
            return response()->json(['numero' => $numero, 'testo' => (string) ($gia['testo'] ?? ''), 'errore' => $gia['errore'] ?? null, 'doppio' => true, 'secondi' => $r->secondi()]);
        }

        $percorso = $this->archivio->salvaPezzo($r, $numero, $request->file('audio'));
        $byte = (int) $this->archivio->disco()->size($percorso);
        $esito = $this->deepgram->pezzo($this->archivio->percorsoAssoluto($percorso), ArchivioAudio::mime($r->formato), $vocabolario->per($condominio));

        $aggiornata = DB::transaction(function () use ($r, $numero, $byte, $esito) {
            $riga = Registrazione::whereKey($r->id)->lockForUpdate()->firstOrFail();
            $segmenti = $riga->segmenti ?? [];
            $segmenti[$numero] = ['byte' => $byte, 'secondi' => round($esito['secondi'], 2), 'testo' => $esito['testo'], 'errore' => $esito['errore']];
            ksort($segmenti);

            $riga->segmenti = $segmenti;
            $riga->trascrizione_live = implode(' ', array_filter(array_map(fn ($s) => trim((string) ($s['testo'] ?? '')), $segmenti), fn ($t) => $t !== ''));
            $riga->save();

            return $riga;
        });

        return response()->json(['numero' => $numero, 'testo' => $esito['testo'], 'errore' => $esito['errore'], 'doppio' => false, 'secondi' => $aggiornata->secondi()]);
    }

    /**
     * Chiude la registrazione e mette in coda l'elaborazione finale. Vale anche per una registrazione rimasta aperta (il browser si è chiuso)
     * e, come «Riprova», per una finita in errore. Due clic insieme ne mettono in coda una sola: passa chi cambia lo stato.
     */
    public function termina(Request $request, Condominio $condominio, string|int $registrazione): JsonResponse|RedirectResponse
    {
        $r = $this->assicuraRegistrazione($condominio, $registrazione);

        if ($r->stato === Registrazione::PRONTA) {
            return $this->rispondi($request, 'La registrazione è già pronta.', 200, $r, 'info');
        }

        if ($r->stato === Registrazione::IN_ELABORAZIONE) {
            return $this->rispondi($request, 'La trascrizione con le voci è già in preparazione.', 200, $r, 'info');
        }

        if (! $this->archivio->haAudio($r)) {
            $motivo = $r->audio_cancellato_at
                ? 'L\'audio di questa registrazione è stato cancellato: non si può più trascrivere.'
                : 'Non è arrivato nessun pezzo di audio: non c\'è niente da trascrivere.';
            $r->forceFill(['stato' => Registrazione::ERRORE, 'errore' => $motivo, 'finita_at' => $r->finita_at ?? now()])->save();

            return $this->rispondi($request, $motivo, 422, $r, 'error');
        }

        $passata = Registrazione::whereKey($r->id)
            ->whereIn('stato', [Registrazione::IN_CORSO, Registrazione::ERRORE])
            ->update(['stato' => Registrazione::IN_ELABORAZIONE, 'errore' => null, 'finita_at' => $r->finita_at ?? now(), 'updated_at' => now()]);

        if ($passata === 1) {
            ElaboraRegistrazione::dispatch($r->id);
        }

        return $this->rispondi($request, 'Registrazione chiusa: preparo la trascrizione con le voci'.($this->gemini->disponibile() ? ' e il riassunto' : '').'. Ci vogliono alcuni minuti; puoi chiudere la pagina.', 200, $r->fresh());
    }

    /**
     * Collega la registrazione a un'assemblea di questo condominio, o la scollega (`assemblea_id` vuoto). Per una registrazione già pronta,
     * collegata a un'assemblea con l'ordine del giorno, si prepara in coda la bozza per punti e poi si lancia di nuovo `RegistrazioneConclusa`.
     */
    public function collega(Request $request, Condominio $condominio, string|int $registrazione): JsonResponse|RedirectResponse
    {
        $r = $this->assicuraRegistrazione($condominio, $registrazione);
        $dati = $request->validate(['assemblea_id' => ['present', 'nullable', 'integer', 'min:1']]);
        $assembleaId = $dati['assemblea_id'] !== null ? (int) $dati['assemblea_id'] : null;

        if ($assembleaId && ! $this->assemblee->delCondominio($assembleaId, $condominio->id)) {
            throw ValidationException::withMessages(['assemblea_id' => 'L\'assemblea indicata non è di questo condominio.']);
        }

        if ($assembleaId === $r->assemblea_id) {
            return $this->rispondi($request, $assembleaId ? 'La registrazione era già collegata a questa assemblea.' : 'La registrazione non era collegata a nessuna assemblea.', 200, $r, 'info');
        }

        $r->forceFill(['assemblea_id' => $assembleaId, 'bozza_punti' => null])->save();

        $bozza = $assembleaId && $r->stato === Registrazione::PRONTA && $this->gemini->disponibile() && $this->assemblee->punti($assembleaId) !== [];
        if ($bozza) {
            ElaboraRegistrazione::dispatch($r->id, true);
        }

        return $this->rispondi($request, $assembleaId
            ? 'Registrazione collegata all\'assemblea.'.($bozza ? ' Preparo la bozza per i punti dell\'ordine del giorno.' : '')
            : 'Registrazione scollegata dall\'assemblea.', 200, $r->fresh());
    }

    /** «Cancella l'audio»: toglie i file, la trascrizione e il riassunto restano. Non mentre si registra o si elabora. */
    public function cancellaAudio(Request $request, Condominio $condominio, string|int $registrazione): JsonResponse|RedirectResponse
    {
        $r = $this->assicuraRegistrazione($condominio, $registrazione);

        if ($r->stato === Registrazione::IN_CORSO) {
            return $this->rispondi($request, 'La registrazione è ancora aperta: prima chiudila, poi potrai cancellare l\'audio.', 409, $r, 'error');
        }

        if ($r->stato === Registrazione::IN_ELABORAZIONE) {
            return $this->rispondi($request, 'Aspetta che la trascrizione sia pronta: l\'audio serve ancora.', 409, $r, 'error');
        }

        $this->archivio->cancella($r);
        $r->forceFill(['audio_cancellato_at' => $r->audio_cancellato_at ?? now()])->save();

        return $this->rispondi($request, 'Audio cancellato. La trascrizione e il riassunto restano finché non elimini la registrazione.', 200, $r);
    }

    /** Elimina la registrazione: l'audio, i testi, tutto. */
    public function destroy(Request $request, Condominio $condominio, string|int $registrazione): JsonResponse|RedirectResponse
    {
        $r = $this->assicuraRegistrazione($condominio, $registrazione);

        $this->archivio->cancella($r);
        $r->delete();

        if ($request->header('X-Inertia')) {
            return redirect()->route('admin.gestionale.registrazioni.index', $condominio)
                ->with($this->flashSuccess('Registrazione eliminata, con il suo audio e i suoi testi.'));
        }

        return response()->json(['message' => 'Registrazione eliminata, con il suo audio e i suoi testi.']);
    }

    /** La registrazione dell'indirizzo, se è di questo condominio; altrimenti 404. */
    private function assicuraRegistrazione(Condominio $condominio, string|int $id): Registrazione
    {
        abort_unless(ctype_digit((string) $id), 404);

        return Registrazione::where('condominio_id', $condominio->id)->whereKey((int) $id)->firstOrFail();
    }

    private function riga(Registrazione $r): array
    {
        $fuso = config('app.user_timezone', 'Europe/Rome');
        $inizio = $r->iniziata_at ?? $r->created_at;

        return [
            'id' => $r->id,
            'titolo' => $r->titolo,
            'stato' => $r->stato,
            'assemblea_id' => $r->assemblea_id,
            'iniziata_at' => $inizio?->toIso8601String(),
            'quando' => $inizio ? Carbon::parse($inizio)->timezone($fuso)->format('d/m/Y H:i') : null,
            'durata_secondi' => $r->secondi(),
            'pezzi' => count($r->segmenti ?? []),
            'ha_riassunto' => trim((string) $r->riassunto) !== '',
            'ha_bozza' => ! empty($r->bozza_punti),
            'audio_cancellato_at' => $r->audio_cancellato_at?->toIso8601String(),
            'errore' => $r->errore,
        ];
    }

    private function servizioDati(): array
    {
        return [
            'trascrizione' => $this->deepgram->disponibile(),
            'riassunto' => $this->gemini->disponibile(),
            'giorni_audio' => $this->giorniAudio(),
            'secondi_segmento' => max(5, (int) config('registrazioni.secondi_segmento', 25)),
        ];
    }

    private function giorniAudio(): int
    {
        return max(1, (int) config('registrazioni.giorni_audio', 30));
    }

    /** Un rimando con l'avviso per il browser e per gli agenti (Inertia); JSON per il registratore. */
    private function rispondi(Request $request, string $messaggio, int $stato, ?Registrazione $r, string $tipo = 'success'): JsonResponse|RedirectResponse
    {
        if ($request->header('X-Inertia')) {
            return back()->with(match ($tipo) {
                'error' => $this->flashError($messaggio),
                'info' => $this->flashInfo($messaggio),
                default => $this->flashSuccess($messaggio),
            });
        }

        return response()->json(['message' => $messaggio, 'registrazione' => $r ? $this->riga($r) : null], $stato);
    }
}
