/**
 * Gli indirizzi che si aprono nella scheda laterale invece di cambiare pagina (Web Pro Italia, 18/11/2026).
 *
 * È un elenco chiuso: ogni indirizzo qui dentro è stato provato davvero nella scheda (aprire, salvare, errore di validazione,
 * chiudere, tornare). Le pagine grandi — fatture, incassi, piani rate, rendiconto, verbale, l'assemblea in sala, il passaggio
 * di un'unità — restano a pagina intera: nella scheda starebbero strette, o hanno una loro altezza agganciata allo schermo.
 *
 * Per aggiungerne una: una riga qui, la prova nella scheda, e un caso in `indirizzi.test.ts`.
 * Il confronto è sul solo percorso (senza la domanda), per intero: `/admin/anagrafiche` (l'elenco) non è una scheda.
 */
const N = '\\d+';
const condominio = `/admin/gestionale/${N}`;

const FORME: string[] = [
    // Persone (la rubrica): la scheda, nuova, modifica, e i documenti della persona.
    '/admin/anagrafiche/create',
    `/admin/anagrafiche/${N}`,
    `/admin/anagrafiche/${N}/edit`,
    `/admin/anagrafiche/${N}/documenti`,
    `/admin/anagrafiche/${N}/documenti/create`,
    `/admin/anagrafiche/${N}/documenti/${N}/edit`,

    // Fornitori: la scheda con le sue linguette (referenti, documenti, situazione debitoria), nuovo, modifica.
    '/admin/fornitori/create',
    `/admin/fornitori/${N}`,
    `/admin/fornitori/${N}/edit`,
    `/admin/fornitori/${N}/anagrafiche`,
    `/admin/fornitori/${N}/anagrafiche/create`,
    `/admin/fornitori/${N}/documenti`,
    `/admin/fornitori/${N}/documenti/create`,
    `/admin/fornitori/${N}/documenti/${N}/edit`,
    `/admin/fornitori/${N}/situazione-debitoria`,

    // Unità del condominio: la scheda con le sue linguette (titolari, documenti, pertinenze), nuova, modifica.
    // Il passaggio di proprietà (`…/passaggi/create`) resta a pagina intera: è una pagina grande, con il suo pannello agganciato.
    `${condominio}/immobili/create`,
    `${condominio}/immobili/${N}`,
    `${condominio}/immobili/${N}/edit`,
    `${condominio}/immobili/${N}/anagrafiche`,
    `${condominio}/immobili/${N}/anagrafiche/create`,
    `${condominio}/immobili/${N}/anagrafiche/${N}/edit`,
    `${condominio}/immobili/${N}/documenti`,
    `${condominio}/immobili/${N}/documenti/create`,
    `${condominio}/immobili/${N}/documenti/${N}/edit`,
    `${condominio}/immobili/${N}/pertinenze`,

    // Condomini: nuovo, modifica.
    '/condomini/create',
    `/condomini/${N}/edit`,

    // Tabelle millesimali: nuova, modifica (le quote restano a pagina intera).
    `${condominio}/tabelle/create`,
    `${condominio}/tabelle/${N}/edit`,

    // Assemblee: nuova, modifica (l'assemblea in sala resta a pagina intera).
    `${condominio}/assemblee/create`,
    `${condominio}/assemblee/${N}/edit`,
];

export const INDIRIZZI_DI_LATO: RegExp[] = FORME.map((forma) => new RegExp(`^${forma}$`));

/** L'indirizzo senza la domanda, senza l'ancora e senza la barra in fondo. */
export const percorsoDi = (indirizzo: string): string => {
    const senzaSito = indirizzo.replace(/^[a-z]+:\/\/[^/]+/i, '');

    return senzaSito.split(/[?#]/)[0].replace(/\/+$/, '') || '/';
};

/** Questo indirizzo si apre nella scheda laterale? */
export const apreDiLato = (indirizzo: string): boolean => {
    const percorso = percorsoDi(indirizzo);

    return INDIRIZZI_DI_LATO.some((forma) => forma.test(percorso));
};
