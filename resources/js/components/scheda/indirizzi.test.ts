/**
 * Gli indirizzi che si aprono nella scheda laterale (Web Pro Italia, 18/11/2026).
 *
 * L'elenco è chiuso, e ogni indirizzo è stato provato nella scheda: la scheda, il modulo nuovo e la modifica di persone,
 * fornitori e unità con le loro linguette; nuovo e modifica di condomini, tabelle e assemblee. Tutto il resto resta a pagina
 * intera: gli elenchi (sono le pagine da cui le schede si aprono), e le pagine grandi, che nella scheda starebbero strette.
 *
 * Questo file tiene fermo il confine: aggiungere una pagina all'elenco è una scelta da provare, non un effetto collaterale.
 */
import { describe, expect, test } from 'vitest';
import { apreDiLato, percorsoDi } from './indirizzi';

describe('si aprono di lato', () => {
    test.each([
        // Persone
        '/admin/anagrafiche/create',
        '/admin/anagrafiche/12',
        '/admin/anagrafiche/12/edit',
        '/admin/anagrafiche/12/documenti',
        '/admin/anagrafiche/12/documenti/create',
        '/admin/anagrafiche/12/documenti/8/edit',
        // Fornitori
        '/admin/fornitori/create',
        '/admin/fornitori/5',
        '/admin/fornitori/5/edit',
        '/admin/fornitori/5/anagrafiche',
        '/admin/fornitori/5/anagrafiche/create',
        '/admin/fornitori/5/documenti',
        '/admin/fornitori/5/documenti/create',
        '/admin/fornitori/5/documenti/9/edit',
        '/admin/fornitori/5/situazione-debitoria',
        // Unità del condominio
        '/admin/gestionale/1/immobili/create',
        '/admin/gestionale/1/immobili/4',
        '/admin/gestionale/1/immobili/4/edit',
        '/admin/gestionale/1/immobili/4/anagrafiche',
        '/admin/gestionale/1/immobili/4/anagrafiche/create',
        '/admin/gestionale/1/immobili/4/anagrafiche/7/edit',
        '/admin/gestionale/1/immobili/4/documenti',
        '/admin/gestionale/1/immobili/4/documenti/create',
        '/admin/gestionale/1/immobili/4/documenti/10/edit',
        '/admin/gestionale/1/immobili/4/pertinenze',
        // Condomini, tabelle, assemblee: nuovo e modifica
        '/condomini/create',
        '/condomini/4/edit',
        '/admin/gestionale/1/tabelle/create',
        '/admin/gestionale/1/tabelle/3/edit',
        '/admin/gestionale/1/assemblee/create',
        '/admin/gestionale/1/assemblee/4/edit',
    ])('%s', (indirizzo) => {
        expect(apreDiLato(indirizzo)).toBe(true);
    });
});

describe('restano a pagina intera', () => {
    test.each([
        // Gli elenchi: sono le pagine da cui le schede si aprono.
        '/admin/anagrafiche',
        '/admin/fornitori',
        '/admin/gestionale/1/immobili',
        '/condomini',
        '/admin/gestionale/1/tabelle',
        '/admin/gestionale/1/assemblee',
        // Le pagine grandi.
        '/admin/gestionale/1/assemblee/4',
        '/admin/gestionale/1/assemblee/4/verbale',
        '/admin/gestionale/1/assemblee/4/convocazione',
        '/admin/gestionale/1/assemblee/modelli',
        '/admin/gestionale/1/tabelle/3/quote',
        '/admin/gestionale/1/immobili/4/passaggi/create',
        '/admin/gestionale/1/fatture/create',
        '/admin/gestionale/1/fatture/9/edit',
        '/admin/gestionale/1/movimenti-rate/create',
        '/admin/gestionale/1/esercizi/1/piani-rate/2',
        '/admin/gestionale/1/esercizi/1/rendiconto',
        // Il cruscotto di un condominio, la dashboard, le impostazioni.
        '/admin/gestionale/1',
        '/condomini/4',
        '/admin/dashboard',
        '/impostazioni/aspetto',
        // Il portale dei condòmini non ha schede di lato.
        '/user/anagrafiche/12',
        '/user/anagrafiche/create',
        // Pagine che nessun collegamento apre: fuori dall'elenco finché non servono.
        '/admin/fornitori/5/documenti/9',
        '/admin/gestionale/1/immobili/4/anagrafiche/7',
    ])('%s', (indirizzo) => {
        expect(apreDiLato(indirizzo)).toBe(false);
    });
});

describe('il confronto è sul percorso', () => {
    test('la domanda, l\'ancora, la barra in fondo e il nome del sito non contano', () => {
        expect(apreDiLato('/admin/anagrafiche/12?scheda=dati#recapiti')).toBe(true);
        expect(apreDiLato('/admin/anagrafiche/12/')).toBe(true);
        expect(apreDiLato('https://studio.example.test/admin/anagrafiche/12')).toBe(true);
        expect(percorsoDi('https://studio.example.test/admin/anagrafiche/?pagina=2')).toBe('/admin/anagrafiche');
    });

    test('per intero: un indirizzo che comincia come una scheda non è una scheda', () => {
        expect(apreDiLato('/admin/anagrafiche/12/altro')).toBe(false);
        expect(apreDiLato('/admin/anagrafiche/nuova')).toBe(false);
        expect(apreDiLato('/x/admin/anagrafiche/12')).toBe(false);
    });
});
