<?php

namespace App\Console\Commands;

use App\Models\Registrazione;
use App\Services\Registrazioni\ArchivioAudio;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * La privacy delle registrazioni (Web Pro Italia, 17/11/2026): l'audio serve solo a scrivere la trascrizione, quindi si cancella da solo
 * `registrazioni.giorni_audio` giorni (30 di serie) dopo la fine della registrazione. La trascrizione, il riassunto e la bozza per punti
 * restano finché la persona non elimina la registrazione. Gira ogni notte (routes/console.php).
 *
 * Una registrazione mai chiusa (il browser si è chiuso e nessuno l'ha chiusa dall'elenco), o ferma in elaborazione, perde l'audio dopo lo
 * stesso numero di giorni dall'ultimo movimento: l'audio di una riunione non resta sul disco per sempre perché nessuno ha premuto un tasto.
 */
class PulisciAudioRegistrazioniCommand extends Command
{
    protected $signature = 'registrazioni:pulisci-audio';

    protected $description = 'Cancella l\'audio delle registrazioni più vecchie dei giorni impostati (le trascrizioni restano)';

    public function handle(ArchivioAudio $archivio): int
    {
        $giorni = max(1, (int) config('registrazioni.giorni_audio', 30));
        $limite = now()->subDays($giorni)->toDateTimeString();
        $tolte = 0;
        $byte = 0;

        Registrazione::query()
            ->whereNull('audio_cancellato_at')
            ->where(function (Builder $q) use ($limite) {
                $q->where(fn (Builder $q) => $q->whereIn('stato', [Registrazione::PRONTA, Registrazione::ERRORE])
                    ->whereRaw('COALESCE(finita_at, iniziata_at, created_at) < ?', [$limite]))
                    ->orWhere(fn (Builder $q) => $q->whereIn('stato', [Registrazione::IN_CORSO, Registrazione::IN_ELABORAZIONE])
                        ->where('updated_at', '<', $limite));
            })
            ->chunkById(100, function ($registrazioni) use ($archivio, &$tolte, &$byte) {
                foreach ($registrazioni as $registrazione) {
                    $byte += $archivio->byte($registrazione);
                    $archivio->cancella($registrazione);
                    $registrazione->forceFill(['audio_cancellato_at' => now()])->save();
                    $tolte++;
                }
            });

        $this->info($tolte
            ? sprintf('Audio cancellato per %d registrazioni oltre i %d giorni (%.1f MB liberati). Le trascrizioni restano.', $tolte, $giorni, $byte / 1048576)
            : sprintf('Nessuna registrazione con l\'audio oltre i %d giorni.', $giorni));

        return self::SUCCESS;
    }
}
