<?php

namespace App\Jobs\Registrazioni;

use App\Contracts\Registrazioni\UnisceAudio;
use App\Events\Registrazioni\RegistrazioneConclusa;
use App\Models\Registrazione;
use App\Services\Registrazioni\ArchivioAudio;
use App\Services\Registrazioni\Assemblee;
use App\Services\Registrazioni\Deepgram;
use App\Services\Registrazioni\Gemini;
use App\Services\Registrazioni\Vocabolario;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * L'elaborazione finale di una registrazione (Web Pro Italia, 17/11/2026), in coda perché dura: si uniscono i pezzi di audio (ffmpeg), si fa
 * UNA passata con Deepgram sull'audio intero con le voci, poi Gemini scrive il riassunto e, se la registrazione è collegata a un'assemblea
 * che ha l'ordine del giorno (`assemblea_punti`), la bozza per punto. Alla fine `RegistrazioneConclusa`.
 *
 * - Se l'unione o Deepgram non riescono, la registrazione va in `errore` con il motivo e i pezzi restano: si riprova dalla pagina.
 * - Se Gemini non risponde, la registrazione è `pronta` lo stesso, senza riassunto.
 * - Il tempo: `registrazioni.timeout_elaborazione` (1.800 secondi, per un'ora e mezza di riunione), più alto del `--timeout=90` del worker;
 *   `retry_after` della coda (config/queue.php) sta sopra, perché la coda non la creda abbandonata e non la faccia partire due volte. Per
 *   sicurezza c'è anche un lucchetto: una seconda copia che parte mentre la prima lavora esce senza fare niente.
 * - Con `$soloBozza` prepara soltanto la bozza per punti di una registrazione già pronta (collegata dopo a un'assemblea).
 */
class ElaboraRegistrazione implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Una seconda volta solo se la prima si è interrotta (il worker fermato a metà da un aggiornamento): gli errori dei servizi no. */
    public int $tries = 2;

    public int $timeout;

    public bool $failOnTimeout = true;

    public function __construct(public int $registrazioneId, public bool $soloBozza = false)
    {
        $this->timeout = max(120, (int) config('registrazioni.timeout_elaborazione', 1800));
    }

    public function handle(ArchivioAudio $archivio, UnisceAudio $unione, Deepgram $deepgram, Gemini $gemini, Vocabolario $vocabolario, Assemblee $assemblee): void
    {
        $lucchetto = null;

        try {
            $lucchetto = Cache::lock('registrazioni:elabora:'.$this->registrazioneId, $this->timeout + 120);
            if (! $lucchetto->get()) {
                Log::info('Registrazioni: elaborazione già in corso, questa copia esce', ['registrazione' => $this->registrazioneId]);

                return;
            }
        } catch (Throwable $e) {
            // Una cache che inciampa non deve fermare l'elaborazione: senza lucchetto resta la guardia sullo stato, qui sotto.
            Log::warning('Registrazioni: lucchetto non preso', ['errore' => $e->getMessage()]);
            $lucchetto = null;
        }

        try {
            $this->soloBozza
                ? $this->bozza($gemini, $assemblee)
                : $this->elabora($archivio, $unione, $deepgram, $gemini, $vocabolario, $assemblee);
        } finally {
            try {
                $lucchetto?->release();
            } catch (Throwable) {
            }
        }
    }

    public function failed(?Throwable $e): void
    {
        $registrazione = Registrazione::find($this->registrazioneId);

        if ($registrazione && $registrazione->stato === Registrazione::IN_ELABORAZIONE && ! $this->soloBozza) {
            $registrazione->forceFill([
                'stato' => Registrazione::ERRORE,
                'errore' => 'L\'elaborazione si è interrotta'.($e ? ' ('.mb_substr($e->getMessage(), 0, 200).')' : '').'. I pezzi di audio sono salvi: premi «Riprova».',
            ])->save();
        }
    }

    private function elabora(ArchivioAudio $archivio, UnisceAudio $unione, Deepgram $deepgram, Gemini $gemini, Vocabolario $vocabolario, Assemblee $assemblee): void
    {
        $registrazione = Registrazione::with('condominio')->find($this->registrazioneId);

        // Eliminata nel frattempo, o già sistemata da un'altra copia: niente da fare.
        if (! $registrazione || $registrazione->stato !== Registrazione::IN_ELABORAZIONE) {
            return;
        }

        @set_time_limit($this->timeout + 60);

        $pezzi = $archivio->pezzi($registrazione);

        if ($pezzi === []) {
            $this->errore($registrazione, $registrazione->audio_cancellato_at
                ? 'L\'audio di questa registrazione è stato cancellato: non si può più trascrivere. Resta il testo in diretta.'
                : 'Non è arrivato nessun pezzo di audio: non c\'è niente da trascrivere.');

            return;
        }

        $completo = $archivio->percorsoAssoluto($archivio->percorsoCompleto($registrazione));

        try {
            $scartati = $unione->unisci(array_values(array_map(fn ($p) => $archivio->percorsoAssoluto($p), $pezzi)), $completo);
        } catch (RuntimeException $e) {
            @unlink($completo);
            $this->errore($registrazione, 'Non sono riuscito a unire i pezzi di audio: '.$e->getMessage());

            return;
        }

        try {
            $finale = $deepgram->completa($completo, ArchivioAudio::mime($registrazione->formato), $vocabolario->per($registrazione->condominio));
        } finally {
            @unlink($completo);
        }

        if (! $finale['ok']) {
            $this->errore($registrazione, 'La trascrizione con le voci non è riuscita: '.$finale['errore'].' I pezzi di audio sono salvi: premi «Riprova».');

            return;
        }

        $registrazione->trascrizione = $finale['testo'];
        $registrazione->durata_secondi = (int) round($finale['secondi']) ?: $registrazione->secondi();

        $testo = trim($finale['testo']) !== '' ? $finale['testo'] : (string) $registrazione->trascrizione_live;
        $punti = $assemblee->punti($registrazione->assemblea_id);
        $nome = (string) ($registrazione->condominio?->nome ?? '');

        $riassunto = $gemini->riassunto($testo, $nome, $punti ?: null);
        $registrazione->riassunto = $riassunto['testo'] ?? null;

        // Il titolo proposto vale solo se chi ha registrato non ne aveva dato uno.
        if (($riassunto['titolo'] ?? null) && self::titoloAutomatico($registrazione->titolo)) {
            $registrazione->titolo = $riassunto['titolo'];
        }

        $registrazione->bozza_punti = $punti ? $gemini->bozzaPunti($testo, $nome, $punti) : null;
        $registrazione->stato = Registrazione::PRONTA;
        // Pronta, con una nota se qualche pezzo era illeggibile: la trascrizione con le voci ha un buco lì (il testo in diretta di quei pezzi
        // può esserci lo stesso).
        $registrazione->errore = $scartati
            ? (count($scartati) === 1 ? 'Un pezzo di audio non era leggibile' : count($scartati).' pezzi di audio non erano leggibili')
                .' e manca dalla trascrizione con le voci (circa '.(count($scartati) * max(5, (int) config('registrazioni.secondi_segmento', 25))).' secondi): '.implode(', ', $scartati).'.'
            : null;
        $registrazione->save();

        RegistrazioneConclusa::dispatch($registrazione);
    }

    private function bozza(Gemini $gemini, Assemblee $assemblee): void
    {
        $registrazione = Registrazione::with('condominio')->find($this->registrazioneId);

        if (! $registrazione || $registrazione->stato !== Registrazione::PRONTA || ! $registrazione->assemblea_id) {
            return;
        }

        $punti = $assemblee->punti($registrazione->assemblea_id);
        $testo = trim((string) $registrazione->trascrizione) !== '' ? (string) $registrazione->trascrizione : (string) $registrazione->trascrizione_live;
        $bozza = $punti ? $gemini->bozzaPunti($testo, (string) ($registrazione->condominio?->nome ?? ''), $punti) : null;

        // Chi ha cambiato idea nel frattempo (scollegata, o collegata a un'altra assemblea) non riceve la bozza della prima.
        if ($registrazione->fresh()?->assemblea_id !== $registrazione->assemblea_id) {
            return;
        }

        $registrazione->forceFill(['bozza_punti' => $bozza])->save();

        RegistrazioneConclusa::dispatch($registrazione);
    }

    private function errore(Registrazione $registrazione, string $motivo): void
    {
        Log::warning('Registrazioni: elaborazione non riuscita', ['registrazione' => $registrazione->id, 'motivo' => $motivo]);

        $registrazione->forceFill(['stato' => Registrazione::ERRORE, 'errore' => mb_substr($motivo, 0, 2000)])->save();
    }

    /** Il titolo che il programma dà quando chi registra non ne scrive uno: si può sostituire con quello proposto. */
    public static function titoloAutomatico(?string $titolo): bool
    {
        return $titolo === null || trim($titolo) === '' || str_starts_with($titolo, 'Registrazione del ');
    }
}
