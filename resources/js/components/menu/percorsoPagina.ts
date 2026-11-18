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
