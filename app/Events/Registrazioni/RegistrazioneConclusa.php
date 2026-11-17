<?php

namespace App\Events\Registrazioni;

use App\Models\Registrazione;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Una registrazione è pronta: trascrizione con le voci, riassunto (se Gemini ha risposto) e, se è collegata a un'assemblea che ha l'ordine
 * del giorno, `bozza_punti` = `[{numero, discussione, delibera}]` (`delibera` è `null` quando una decisione chiara non è stata detta).
 *
 * Si lancia alla fine dell'elaborazione (`ElaboraRegistrazione`), e di nuovo quando una registrazione già pronta viene collegata a
 * un'assemblea, dopo aver preparato la bozza per i punti di quell'assemblea. Chi ascolta (il verbale dell'assemblea) legge
 * `$registrazione->assemblea_id` e `$registrazione->bozza_punti`; non si lancia per una registrazione finita in errore.
 */
class RegistrazioneConclusa
{
    use Dispatchable, SerializesModels;

    public function __construct(public Registrazione $registrazione) {}
}
