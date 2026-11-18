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
 * esercizio — con il suo esercizio aperto: è quello che fa la testata della pagina con il menu in alto. Altrimenti il cruscotto
 * dell'altro condominio: l'indirizzo di una scheda (un'assemblea, una fattura) in un altro condominio non esiste.
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
    const segmenti = opzioni.url.split('/');
    const posto = segmenti.findIndex((s) => s === String(opzioni.attuale));
    if (posto !== -1) {
        segmenti[posto] = String(opzioni.nuovo);
    }
    const postoEsercizio = segmenti.findIndex((_, i) => segmenti[i - 1] === 'esercizi');
    if (postoEsercizio !== -1 && opzioni.esercizioAperto) {
        segmenti[postoEsercizio] = String(opzioni.esercizioAperto);
    }

    return segmenti.join('/');
}

/** Lo stesso indirizzo con un altro esercizio. */
export function indirizzoAltroEsercizio(url: string, esercizio: number): string {
    return url.replace(/\/esercizi\/\d+/, `/esercizi/${esercizio}`);
}
