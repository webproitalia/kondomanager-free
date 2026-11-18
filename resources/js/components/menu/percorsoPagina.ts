/**
 * Che cosa la pagina dice alla barra in alto sul percorso «studio › condominio › esercizio» (Web Pro Italia, 18/11/2026).
 *
 * Con il menu in alto il condominio e l'esercizio si cambiano dalla testata della pagina (`PageHeaderGuide`), che riceve
 * dalla pagina l'elenco dei condomini e quello degli esercizi. Con il menu laterale i due selettori stanno nella barra, sempre
 * nello stesso posto: la testata della pagina le passa gli stessi elenchi, così la barra offre le stesse scelte.
 */
import { inject, provide, reactive, type InjectionKey } from 'vue';
import type { Esercizio } from '@/types/gestionale/esercizi';

export interface PercorsoPagina {
    /** L'impalcatura è quella di un condominio (`GestionaleLayout`). */
    gestionale: boolean;
    /** La pagina sa cambiare condominio restando dov'è: la sua testata ha l'elenco dei condomini. */
    cambiaCondominioSulPosto: boolean;
    /** Gli esercizi fra cui la pagina fa scegliere, e quello mostrato. */
    esercizi: Esercizio[];
    esercizio: Esercizio | null;
}

const CHIAVE: InjectionKey<PercorsoPagina> = Symbol('km-percorso-pagina');

export function offriPercorsoPagina(gestionale = false): PercorsoPagina {
    const percorso = reactive<PercorsoPagina>({ gestionale, cambiaCondominioSulPosto: false, esercizi: [], esercizio: null });
    provide(CHIAVE, percorso);

    return percorso;
}

/** `null` fuori dall'impalcatura a menu laterale. */
export function usePercorsoPagina(): PercorsoPagina | null {
    return inject(CHIAVE, null);
}

/**
 * Dove si va scegliendo un altro condominio dalla barra.
 *
 * Se la pagina sa cambiare condominio restando dov'è, lo stesso indirizzo con l'altro condominio e — dove l'indirizzo porta un
 * esercizio — con il suo esercizio aperto: è quello che fa la testata della pagina con il menu in alto.
 *
 * Altrimenti il cruscotto dell'altro condominio. E anche quando l'indirizzo, cambiato il condominio, non esisterebbe: la scheda
 * di una cosa (un'assemblea, una fattura, un piano rate hanno un numero che è di questo condominio), o una pagina sotto un
 * esercizio quando l'altro condominio non ne ha uno aperto. Con il menu in alto lì si finiva su «pagina non trovata».
 */
export function indirizzoAltroCondominio(opzioni: {
    url: string;
    attuale: number;
    nuovo: number;
    sulPosto: boolean;
    cruscotto: string;
    esercizioAperto?: number | null;
}): string {
    if (! opzioni.sulPosto) {
        return opzioni.cruscotto;
    }
    // La domanda e l'ancora in coda all'indirizzo si tengono da parte: i numeri che contano sono quelli del percorso.
    const taglio = opzioni.url.search(/[?#]/);
    const coda = taglio === -1 ? '' : opzioni.url.slice(taglio);
    const segmenti = (taglio === -1 ? opzioni.url : opzioni.url.slice(0, taglio)).split('/');
    const posto = segmenti.findIndex((s) => s === String(opzioni.attuale));
    if (posto === -1) {
        return opzioni.cruscotto;
    }
    const postoEsercizio = segmenti.findIndex((_, i) => i > posto && segmenti[i - 1] === 'esercizi' && /^\d+$/.test(segmenti[i]));
    const altriNumeri = segmenti.some((s, i) => i > posto && i !== postoEsercizio && /^\d+$/.test(s));
    if (altriNumeri || (postoEsercizio !== -1 && ! opzioni.esercizioAperto)) {
        return opzioni.cruscotto;
    }
    segmenti[posto] = String(opzioni.nuovo);
    if (postoEsercizio !== -1) {
        segmenti[postoEsercizio] = String(opzioni.esercizioAperto);
    }

    return segmenti.join('/') + coda;
}

/** Lo stesso indirizzo con un altro esercizio. */
export function indirizzoAltroEsercizio(url: string, esercizio: number): string {
    return url.replace(/\/esercizi\/\d+/, `/esercizi/${esercizio}`);
}
