/**
 * Il cambio di condominio e di esercizio dalla barra in alto (Web Pro Italia, 18/11/2026).
 *
 * Dove la pagina sa cambiare condominio restando dov'è (la sua testata ha l'elenco dei condomini) si resta nella stessa pagina,
 * con l'altro condominio e il suo esercizio aperto: è quello che fa `PageHeaderGuide` con il menu in alto. Altrove si apre il
 * cruscotto dell'altro condominio: la scheda di un'assemblea, in un altro condominio, non esiste.
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

    test('l\'altro condominio non ha un esercizio aperto: l\'esercizio dell\'indirizzo resta com\'è, come con il menu in alto', () => {
        expect(indirizzoAltroCondominio({ url: '/admin/gestionale/7/esercizi/3/gestioni', attuale: 7, nuovo: 9, sulPosto: true, cruscotto, esercizioAperto: null }))
            .toBe('/admin/gestionale/9/esercizi/3/gestioni');
    });

    test('la pagina non lo sa fare (una scheda): si apre il cruscotto dell\'altro condominio', () => {
        expect(indirizzoAltroCondominio({ url: '/admin/gestionale/7/assemblee/4', attuale: 7, nuovo: 9, sulPosto: false, cruscotto, esercizioAperto: 12 })).toBe(cruscotto);
    });
});

describe('cambiare esercizio', () => {
    test('stesso indirizzo, altro esercizio; il resto non si tocca', () => {
        expect(indirizzoAltroEsercizio('/admin/gestionale/7/esercizi/3/piani-rate?stato=bozza', 5)).toBe('/admin/gestionale/7/esercizi/5/piani-rate?stato=bozza');
        expect(indirizzoAltroEsercizio('/admin/gestionale/7/immobili', 5)).toBe('/admin/gestionale/7/immobili');
    });
});
