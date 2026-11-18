/**
 * Dove sta il menu dell'applicazione (Web Pro Italia, 18/11/2026): «laterale», l'impalcatura a menu laterale, o «alto», la
 * testata di sempre. Si sceglie in Impostazioni → Aspetto.
 *
 * La scelta arriva scritta sulla pagina (`<html data-km-menu="…">`, vedi `resources/views/app.blade.php`) e non fra i dati
 * condivisi di ogni richiesta: cambia solo salvando l'aspetto, e quel salvataggio ricarica la pagina. Così non costa una
 * lettura delle impostazioni a ogni navigazione.
 */
export type Impalcatura = 'laterale' | 'alto';

export function impalcaturaScelta(): Impalcatura {
    if (typeof document === 'undefined') {
        return 'laterale';
    }

    return document.documentElement.dataset.kmMenu === 'alto' ? 'alto' : 'laterale';
}

export function useImpalcatura() {
    const impalcatura = impalcaturaScelta();

    return { impalcatura, menuLaterale: impalcatura === 'laterale' };
}
