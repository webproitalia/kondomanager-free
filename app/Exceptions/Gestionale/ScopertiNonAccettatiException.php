<?php

namespace App\Exceptions\Gestionale;

use Exception;

class ScopertiNonAccettatiException extends Exception
{
    protected array $scoperti;

    public function __construct(array $scoperti)
    {
        $this->scoperti = $scoperti;
        parent::__construct("Ci sono quote scoperte che richiedono approvazione esplicita.");
    }

    public function getScoperti(): array
    {
        return $this->scoperti;
    }

    /**
     * Il cancello in parole, per chi non vede il riquadro della pagina: un agente AI riceveva l'elenco degli scoperti senza una frase,
     * con la risposta «riuscita» e il rimando alla home (Web Pro Italia, 15/11/2026; `AutenticaAgente`). Dice che niente è stato
     * scritto, che cosa manca e dove (`MancanzeDelRiparto`), e le due strade: correggere, o procedere con la presa d'atto.
     *
     * @param  list<array<string, mixed>>  $scoperti
     */
    public static function fraseDegliScoperti(array $scoperti): string
    {
        $cause = app(\App\Services\Riparto\MancanzeDelRiparto::class)->perGliScoperti($scoperti);

        return 'Niente è stato scritto: alcune quote non hanno a chi andare.'
            .($cause !== [] ? ' '.\App\Services\Riparto\MancanzeDelRiparto::frase($cause) : '')
            .' Correggi questi dati e ripeti la richiesta; oppure, se va bene così, ripetila con la presa d\'atto delle quote scoperte'
            .' (accetta_scoperti e una motivazione di almeno 10 caratteri in nota_scoperti): quelle quote restano non assegnate e il piano lo registra.';
    }

    public function report(): bool
    {
        return false;
    }
}
