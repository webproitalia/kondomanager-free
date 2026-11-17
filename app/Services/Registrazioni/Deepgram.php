<?php

namespace App\Services\Registrazioni;

use GuzzleHttp\Psr7\LazyOpenStream;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * La trascrizione con Deepgram (modello `nova-3`, italiano, punteggiatura e paragrafi), come in Follow Call:
 *  - un pezzo da 25 secondi alla volta, mentre si registra: il testo in diretta, senza le voci;
 *  - a fine riunione una passata sola sull'audio intero con `diarize=true`: le voci «Persona 1, 2…» sono coerenti solo così.
 *
 * Il vocabolario (`keyterm`) aiuta Deepgram con le parole del condominio e i nomi dei condòmini. Se Deepgram rifiuta la richiesta per il
 * vocabolario (400), si riprova una volta senza: meglio una trascrizione con un nome storpiato che nessuna trascrizione.
 * L'audio va a Deepgram in streaming dal disco: un'ora e mezza di riunione non passa dalla memoria di PHP.
 */
class Deepgram
{
    public const URL = 'https://api.deepgram.com/v1/listen';

    public function disponibile(): bool
    {
        return trim((string) config('services.deepgram.key')) !== '';
    }

    /**
     * Il testo di un pezzo, senza le voci.
     *
     * @param  list<string>  $vocabolario
     * @return array{ok: bool, testo: string, secondi: float, errore: ?string}
     */
    public function pezzo(string $percorso, string $mime, array $vocabolario = []): array
    {
        $esito = $this->chiedi($percorso, $mime, false, $vocabolario, 60);

        if (! $esito['ok']) {
            return ['ok' => false, 'testo' => '', 'secondi' => 0.0, 'errore' => $esito['errore']];
        }

        $alternativa = $esito['json']['results']['channels'][0]['alternatives'][0] ?? [];

        return [
            'ok' => true,
            'testo' => trim((string) ($alternativa['transcript'] ?? '')),
            'secondi' => (float) ($esito['json']['metadata']['duration'] ?? 0),
            'errore' => null,
        ];
    }

    /**
     * La trascrizione finale, con le voci: un capoverso per ogni turno di parola, «[mm:ss] Persona N: …».
     *
     * @param  list<string>  $vocabolario
     * @return array{ok: bool, testo: string, secondi: float, voci: int, errore: ?string}
     */
    public function completa(string $percorso, string $mime, array $vocabolario = []): array
    {
        $esito = $this->chiedi($percorso, $mime, true, $vocabolario, 900);

        if (! $esito['ok']) {
            return ['ok' => false, 'testo' => '', 'secondi' => 0.0, 'voci' => 0, 'errore' => $esito['errore']];
        }

        $alternativa = $esito['json']['results']['channels'][0]['alternatives'][0] ?? [];
        $secondi = (float) ($esito['json']['metadata']['duration'] ?? 0);
        $paragrafi = $alternativa['paragraphs']['paragraphs'] ?? [];

        // Senza paragrafi (una risposta senza parole, o un audio muto) resta il testo semplice.
        if (! is_array($paragrafi) || $paragrafi === []) {
            return ['ok' => true, 'testo' => trim((string) ($alternativa['transcript'] ?? '')), 'secondi' => $secondi, 'voci' => 0, 'errore' => null];
        }

        // I paragrafi consecutivi della stessa voce diventano un turno solo.
        $turni = [];
        foreach ($paragrafi as $paragrafo) {
            $testo = trim(implode(' ', array_map(fn ($f) => trim((string) ($f['text'] ?? '')), $paragrafo['sentences'] ?? [])));
            if ($testo === '') {
                continue;
            }
            $voce = is_numeric($paragrafo['speaker'] ?? null) ? (int) $paragrafo['speaker'] : null;
            $ultimo = array_key_last($turni);

            if ($ultimo !== null && $turni[$ultimo]['voce'] === $voce) {
                $turni[$ultimo]['testo'] .= ' '.$testo;
            } else {
                $turni[] = ['voce' => $voce, 'inizio' => (float) ($paragrafo['start'] ?? 0), 'testo' => $testo];
            }
        }

        $righe = array_map(function (array $t) {
            $chi = $t['voce'] === null ? '' : ' Persona '.($t['voce'] + 1).':';

            return '['.self::minuti($t['inizio']).']'.$chi.' '.$t['testo'];
        }, $turni);

        $voci = count(array_unique(array_filter(array_column($turni, 'voce'), fn ($v) => $v !== null)));

        return ['ok' => true, 'testo' => implode("\n\n", $righe), 'secondi' => $secondi, 'voci' => $voci, 'errore' => null];
    }

    public static function minuti(float $secondi): string
    {
        $s = (int) floor($secondi);

        return $s >= 3600
            ? sprintf('%d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60)
            : sprintf('%02d:%02d', intdiv($s, 60), $s % 60);
    }

    /**
     * @param  list<string>  $vocabolario
     * @return array{ok: bool, json: array, errore: ?string}
     */
    private function chiedi(string $percorso, string $mime, bool $voci, array $vocabolario, int $timeout): array
    {
        $chiave = trim((string) config('services.deepgram.key'));

        if ($chiave === '') {
            return ['ok' => false, 'json' => [], 'errore' => 'Manca la chiave di Deepgram (KONDO_DEEPGRAM_KEY).'];
        }

        try {
            $risposta = $this->manda($chiave, $percorso, $mime, $voci, $vocabolario, $timeout);

            if ($risposta->status() === 400 && $vocabolario !== []) {
                Log::warning('Registrazioni: Deepgram ha rifiutato il vocabolario, riprovo senza', ['risposta' => mb_substr($risposta->body(), 0, 300)]);
                $risposta = $this->manda($chiave, $percorso, $mime, $voci, [], $timeout);
            }
        } catch (ConnectionException $e) {
            return ['ok' => false, 'json' => [], 'errore' => 'Deepgram non risponde: '.mb_substr($e->getMessage(), 0, 200)];
        } catch (Throwable $e) {
            return ['ok' => false, 'json' => [], 'errore' => 'Deepgram: '.mb_substr($e->getMessage(), 0, 200)];
        }

        if (! $risposta->successful()) {
            return ['ok' => false, 'json' => [], 'errore' => 'Deepgram ha risposto '.$risposta->status().': '.mb_substr(trim($risposta->body()), 0, 200)];
        }

        $json = $risposta->json();

        if (! is_array($json)) {
            return ['ok' => false, 'json' => [], 'errore' => 'Deepgram ha mandato una risposta illeggibile.'];
        }

        return ['ok' => true, 'json' => $json, 'errore' => null];
    }

    /** @param  list<string>  $vocabolario */
    private function manda(string $chiave, string $percorso, string $mime, bool $voci, array $vocabolario, int $timeout): Response
    {
        $parametri = [
            'model' => (string) config('services.deepgram.model', 'nova-3') ?: 'nova-3',
            'language' => 'it',
            'smart_format' => 'true',
            'punctuate' => 'true',
            'paragraphs' => 'true',
        ];
        if ($voci) {
            $parametri['diarize'] = 'true';
        }

        // `keyterm` si ripete una volta per parola: `http_build_query` scriverebbe `keyterm[0]=`, che Deepgram non capisce.
        $query = http_build_query($parametri, '', '&', PHP_QUERY_RFC3986);
        foreach ($vocabolario as $parola) {
            $query .= '&keyterm='.rawurlencode($parola);
        }

        if (! is_readable($percorso)) {
            throw new \RuntimeException('il file audio non si apre');
        }

        // Il file si apre quando Guzzle comincia a mandarlo e si chiude quando la richiesta se ne va.
        return Http::withHeaders(['Authorization' => 'Token '.$chiave])
            ->connectTimeout(15)
            ->timeout($timeout)
            ->withBody(new LazyOpenStream($percorso, 'rb'), $mime)
            ->post((rtrim((string) config('services.deepgram.url', self::URL), '?') ?: self::URL).'?'.$query);
    }
}
