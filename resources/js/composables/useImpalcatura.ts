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
export type Impalcatura = 'barra' | 'laterale' | 'alto';

/**
 * Dove si aprono le schede (la scheda di una persona, un modulo): «lato», in una scheda che entra da destra sopra la pagina in
 * cui si è, o «pagina», a pagina intera come sempre. Vale solo con il menu laterale, e solo se la pagina lo dice
 * (`<html data-km-schede="lato">`): è la stessa regola del menu.
 */
export type Schede = 'lato' | 'pagina';

export function schedeScelte(): Schede {
    // Le schede di lato ci sono con le due impalcature nuove; con la testata di sempre («alto») no.
    if (typeof document === 'undefined' || impalcaturaScelta() === 'alto') {
        return 'pagina';
    }

    return document.documentElement.dataset.kmSchede === 'lato' ? 'lato' : 'pagina';
}

export function impalcaturaScelta(): Impalcatura {
    if (typeof document === 'undefined') {
        return 'alto';
    }

    const scritta = document.documentElement.dataset.kmMenu;

    return scritta === 'barra' || scritta === 'laterale' ? scritta : 'alto';
}

export function useImpalcatura() {
    const impalcatura = impalcaturaScelta();

    // `menuLaterale`: una delle due impalcature nuove (pagine con la testata nuova, schede di lato…). `barraInAlto`: quella
    // con la barra dello studio in alto e il menu laterale solo dentro un condominio.
    return { impalcatura, menuLaterale: impalcatura !== 'alto', barraInAlto: impalcatura === 'barra', schedeDiLato: schedeScelte() === 'lato' };
}
