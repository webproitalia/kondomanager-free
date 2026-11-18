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
 *
 * ## Ripiegare il gruppo
 *
 * Chi lavora su altro non vuole sempre l'elenco delle sezioni spalancato: la freccia sulla riga di testa del gruppo lo
 * ripiega, e resta la sola riga di testa. Anche questo si ricorda nel browser, utente per utente. Il gruppo si riapre da solo
 * **solo** quando si apre o si cambia condominio con una scelta esplicita (dalla scelta del menu, dal selettore della barra,
 * dalla ricerca rapida, o aprendo il cruscotto di un condominio dall'elenco dei condomini): non a ogni pagina.
 *
 * «Chiudi il condominio», nella scelta del condominio, toglie quello in uso: il menu torna a «Scegli un condominio».
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
/** Il gruppo del condominio è ripiegato: nel menu resta la sola riga di testa. */
const ripiegato = ref(false);
let lettoPer: string | null = null;
let inAscolto = false;
let ultimaDestinazione = '';
let paginaPrima = '';

const chiave = (utente: string) => `km-condominio-in-uso:${utente}`;
const chiaveRipiegato = (utente: string) => `km-condominio-ripiegato:${utente}`;

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

function leggiRipiegato(utente: string): boolean {
    try {
        return localStorage.getItem(chiaveRipiegato(utente)) === 'true';
    } catch {
        return false;
    }
}

function ripiega(valore: boolean): void {
    if (ripiegato.value === valore) {
        return;
    }
    ripiegato.value = valore;
    try {
        // Aperto è come nasce: non serve ricordarlo.
        if (valore) {
            localStorage.setItem(chiaveRipiegato(lettoPer ?? ''), 'true');
        } else {
            localStorage.removeItem(chiaveRipiegato(lettoPer ?? ''));
        }
    } catch {
        // Vale lo stesso, fino alla prossima pagina intera.
    }
}

export function useCondominioInUso() {
    const page = usePage();
    const utente = String((page.props.auth as { user?: { id?: string | number } } | undefined)?.user?.id ?? '');

    if (lettoPer !== utente) {
        inUso.value = leggi(utente);
        ripiegato.value = leggiRipiegato(utente);
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

    /** Si apre o si cambia condominio con una scelta esplicita: se il gruppo era ripiegato, si riapre. */
    const riapri = (): void => ripiega(false);

    return { inUso, ripiegato, ricorda, dimentica, controllaCon, ripiega, riapri };
}

/**
 * Si è arrivati a una pagina. Aprire un condominio da fuori — il suo cruscotto: dall'elenco dei condomini, dalla dashboard,
 * dalla ricerca — è una scelta esplicita, e il gruppo ripiegato si riapre. Non lo riaprono le altre pagine del condominio (ci
 * si arriva seguendo un collegamento qualunque), il cruscotto del condominio in cui si è già, né il «torna indietro».
 */
export function arrivoA(da: string, a: string, tipo: 'initial' | 'visit' | 'history'): void {
    const cruscotto = a.split(/[?#]/)[0].replace(/\/+$/, '').match(/\/gestionale\/(\d+)$/);
    if (tipo === 'visit' && cruscotto && ! new RegExp(`/gestionale/${cruscotto[1]}(/|$)`).test(da)) {
        ripiega(false);
    }
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
 * L'ascolto delle pagine: lo avvia l'impalcatura (`ImpalcaturaLaterale.vue`), una volta sola per visita. Serve a due cose:
 * cancellare il ricordo quando una sezione del condominio non si trova (quale pagina non è stata trovata lo dice l'ultima
 * visita partita: le letture in anticipo non contano), e riaprire il gruppo quando si apre un condominio da fuori.
 */
export function ascoltaCondominioInUso(): void {
    if (inAscolto || typeof window === 'undefined') {
        return;
    }
    inAscolto = true;
    paginaPrima = window.location.pathname;
    router.on('navigate', (evento) => {
        const { page, type } = (evento as { detail: { page: { url: string }; type: 'initial' | 'visit' | 'history' } }).detail;
        const ora = page.url.split(/[?#]/)[0];
        arrivoA(paginaPrima, ora, type);
        paginaPrima = ora;
    });
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
    ripiegato.value = false;
    lettoPer = null;
}
