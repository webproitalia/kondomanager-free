/**
 * Il condominio in uso (Web Pro Italia, 18/11/2026).
 *
 * Il menu laterale non cambia mai: sotto le voci dello studio ci sono sempre, nello stesso posto, le sezioni del condominio
 * (Unità, Tabelle, Conti, Rate…). Dentro un condominio sono le sue. Nelle pagine dello studio lavorano sull'**ultimo
 * condominio aperto**, come nei gestionali da tavolo, dove i comandi lavorano sul condominio scelto in alto: da qualunque
 * pagina «Unità» porta alle unità del condominio in uso.
 *
 * Il condominio in uso si ricorda nel browser, utente per utente: il suo numero, il nome e l'esercizio in cui si lavorava.
 * Si aggiorna ogni volta che si entra in un condominio. Se il condominio non c'è più (una sua sezione risponde «non
 * trovata», o non è più nell'elenco dei condomini) il ricordo si cancella e il menu chiede di sceglierne uno; se non c'è più
 * solo l'esercizio, si dimentica l'esercizio, e le voci che ne hanno bisogno portano all'elenco degli esercizi.
 */
import { ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';

export interface CondominioInUso {
    id: number;
    nome: string;
    esercizio: { id: number; nome: string | null } | null;
}

// Fuori dalla funzione: è lo stesso per il menu, la ricerca e la testata, e vale per tutta la visita.
const inUso = ref<CondominioInUso | null>(null);
let lettoPer: string | null = null;
let inAscolto = false;
let ultimaDestinazione = '';

const chiave = (utente: string) => `km-condominio-in-uso:${utente}`;

function leggi(utente: string): CondominioInUso | null {
    try {
        const scritto = JSON.parse(localStorage.getItem(chiave(utente)) ?? 'null');
        if (scritto && typeof scritto.id === 'number' && typeof scritto.nome === 'string') {
            const esercizio = scritto.esercizio && typeof scritto.esercizio.id === 'number'
                ? { id: scritto.esercizio.id, nome: typeof scritto.esercizio.nome === 'string' ? scritto.esercizio.nome : null }
                : null;

            return { id: scritto.id, nome: scritto.nome, esercizio };
        }
    } catch {
        // Senza memoria del browser, o con un ricordo illeggibile: nessun condominio in uso.
    }

    return null;
}

function scrivi(utente: string, condominio: CondominioInUso | null): void {
    try {
        if (condominio) {
            localStorage.setItem(chiave(utente), JSON.stringify(condominio));
        } else {
            localStorage.removeItem(chiave(utente));
        }
    } catch {
        // Il menu funziona lo stesso: il condominio in uso vale fino alla prossima pagina intera.
    }
}

export function useCondominioInUso() {
    const page = usePage();
    const utente = String((page.props.auth as { user?: { id?: string | number } } | undefined)?.user?.id ?? '');

    if (lettoPer !== utente) {
        inUso.value = leggi(utente);
        lettoPer = utente;
    }

    /** Si è dentro questo condominio: da adesso è quello in uso. */
    const ricorda = (condominio: CondominioInUso): void => {
        const prima = inUso.value;
        // Una pagina che non porta l'esercizio non cancella quello in cui si lavorava nello stesso condominio.
        const esercizio = condominio.esercizio ?? (prima?.id === condominio.id ? prima.esercizio : null);
        if (prima && prima.id === condominio.id && prima.nome === condominio.nome && prima.esercizio?.id === esercizio?.id && prima.esercizio?.nome === esercizio?.nome) {
            return;
        }
        inUso.value = { id: condominio.id, nome: condominio.nome, esercizio };
        scrivi(utente, inUso.value);
    };

    const dimentica = (): void => {
        inUso.value = null;
        scrivi(utente, null);
    };

    /**
     * Il condominio in uso non è più fra quelli dell'elenco: il ricordo si cancella. L'elenco dev'essere quello intero (quello
     * chiesto al server): le pagine a volte ne portano uno parziale, che non dice niente su chi manca.
     */
    const controllaCon = (elenco: { id: number }[]): void => {
        if (inUso.value && elenco.length > 0 && ! elenco.some((c) => c.id === inUso.value?.id)) {
            dimentica();
        }
    };

    return { inUso, ricorda, dimentica, controllaCon };
}

/**
 * Una pagina ha risposto «non trovata». Se era una sezione del condominio in uso — una di quelle a cui porta il menu — il
 * ricordo è vecchio:
 *
 * - `/gestionale/7` o `/gestionale/7/immobili`: il condominio non c'è più, e si dimentica;
 * - `/gestionale/7/esercizi/3/gestioni`, con l'esercizio ricordato: non c'è più l'esercizio, e si dimentica solo quello (se
 *   manca anche il condominio lo dirà la voce successiva, che a quel punto porta a `/gestionale/7/esercizi`).
 *
 * Ogni altra pagina non trovata (un'unità eliminata, un indirizzo scritto male) non dice niente sul condominio: il ricordo resta.
 */
export function paginaNonTrovata(percorso: string): void {
    const ricordo = inUso.value;
    const trovato = percorso.split(/[?#]/)[0].replace(/\/+$/, '').match(/\/gestionale\/(\d+)(\/.*)?$/);
    if (! ricordo || ! trovato || Number(trovato[1]) !== ricordo.id) {
        return;
    }
    const sezione = trovato[2] ?? '';
    if (sezione === '' || /^\/[a-z-]+$/.test(sezione)) {
        inUso.value = null;
    } else if (ricordo.esercizio && new RegExp(`^/esercizi/${ricordo.esercizio.id}/[a-z-]+$`).test(sezione)) {
        inUso.value = { ...ricordo, esercizio: null };
    } else {
        return;
    }
    scrivi(lettoPer ?? '', inUso.value);
}

/**
 * L'ascolto delle pagine non trovate: lo avvia l'impalcatura (`ImpalcaturaLaterale.vue`), una volta sola per visita.
 * Quale pagina non è stata trovata lo dice l'ultima visita partita (le letture in anticipo non contano).
 */
export function ascoltaCondominioInUso(): void {
    if (inAscolto || typeof window === 'undefined') {
        return;
    }
    inAscolto = true;
    router.on('before', (evento) => {
        const visita = (evento as { detail: { visit: { url: URL | string; prefetch?: boolean } } }).detail.visit;
        if (! visita.prefetch) {
            ultimaDestinazione = typeof visita.url === 'string' ? visita.url : visita.url.pathname;
        }
    });
    router.on('httpException', (evento) => {
        if ((evento as { detail: { response?: { status?: number } } }).detail.response?.status === 404) {
            paginaNonTrovata(ultimaDestinazione);
        }
    });
}

/** Per i test: dimentica tutto, anche per quale utente si era letto. */
export function azzeraCondominioInUso(): void {
    inUso.value = null;
    lettoPer = null;
}
