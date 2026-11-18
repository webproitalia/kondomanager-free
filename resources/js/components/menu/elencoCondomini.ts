/**
 * L'elenco dei condomini per la ricerca rapida e per il selettore del percorso (Web Pro Italia, 18/11/2026).
 *
 * Dentro un condominio le pagine lo portano già (`condomini`, con l'esercizio aperto di ognuno): si usa quello, senza chiamate.
 * Altrove si chiede una volta sola, e solo quando serve (alla prima apertura della ricerca o del selettore), a
 * `/fetch-condomini`: è la chiamata leggera — id e nome — che usano già la dashboard e i filtri degli elenchi.
 */
import { computed, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { usePermission } from '@/composables/permissions';
import { Permission } from '@/enums/Permission';

export interface CondominioInElenco {
    id: number;
    nome: string;
    esercizio_aperto?: { id: number } | null;
}

// Fuori dalla funzione: l'elenco chiesto una volta vale per tutta la visita, non per una pagina.
const chiesti = ref<CondominioInElenco[] | null>(null);
let richiesta: Promise<void> | null = null;

export function useElencoCondomini() {
    const page = usePage();
    const { getRolePrefix, hasPermission } = usePermission();

    /** La rotta risponde a chi ha il pannello o «Visualizza condomini»: agli altri non si chiede niente. */
    const puoVederli = computed(() => getRolePrefix() === 'admin' || hasPermission([Permission.VIEW_CONDOMINI]));

    const dellaPagina = computed<CondominioInElenco[] | null>(() => {
        const elenco = page.props.condomini as unknown;
        if (! Array.isArray(elenco) || ! elenco.every((c) => c && typeof c.id === 'number' && typeof c.nome === 'string')) {
            return null;
        }

        return elenco as CondominioInElenco[];
    });

    const condomini = computed<CondominioInElenco[]>(() => (puoVederli.value ? (dellaPagina.value ?? chiesti.value ?? []) : []));

    const carica = (): Promise<void> => {
        if (! puoVederli.value || dellaPagina.value || chiesti.value) {
            return Promise.resolve();
        }
        richiesta ??= axios.get('/fetch-condomini')
            .then((risposta) => {
                chiesti.value = Array.isArray(risposta.data) ? risposta.data : [];
            })
            .catch(() => {
                // Senza l'elenco la ricerca porta lo stesso alle sezioni: i condomini si aprono da «Condomini».
            })
            .finally(() => {
                richiesta = null;
            });

        return richiesta;
    };

    return { condomini, carica, puoVederli };
}

/** Per i test: dimentica l'elenco chiesto. */
export function dimenticaElencoCondomini() {
    chiesti.value = null;
    richiesta = null;
}
