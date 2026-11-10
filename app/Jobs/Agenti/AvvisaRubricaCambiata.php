<?php

namespace App\Jobs\Agenti;

use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;

/**
 * «Le anagrafiche sono cambiate» (Web Pro Italia, 10/11/2026): un avviso solo anche quando ne cambiano duecento in
 * un colpo (un'importazione). Finché è in coda gli altri non si accodano; parte dopo mezzo minuto, a cose ferme.
 */
class AvvisaRubricaCambiata extends AvvisaSistemaEsterno implements ShouldBeUniqueUntilProcessing
{
    public int $uniqueFor = 600;

    public function __construct()
    {
        parent::__construct('rubrica.cambiata');
    }

    public function uniqueId(): string
    {
        return 'rubrica';
    }
}
