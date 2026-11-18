/**
 * Dove sta il menu dell'applicazione (Web Pro Italia, 18/11/2026): «laterale», l'impalcatura a menu laterale, o «alto», la
 * testata di sempre. Si sceglie in Impostazioni → Aspetto, e il predefinito è il menu laterale.
 *
 * La scelta arriva scritta sulla pagina (`<html data-km-menu="…">`, vedi `resources/views/app.blade.php`) e non fra i dati
 * condivisi di ogni richiesta: cambia solo salvando l'aspetto, e quel salvataggio ricarica la pagina. Così non costa una
 * lettura delle impostazioni a ogni navigazione.
 *
 * ⚠️ Il menu laterale vale solo se la pagina lo dice. Dove la scelta non è scritta — una pagina che non passa dal modello
 * dell'applicazione, i test delle singole pagine — resta l'impalcatura di sempre: è la via di ritorno, e i test che montano una
 * pagina continuano a guardare la pagina, non il menu.
 */
export type Impalcatura = 'laterale' | 'alto';

export function impalcaturaScelta(): Impalcatura {
    if (typeof document === 'undefined') {
        return 'alto';
    }

    return document.documentElement.dataset.kmMenu === 'laterale' ? 'laterale' : 'alto';
}

export function useImpalcatura() {
    const impalcatura = impalcaturaScelta();

    return { impalcatura, menuLaterale: impalcatura === 'laterale' };
}
