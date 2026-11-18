/**
 * Il cambio di condominio e di esercizio dalla barra in alto (Web Pro Italia, 18/11/2026).
 *
 * Dove la pagina sa cambiare condominio restando dov'è (la sua testata ha l'elenco dei condomini) si resta nella stessa pagina,
 * con l'altro condominio e il suo esercizio aperto: è quello che fa `PageHeaderGuide` con il menu in alto. Altrove, e dove
 * l'indirizzo nell'altro condominio non esisterebbe (la scheda di un'assemblea), si apre il cruscotto dell'altro condominio.
 */
import { describe, expect, test } from 'vitest';
import { indirizzoAltroCondominio, indirizzoAltroEsercizio } from './percorsoPagina';

const cruscotto = '/admin/gestionale/9';

describe('cambiare condominio', () => {
    test('la pagina lo sa fare: stesso indirizzo, altro condominio', () => {
        expect(indirizzoAltroCondominio({ url: '/admin/gestionale/7/immobili', attuale: 7, nuovo: 9, sulPosto: true, cruscotto })).toBe('/admin/gestionale/9/immobili');
    });

    test('con un esercizio nell\'indirizzo: anche l\'esercizio aperto dell\'altro condominio', () => {
        expect(indirizzoAltroCondominio({ url: '/admin/gestionale/7/esercizi/3/gestioni', attuale: 7, nuovo: 9, sulPosto: true, cruscotto, esercizioAperto: 12 }))
            .toBe('/admin/gestionale/9/esercizi/12/gestioni');
        // Il condominio 1 con l'esercizio 1: cambia il primo «1», quello del condominio, e l'esercizio al suo posto.
        expect(indirizzoAltroCondominio({ url: '/admin/gestionale/1/esercizi/1/rendiconto', attuale: 1, nuovo: 9, sulPosto: true, cruscotto, esercizioAperto: 12 }))
            .toBe('/admin/gestionale/9/esercizi/12/rendiconto');
    });

    test('la domanda in coda all\'indirizzo resta', () => {
        expect(indirizzoAltroCondominio({ url: '/admin/gestionale/7/fatture?stato=aperte', attuale: 7, nuovo: 9, sulPosto: true, cruscotto })).toBe('/admin/gestionale/9/fatture?stato=aperte');
        expect(indirizzoAltroCondominio({ url: '/admin/gestionale/7/fatture?pagina=2#elenco', attuale: 7, nuovo: 9, sulPosto: true, cruscotto })).toBe('/admin/gestionale/9/fatture?pagina=2#elenco');
    });

    test('l\'altro condominio non ha un esercizio aperto: una pagina sotto un esercizio non ci sarebbe, si apre il suo cruscotto', () => {
        expect(indirizzoAltroCondominio({ url: '/admin/gestionale/7/esercizi/3/gestioni', attuale: 7, nuovo: 9, sulPosto: true, cruscotto, esercizioAperto: null })).toBe(cruscotto);
    });

    test('la scheda di una cosa ha un numero che è di questo condominio: nell\'altro non c\'è, si apre il suo cruscotto', () => {
        for (const url of ['/admin/gestionale/7/assemblee/4', '/admin/gestionale/7/immobili/12/anagrafiche', '/admin/gestionale/7/esercizi/3/piani-rate/8', '/admin/gestionale/7/fatture/15/edit']) {
            expect(indirizzoAltroCondominio({ url, attuale: 7, nuovo: 9, sulPosto: true, cruscotto, esercizioAperto: 12 }), url).toBe(cruscotto);
        }
    });

    test('la pagina non lo sa fare: si apre il cruscotto dell\'altro condominio', () => {
        expect(indirizzoAltroCondominio({ url: '/admin/gestionale/7/controlli-import', attuale: 7, nuovo: 9, sulPosto: false, cruscotto, esercizioAperto: 12 })).toBe(cruscotto);
    });
});

describe('cambiare esercizio', () => {
    test('stesso indirizzo, altro esercizio; il resto non si tocca', () => {
        expect(indirizzoAltroEsercizio('/admin/gestionale/7/esercizi/3/piani-rate?stato=bozza', 5)).toBe('/admin/gestionale/7/esercizi/5/piani-rate?stato=bozza');
        expect(indirizzoAltroEsercizio('/admin/gestionale/7/immobili', 5)).toBe('/admin/gestionale/7/immobili');
    });
});
