<?php

namespace App\Services\Registrazioni;

use App\Models\Condominio;
use App\Models\Registrazione;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * L'audio delle registrazioni sul disco privato `local` (storage/app/private), mai su quello pubblico e mai su S3: ffmpeg e Deepgram
 * lavorano su file veri, e l'audio di una riunione non esce dal server finché non va a Deepgram. Un pezzo per file, col suo numero:
 * `registrazioni/<id>/seg-0001.webm`. Il numero lo dà il browser, così un pezzo arrivato fuori ordine o due volte va al suo posto.
 * Il file unito (`completo.<formato>`) vive solo durante l'elaborazione.
 */
class ArchivioAudio
{
    public function disco(): Filesystem
    {
        return Storage::disk('local');
    }

    public function nomePezzo(int $numero, string $formato): string
    {
        return sprintf('seg-%04d.%s', $numero, $formato);
    }

    public function salvaPezzo(Registrazione $registrazione, int $numero, UploadedFile $file): string
    {
        $nome = $this->nomePezzo($numero, $registrazione->formato ?: 'webm');
        $this->disco()->putFileAs($registrazione->cartella(), $file, $nome);

        return $registrazione->cartella().'/'.$nome;
    }

    /**
     * I pezzi sul disco, in ordine di numero (non di arrivo).
     *
     * @return array<int, string> numero => percorso relativo al disco
     */
    public function pezzi(Registrazione $registrazione): array
    {
        $pezzi = [];

        foreach ($this->disco()->files($registrazione->cartella()) as $percorso) {
            if (preg_match('/\/seg-(\d+)\.(webm|mp4|ogg)$/', $percorso, $m)) {
                $pezzi[(int) $m[1]] = $percorso;
            }
        }

        ksort($pezzi);

        return $pezzi;
    }

    public function haAudio(Registrazione $registrazione): bool
    {
        return $this->pezzi($registrazione) !== [];
    }

    /** I byte dell'audio conservato: i pezzi e, se è rimasto, il file unito. */
    public function byte(Registrazione $registrazione): int
    {
        $totale = 0;

        foreach ($this->disco()->files($registrazione->cartella()) as $percorso) {
            $totale += (int) $this->disco()->size($percorso);
        }

        return $totale;
    }

    public function percorsoAssoluto(string $relativo): string
    {
        return $this->disco()->path($relativo);
    }

    /** Il file unito: sta nella cartella della registrazione, e si toglie appena la passata finale l'ha letto. */
    public function percorsoCompleto(Registrazione $registrazione): string
    {
        return $registrazione->cartella().'/completo.'.($registrazione->formato ?: 'webm');
    }

    /** Toglie tutto l'audio della registrazione (i pezzi e il file unito). La riga e i testi restano. */
    public function cancella(Registrazione $registrazione): void
    {
        $this->disco()->deleteDirectory($registrazione->cartella());
    }

    /** @var array<int, list<int>> le registrazioni di un condominio che si sta eliminando, lette prima che la cascata le porti via */
    private static array $daTogliere = [];

    /**
     * Un condominio eliminato porta via le sue registrazioni a cascata nel database, ma non i file: le cartelle si leggono qui, prima della
     * cancellazione (`Condominio::deleting`), e si tolgono dopo (`deleted`), a transazione chiusa. Come gli allegati delle segnalazioni.
     */
    public function preparaPuliziaPerCondominio(Condominio $condominio): void
    {
        self::$daTogliere[$condominio->id] = Schema::hasTable('registrazioni')
            ? Registrazione::where('condominio_id', $condominio->id)->pluck('id')->map(fn ($id) => (int) $id)->all()
            : [];
    }

    public function completaPuliziaPerCondominio(Condominio $condominio): void
    {
        $ids = self::$daTogliere[$condominio->id] ?? [];
        unset(self::$daTogliere[$condominio->id]);

        if ($ids !== []) {
            DB::afterCommit(function () use ($ids) {
                foreach ($ids as $id) {
                    $this->disco()->deleteDirectory('registrazioni/'.$id);
                }
            });
        }
    }

    /** Il tipo da dichiarare a Deepgram per un file di questo formato. */
    public static function mime(?string $formato): string
    {
        return match ($formato) {
            'mp4' => 'audio/mp4',
            'ogg' => 'audio/ogg',
            default => 'audio/webm',
        };
    }
}
