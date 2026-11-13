<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class PrintSettings extends Settings
{
    public ?string $nota_legale_stampe = 'Professione esercitata ai sensi della legge 14 gennaio 2013, n.4';
    public ?string $firma_stampe_path = null;

    // Il fascicolo di bilancio (Web Pro Italia, 13/11/2026): la riga con il nome dello studio in fondo alla copertina,
    // la riga piccola sopra il titolo e la foto di copertina. Il colore è quello scelto in Impostazioni → Aspetto.
    public ?string $fascicolo_studio = null;
    public ?string $fascicolo_sopratitolo = 'Amministrazione condominiale';
    public ?string $fascicolo_copertina_path = null;
    
    public static function group(): string
    {
        return 'print';
    }
}
