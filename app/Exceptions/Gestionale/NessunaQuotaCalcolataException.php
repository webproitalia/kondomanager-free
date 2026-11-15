<?php

namespace App\Exceptions\Gestionale;

use App\Models\Gestionale\PianoRate;
use App\Services\Riparto\MancanzeDelRiparto;
use RuntimeException;

/**
 * La generazione non ha calcolato nessuna quota: nessuna voce del piano si poteva ripartire su qualcuno (`GeneratePianoRateAction`).
 *
 * Fino al 15/11/2026 il messaggio elencava le tre cause possibili — millesimi, titolari, tabelle — senza dire quale (Web Pro Italia):
 * ora dice che cosa manca e dove, con i nomi, al più dieci casi per causa e quanti sono in tutto (`MancanzeDelRiparto`), e che cosa
 * fare. Se il motore non ha registrato nessuna causa resta il testo di prima. `errori()` dà ogni caso su una riga, con la chiave
 * `quote.{causa}`.
 *
 * Estende `RuntimeException`, come il rifiuto di prima: chi la prendeva come tale continua a farlo.
 */
class NessunaQuotaCalcolataException extends RuntimeException
{
    /**
     * @param  list<array{causa: string, titolo: string, rimedio: string, totale: int, elementi: list<string>}>  $cause  di `MancanzeDelRiparto`
     */
    public function __construct(public readonly PianoRate $pianoRate, public readonly array $cause)
    {
        if ($cause === []) {
            $dettaglio = $pianoRate->tipo === 'straordinario'
                ? 'Per i piani straordinari verificare che ogni fattura collegata abbia importo finanziato (> 0) e '
                    .'componenti straordinari validi: righe imprevisto/ad personam per le fatture correnti, '
                    ."oppure una copertura di tipo 'sopravvenienza' agganciata a un capitolo con tabella millesimale "
                    .'per le fatture pregresse.'
                : 'Verificare che (1) le tabelle millesimali abbiano i millesimi inseriti per ogni immobile, '
                    .'(2) ogni immobile abbia almeno un condòmino attivo associato, '
                    .'(3) ogni voce di spesa sia collegata a una tabella millesimale.';

            parent::__construct('Impossibile generare il piano rate: nessuna quota calcolata. '.$dettaglio);

            return;
        }

        parent::__construct(sprintf(
            'Impossibile generare il piano rate «%s»: nessuna quota calcolata, perché nessuna spesa del piano ha qualcuno a cui andare. %s Poi genera di nuovo il piano.',
            $pianoRate->nome,
            MancanzeDelRiparto::frase($cause)
        ));
    }

    /** @return array<string, list<string>> ogni caso su una riga, con la chiave `quote.{causa}` */
    public function errori(): array
    {
        return MancanzeDelRiparto::errori($this->cause);
    }

    public function report(): bool
    {
        return false;
    }
}
