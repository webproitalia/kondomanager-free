<?php

namespace App\Services\Registrazioni;

use App\Contracts\Registrazioni\UnisceAudio;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * L'unione dei pezzi con ffmpeg (nell'immagine Docker dal 17/11/2026). Ogni pezzo è un file intero (il browser chiude e riapre la
 * registrazione ogni 25 secondi), quindi basta il «concat» di ffmpeg senza ricodificare: per un'ora e mezza di riunione bastano pochi
 * secondi. Se la copia non riesce (pezzi con parametri diversi, per esempio una registrazione ripresa da un altro telefono) si riprova
 * ricodificando, che è più lento ma accetta pezzi diversi. Un pezzo solo si copia e basta.
 *
 * ⚠️ Il «concat» di ffmpeg, quando un pezzo non si apre (un file vuoto, un mp4 senza intestazione perché il browser si è chiuso mentre
 * lo scriveva), si ferma lì, scrive quello che ha e ESCE CON 0: un pezzo rovinato a metà riunione taglierebbe tutto il resto, in silenzio
 * (provato il 17/11/2026 con ffmpeg 5.1 nell'immagine). Per questo ogni pezzo passa prima da ffprobe, e quelli illeggibili si saltano e
 * si dicono. Un pezzo solo troncato invece si apre, e ffmpeg ne tiene la parte buona.
 */
class UnioneAudioConFfmpeg implements UnisceAudio
{
    public function unisci(array $pezzi, string $destinazione): array
    {
        $leggibili = [];
        $scartati = [];

        foreach ($pezzi as $pezzo) {
            if ($this->leggibile($pezzo)) {
                $leggibili[] = $pezzo;
            } else {
                $scartati[] = basename($pezzo);
            }
        }

        if ($leggibili === []) {
            throw new RuntimeException($pezzi === [] ? 'Non c\'è nessun pezzo di audio da unire.' : 'Nessun pezzo di audio si riesce a leggere.');
        }

        if (count($leggibili) === 1) {
            if (! @copy($leggibili[0], $destinazione)) {
                throw new RuntimeException('Non riesco a copiare l\'unico pezzo di audio.');
            }

            return $scartati;
        }

        $pezzi = $leggibili;

        // L'elenco per il «concat» di ffmpeg: un file per riga, con gli apici del nome protetti come vuole ffmpeg.
        $elenco = dirname($destinazione).'/elenco.txt';
        file_put_contents($elenco, implode("\n", array_map(fn (string $p) => "file '".str_replace("'", "'\\''", $p)."'", $pezzi))."\n");

        try {
            $comune = ['ffmpeg', '-nostdin', '-hide_banner', '-loglevel', 'error', '-y', '-f', 'concat', '-safe', '0', '-i', $elenco];
            $copia = Process::timeout(600)->run([...$comune, '-c', 'copy', $destinazione]);

            if ($copia->successful() && is_file($destinazione) && filesize($destinazione) > 0) {
                return $scartati;
            }

            $codec = str_ends_with($destinazione, '.mp4') ? ['-c:a', 'aac', '-b:a', '64k'] : ['-c:a', 'libopus', '-b:a', '48k'];
            $ricodifica = Process::timeout(1200)->run([...$comune, '-vn', ...$codec, $destinazione]);

            if (! $ricodifica->successful() || ! is_file($destinazione) || filesize($destinazione) === 0) {
                $motivo = trim($ricodifica->errorOutput() ?: $copia->errorOutput());

                throw new RuntimeException('ffmpeg non è riuscito a unire l\'audio'.($motivo !== '' ? ': '.mb_substr($motivo, 0, 300) : '.'));
            }

            return $scartati;
        } finally {
            @unlink($elenco);
        }
    }

    /** Il pezzo si apre e ha una traccia audio? */
    private function leggibile(string $pezzo): bool
    {
        if (! is_file($pezzo) || filesize($pezzo) === 0) {
            return false;
        }

        $prova = Process::timeout(60)->run(['ffprobe', '-v', 'error', '-show_entries', 'stream=codec_type', '-of', 'csv=p=0', $pezzo]);

        return $prova->successful() && str_contains($prova->output(), 'audio');
    }
}
