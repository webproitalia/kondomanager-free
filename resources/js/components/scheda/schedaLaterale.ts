/**
 * La scheda laterale (Web Pro Italia, 18/11/2026): la scheda di una persona, di un'unità o un modulo da compilare si aprono
 * in una scheda che entra da destra, sopra la pagina in cui si è, invece di cambiare pagina.
 *
 * ## Come funziona
 *
 * **La pagina dentro la scheda è la pagina vera.** Aprire una scheda è una visita normale di Inertia all'indirizzo della
 * pagina: cambia l'indirizzo del browser, i moduli salvano e mostrano gli errori come sempre, le linguette e i collegamenti
 * funzionano, «Indietro» del browser chiude. Quello che cambia è come l'impalcatura la disegna: invece del menu e della barra,
 * la mette in un pannello a destra (`ImpalcaturaLaterale.vue`), e sotto lascia **una copia ferma della pagina da cui si è
 * partiti**, presa un attimo prima di lasciarla. Chiudendo si torna a quella pagina nella cronologia: Inertia la ridisegna
 * com'era, allo stesso punto, senza richiederla al server.
 *
 * Non c'è una libreria e non cambia niente sul server: le rotte, le risposte e le pagine sono quelle di sempre. È la ragione
 * della scelta: una pagina montata «a parte» sopra un'altra (come fanno le librerie di finestre per Inertia) non vede i suoi
 * dati con `usePage()`, e i suoi moduli e i suoi collegamenti navigherebbero la pagina di sotto; andrebbe riscritta. Così no.
 *
 * ## Quando una visita diventa una scheda
 *
 * Quando si va da una pagina qualunque a un indirizzo dell'elenco (`indirizzi.ts`): un clic su un collegamento, una voce di
 * un menu a tendina, una riga di un elenco. Non quando ci si arriva con l'indirizzo, con «Ricarica» o con Ctrl/⌘+clic: lì la
 * pagina è a pagina intera, come sempre. Dentro la scheda, un collegamento verso un altro indirizzo dell'elenco cambia il
 * contenuto della scheda; uno verso un'altra pagina la chiude e ci va; uno verso la pagina di sotto è «chiudi».
 *
 * ## Salvare
 *
 * Un modulo salvato porta dove lo manda il server. Quasi sempre è l'elenco: la scheda si chiude da sé, l'elenco è riletto e il
 * messaggio di conferma si vede. Se resta su una pagina da scheda (la modifica appena salvata), resta nella scheda, e alla
 * chiusura la pagina di sotto si rilegge. Con errori di validazione il server rimanda al modulo: resta nella scheda, con quello
 * che si era scritto.
 *
 * **L'elenco torna com'era.** Il server rimanda all'elenco «nudo», senza la ricerca, la pagina e l'ordine che si avevano
 * (stanno nell'indirizzo: `useTabellaServer.ts`). La scheda serve a non perdere il filo, quindi dopo il salvataggio si torna
 * all'indirizzo della pagina di sotto, con tutto: una seconda lettura, che tiene il messaggio di conferma appena arrivato
 * (`except: ['flash']`: il server non lo ridarebbe, è già stato mostrato una volta).
 */
import { computed, reactive, readonly } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { schedeScelte } from '@/composables/useImpalcatura';
import { apreDiLato, percorsoDi } from './indirizzi';

export type FaseScheda = 'chiusa' | 'apertura' | 'aperta' | 'chiusura';

/** Quanto dura l'uscita del pannello, in millisecondi: la stessa durata è in `menu-laterale.css`. */
export const DURATA_USCITA = 180;

const stato = reactive({
    fase: 'chiusa' as FaseScheda,
    /** L'indirizzo, con la sua domanda, della pagina di sotto. */
    base: '',
    /** Quante voci la scheda ha aggiunto alla cronologia: chiudendo si torna indietro di tante. */
    passi: 0,
    /** Si è usato «Indietro» o «Avanti» dentro la scheda: il conto dei passi non è più sicuro. */
    cronologiaIncerta: false,
    /** Dentro la scheda qualcosa è stato salvato: chiudendo, la pagina di sotto si rilegge. */
    salvato: false,
    /** Il modulo nella scheda ha modifiche non salvate: prima di chiudere si chiede. */
    modificato: false,
    /** Si è chiesto di chiudere con modifiche non salvate: l'impalcatura mostra la domanda. */
    daConfermare: false,
    /** La scheda si è appena chiusa da sola (un salvataggio ha portato all'elenco): un pannello vuoto scivola via. */
    uscita: false,
});

// La copia ferma della pagina di sotto e il punto a cui era scorsa: nodi e numeri, non stato da osservare.
let fondo: HTMLElement | null = null;
let scorrimentoFondo = 0;
let scorrimentiInterni: [number, number, number][] = [];

// La visita in corso, per contare i passi nella cronologia e per sapere se è stata un salvataggio.
let visita: { rimpiazza: boolean; da: string; salva: boolean } | null = null;
let avviata = false;

const indirizzoDiAdesso = () => window.location.pathname + window.location.search;
const stessaPagina = (a: string, b: string) => percorsoDi(a) === percorsoDi(b) && (a.split('#')[0].split('?')[1] ?? '') === (b.split('#')[0].split('?')[1] ?? '');

/**
 * La copia ferma della pagina: i nodi così come sono a video, senza niente che si possa toccare.
 * Via gli identificativi e i nomi dei campi (nella scheda un modulo può averne di uguali, e un'etichetta finirebbe sul campo
 * sbagliato), via ciò che ricaricherebbe qualcosa (riquadri con altre pagine, filmati).
 */
function fotografa(): HTMLElement | null {
    const radice = document.querySelector<HTMLElement>('.km-impalcatura');
    if (! radice) {
        return null;
    }
    const copia = radice.cloneNode(true) as HTMLElement;

    // Gli scorrimenti interni (il menu, una tabella larga): si riapplicano alla copia quando è a video.
    scorrimentiInterni = [];
    const veri = radice.querySelectorAll<HTMLElement>('*');
    veri.forEach((nodo, i) => {
        if (nodo.scrollTop || nodo.scrollLeft) {
            scorrimentiInterni.push([i, nodo.scrollTop, nodo.scrollLeft]);
        }
    });

    copia.querySelectorAll('iframe, video, audio, script, [data-scheda-in-arrivo]').forEach((nodo) => nodo.remove());
    copia.querySelectorAll('[id], [name], [for], [autofocus]').forEach((nodo) => {
        ['id', 'name', 'for', 'autofocus'].forEach((attributo) => nodo.removeAttribute(attributo));
    });
    copia.removeAttribute('id');
    copia.setAttribute('inert', '');
    copia.setAttribute('aria-hidden', 'true');

    return copia;
}

function azzera(opzioni: { uscita?: boolean } = {}) {
    document.documentElement.classList.remove('km-scheda-con-barra');
    stato.fase = 'chiusa';
    stato.base = '';
    stato.passi = 0;
    stato.cronologiaIncerta = false;
    stato.salvato = false;
    stato.modificato = false;
    stato.daConfermare = false;
    fondo = null;
    scorrimentiInterni = [];
    if (opzioni.uscita) {
        stato.uscita = true;
        window.setTimeout(() => {
            stato.uscita = false;
        }, DURATA_USCITA + 80);
    }
}

/** Prima di ogni visita: decide se sta nascendo una scheda, e riconosce il «torna alla pagina di sotto». */
function primaDellaVisita(evento: { detail: { visit: { url: URL | string; method: string; replace?: boolean; prefetch?: boolean; async?: boolean } } }): boolean | void {
    const v = evento.detail.visit;
    if (v.prefetch) {
        return;
    }
    const metodo = String(v.method).toLowerCase();
    visita = { rimpiazza: !! v.replace, da: window.location.href, salva: metodo !== 'get' };
    if (metodo !== 'get') {
        return;
    }
    const destinazione = typeof v.url === 'string' ? v.url : v.url.pathname + v.url.search;

    if (stato.fase === 'chiusa') {
        // Da una pagina qualunque a un indirizzo dell'elenco: nasce la scheda. La copia si prende adesso, prima di lasciarla.
        if (apreDiLato(destinazione) && ! apreDiLato(window.location.pathname)) {
            const copia = fotografa();
            if (copia) {
                // Dove la barra di scorrimento occupa spazio, il suo posto resta: la pagina di sotto non si allarga di colpo.
                if (window.innerWidth > document.documentElement.clientWidth) {
                    document.documentElement.classList.add('km-scheda-con-barra');
                }
                fondo = copia;
                scorrimentoFondo = window.scrollY;
                stato.base = indirizzoDiAdesso();
                stato.passi = 0;
                stato.cronologiaIncerta = false;
                stato.salvato = false;
                stato.modificato = false;
                stato.uscita = false;
                stato.fase = 'apertura';
            }
        }

        return;
    }

    // Dentro la scheda, un collegamento alla pagina di sotto («Torna all'elenco») è «chiudi»: si torna lì, com'era.
    if (stato.fase === 'aperta' && ! v.async && stessaPagina(destinazione, stato.base)) {
        chiudi();

        return false;
    }
}

/** Dopo ogni cambio di pagina: la scheda resta, cambia contenuto o è finita. */
function dopoLaNavigazione(evento: { detail: { page: { url: string }; type?: string } }) {
    if (stato.fase === 'chiusa') {
        return;
    }
    const arrivo = evento.detail.page.url;
    const dallaCronologia = evento.detail.type === 'history';

    if (! apreDiLato(arrivo)) {
        // Si è usciti dalla scheda: chiusa da noi, da «Indietro», o da un salvataggio che ha portato altrove.
        const tornatiSotto = stessaPagina(arrivo, stato.base);
        const daRileggere = stato.salvato && tornatiSotto && dallaCronologia;
        const daRiportare = tornatiSotto && ! dallaCronologia ? scorrimentoFondo : null;
        // Un salvataggio ha portato all'elenco di sotto, ma «nudo»: senza la ricerca, la pagina e l'ordine che si avevano.
        const conIFiltri = ! tornatiSotto && ! dallaCronologia && !! visita?.salva && stato.base.includes('?') && ! arrivo.includes('?') && percorsoDi(arrivo) === percorsoDi(stato.base)
            ? stato.base
            : null;
        azzera({ uscita: stato.fase !== 'chiusura' });
        if (daRileggere) {
            // La cronologia ha ridato la pagina com'era prima del salvataggio: si rilegge, restando dove si è.
            router.reload();
        }
        if (conIFiltri) {
            // Si torna all'elenco com'era. Il messaggio di conferma resta quello appena arrivato: il server non lo ridarebbe.
            // Un attimo dopo: questa visita deve prima finire.
            window.setTimeout(() => router.visit(conIFiltri, { replace: true, except: ['flash'] }), 0);
        }
        if (daRiportare !== null) {
            window.requestAnimationFrame(() => window.scrollTo(0, daRiportare));
        }

        return;
    }

    if (stato.fase === 'chiusura') {
        // Si stava chiudendo e la cronologia ha portato a un'altra pagina da scheda: si va alla pagina di sotto, e basta.
        router.visit(stato.base, { replace: true });

        return;
    }

    if (dallaCronologia) {
        stato.cronologiaIncerta = true;
    } else if (visita && ! visita.rimpiazza && visita.da !== window.location.href) {
        stato.passi += 1;
    }
    stato.fase = 'aperta';
    // Un'altra pagina: il modulo di prima non c'è più. Dopo un salvataggio decide l'esito: riuscito azzera (`dopoIlSuccesso`),
    // con errori di validazione il modulo è ancora lì, con quello che si era scritto, e resta «modificato».
    if (! visita?.salva) {
        stato.modificato = false;
    }
}

/** Una visita finita bene: se era un salvataggio, la pagina di sotto non è più aggiornata. */
function dopoIlSuccesso() {
    if (stato.fase !== 'chiusa' && visita?.salva) {
        stato.salvato = true;
        stato.modificato = false;
        // Il messaggio di conferma sta in testa alla pagina: se si resta nella scheda, la scheda torna in cima.
        document.querySelector('[data-scheda-laterale]')?.scrollTo({ top: 0 });
    }
}

/** Una visita finita, comunque sia andata: se la scheda non è mai arrivata (un errore, un'altra pagina), non resta a metà. */
function dopoLaVisita() {
    if (stato.fase === 'apertura' && ! apreDiLato(window.location.pathname)) {
        azzera();
    }
    visita = null;
}

/** Mette in ascolto la scheda laterale. Una volta sola, e solo se le schede di lato sono accese (Impostazioni → Aspetto). */
export function avviaSchedaLaterale(): void {
    if (avviata || typeof window === 'undefined' || schedeScelte() !== 'lato') {
        return;
    }
    avviata = true;
    router.on('before', primaDellaVisita as never);
    router.on('navigate', dopoLaNavigazione as never);
    router.on('success', dopoIlSuccesso);
    router.on('finish', dopoLaVisita);
}

/** Chiude la scheda e torna alla pagina di sotto. Con modifiche non salvate, prima chiede. */
export function chiudi(opzioni: { senzaChiedere?: boolean } = {}): void {
    if (stato.fase !== 'aperta') {
        return;
    }
    if (stato.modificato && ! opzioni.senzaChiedere) {
        stato.daConfermare = true;

        return;
    }
    stato.daConfermare = false;
    stato.fase = 'chiusura';
    const [passi, incerta, base] = [stato.passi, stato.cronologiaIncerta, stato.base];
    window.setTimeout(() => {
        if (! incerta && passi >= 1) {
            window.history.go(-passi);
        } else {
            router.visit(base, { replace: true });
        }
    }, DURATA_USCITA);
}

/** Lascia la pagina dov'è e toglie la scheda: la stessa pagina, a pagina intera. */
export function apriAPaginaIntera(): void {
    if (stato.fase === 'aperta') {
        azzera();
    }
}

export function resta(): void {
    stato.daConfermare = false;
}

/** Il modulo nella scheda è stato toccato. */
export function segnaModificato(): void {
    if (stato.fase === 'aperta') {
        stato.modificato = true;
    }
}

/** La copia ferma della pagina di sotto, da mettere a video: i nodi, il punto a cui era scorsa e gli scorrimenti interni. */
export function fondoDellaScheda(): { nodi: HTMLElement | null; scorrimento: number; interni: [number, number, number][] } {
    return { nodi: fondo, scorrimento: scorrimentoFondo, interni: scorrimentiInterni };
}

export function useSchedaLaterale() {
    const page = usePage();

    /** La pagina a video sta nella scheda: c'è una pagina di sotto, e l'indirizzo è di quelli che si aprono di lato. */
    const inScheda = computed(() => stato.fase !== 'chiusa' && apreDiLato(page.url));

    /** La scheda sta arrivando: la pagina di sotto è ancora a video, e il pannello entra già, vuoto. */
    const inArrivo = computed(() => stato.fase === 'apertura' && ! inScheda.value);

    return { stato: readonly(stato), inScheda, inArrivo, chiudi, apriAPaginaIntera, resta, segnaModificato };
}

/** Per i test: riporta tutto com'era all'avvio. */
export function dimenticaSchedaLaterale(): void {
    azzera();
    stato.uscita = false;
    visita = null;
}

/** Per i test: i quattro ascoltatori, da chiamare a mano al posto di Inertia. */
export const ascoltatori = { primaDellaVisita, dopoLaNavigazione, dopoIlSuccesso, dopoLaVisita };
