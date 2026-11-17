<?php

namespace App\Contracts\Registrazioni;

use App\Services\Registrazioni\UnioneAudioConFfmpeg;
use Illuminate\Container\Attributes\Bind;

/**
 * Chi unisce i pezzi di audio di una registrazione in un file solo, per la passata finale che riconosce le voci (una passata sola sull'audio
 * intero: le voci «Persona 1, 2…» sono coerenti solo così). In produzione è ffmpeg; nei test un finto, perché ffmpeg non c'è dappertutto.
 */
#[Bind(UnioneAudioConFfmpeg::class)]
interface UnisceAudio
{
    /**
     * @param  list<string>  $pezzi  i percorsi assoluti dei pezzi, già in ordine
     * @param  string  $destinazione  il percorso assoluto del file unito, con l'estensione del formato
     * @return list<string> i pezzi scartati perché illeggibili (il nome del file): mancano dal file unito
     *
     * @throws \RuntimeException se l'unione non riesce: il messaggio dice perché
     */
    public function unisci(array $pezzi, string $destinazione): array;
}
