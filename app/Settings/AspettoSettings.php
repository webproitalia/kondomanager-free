<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Aspetto dell'installazione (Web Pro Italia, 09/11/2026): colore principale e angoli.
 * Non è un tema nuovo: cambia solo le variabili dei colori e del raggio, vedi App\Support\Tema.
 */
class AspettoSettings extends Settings
{
    public string $colore = 'grafite';

    public string $angoli = 'standard';

    public static function group(): string
    {
        return 'aspetto';
    }
}
