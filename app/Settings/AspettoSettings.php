<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Aspetto dell'installazione (Web Pro Italia, 09/11/2026): colore principale e angoli.
 * Non è un tema nuovo: cambia solo le variabili dei colori e del raggio, vedi App\Support\Tema.
 *
 * Dal 18/11/2026 anche dove sta il menu: «laterale» (l'impalcatura a menu laterale, predefinita) o «alto» (la testata di sempre);
 * e dove si aprono le schede e i moduli: «lato» (una scheda che entra da destra, predefinita) o «pagina» (a pagina intera).
 *
 * Dal 21/11/2026 anche lo stile dell'area di lavoro: «professionale» (superfici piane, righe sottili, il carattere del sistema:
 * predefinito) o «morbido» (quello di prima). Vedi resources/css/stile-professionale.css.
 */
class AspettoSettings extends Settings
{
    public string $colore = 'grafite';

    public string $angoli = 'standard';

    public string $menu = 'barra';

    public string $schede = 'lato';

    public string $stile = 'professionale';

    public static function group(): string
    {
        return 'aspetto';
    }
}
