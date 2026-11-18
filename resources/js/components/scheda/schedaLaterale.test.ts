// @vitest-environment jsdom

/**
 * La scheda laterale (Web Pro Italia, 18/11/2026): quando una visita diventa una scheda, quando la scheda resta, cambia
 * contenuto o finisce, e come si chiude.
 *
 * La pagina dentro la scheda è la pagina vera, a cui si arriva con una visita normale: qui Inertia non c'è, e i suoi quattro
 * avvisi (prima di una visita, dopo un cambio di pagina, dopo un successo, alla fine) si danno a mano, nell'ordine in cui li dà lei.
 *
 * Che cosa NON copre: come l'impalcatura disegna la scheda (`ImpalcaturaLaterale.test.ts`) e quali indirizzi si aprono di lato
 * (`indirizzi.test.ts`).
 */
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';

const inertia = vi.hoisted(() => ({ on: vi.fn(), visit: vi.fn(), reload: vi.fn(), page: { url: '/admin/anagrafiche' } }));
const scelta = vi.hoisted(() => ({ schede: 'lato' as 'lato' | 'pagina' }));

vi.mock('@inertiajs/vue3', () => ({ router: { on: inertia.on, visit: inertia.visit, reload: inertia.reload }, usePage: () => inertia.page }));
vi.mock('@/composables/useImpalcatura', () => ({ schedeScelte: () => scelta.schede }));

const { ascoltatori, avviaSchedaLaterale, chiudi, apriAPaginaIntera, resta, segnaModificato, fondoDellaScheda, useSchedaLaterale, dimenticaSchedaLaterale, DURATA_USCITA }
    = await import('./schedaLaterale');

const ELENCO = '/admin/anagrafiche?pagina=2';
const { stato } = useSchedaLaterale();
const indietro = vi.spyOn(window.history, 'go').mockImplementation(() => {});

/** Il browser è su questo indirizzo. */
const sono = (indirizzo: string) => window.history.replaceState({}, '', indirizzo);
/** Inertia sta per fare una visita: dice se la visita parte. */
const prima = (verso: string, opzioni: { method?: string; replace?: boolean; prefetch?: boolean; async?: boolean } = {}) =>
    ascoltatori.primaDellaVisita({ detail: { visit: { url: new URL(verso, 'http://localhost'), method: 'get', ...opzioni } } }) !== false;
/** La pagina è cambiata (o è tornata la stessa, con gli errori). */
const arrivo = (indirizzo: string, tipo: 'visit' | 'history' = 'visit') => {
    sono(indirizzo);
    ascoltatori.dopoLaNavigazione({ detail: { page: { url: indirizzo }, type: tipo } });
};
/** Una visita intera andata bene: prima, pagina nuova, successo, fine. */
const vado = (verso: string, opzioni: { method?: string; replace?: boolean } = {}, dove = verso) => {
    if (! prima(verso, opzioni)) {
        return false;
    }
    arrivo(dove);
    ascoltatori.dopoIlSuccesso();
    ascoltatori.dopoLaVisita();

    return true;
};
const apro = (da = ELENCO, verso = '/admin/anagrafiche/12') => {
    sono(da);
    vado(verso);
};

beforeEach(() => {
    vi.useFakeTimers();
    document.body.innerHTML = '<div class="km-impalcatura"><aside id="menu">menu</aside><main><form><input id="filtro" name="filtro" value="rossi"><label for="filtro">Filtra</label></form><iframe src="about:blank"></iframe><p>elenco</p></main></div>';
    scelta.schede = 'lato';
    dimenticaSchedaLaterale();
    inertia.visit.mockClear();
    inertia.reload.mockClear();
    indietro.mockClear();
    sono(ELENCO);
});
afterEach(() => vi.useRealTimers());

describe('quando una visita diventa una scheda', () => {
    test('da un elenco a un indirizzo che si apre di lato: nasce la scheda, e si ricorda la pagina di sotto', () => {
        expect(prima('/admin/anagrafiche/12')).toBe(true);
        expect(stato.fase).toBe('apertura');
        expect(stato.base).toBe(ELENCO);

        arrivo('/admin/anagrafiche/12');
        expect(stato.fase).toBe('aperta');
        expect(stato.passi).toBe(1);
    });

    test('la pagina di sotto è una copia ferma: senza identificativi né nomi dei campi, senza riquadri da ricaricare, non si tocca', () => {
        apro();
        const { nodi } = fondoDellaScheda();
        expect(nodi?.textContent).toContain('elenco');
        expect(nodi?.hasAttribute('inert')).toBe(true);
        expect(nodi?.querySelector('[id], [name], [for]')).toBeNull();
        expect(nodi?.querySelector('iframe')).toBeNull();
        // La pagina vera non è stata toccata.
        expect(document.querySelector('.km-impalcatura #filtro')).not.toBeNull();
    });

    test('da una pagina che è già una scheda a pagina intera (arrivati con l\'indirizzo): si resta a pagina intera', () => {
        sono('/admin/anagrafiche/12');
        vado('/admin/anagrafiche/12/documenti');
        expect(stato.fase).toBe('chiusa');
    });

    test('verso una pagina che non si apre di lato: una visita normale', () => {
        vado('/admin/fornitori');
        expect(stato.fase).toBe('chiusa');
    });

    test('un salvataggio, un precaricamento: non aprono una scheda', () => {
        prima('/admin/anagrafiche/12', { method: 'post' });
        expect(stato.fase).toBe('chiusa');
        prima('/admin/anagrafiche/12', { prefetch: true });
        expect(stato.fase).toBe('chiusa');
    });

    test('senza l\'impalcatura a menu laterale sotto non c\'è niente da tenere fermo: una visita normale', () => {
        document.body.innerHTML = '<main>pagina</main>';
        vado('/admin/anagrafiche/12');
        expect(stato.fase).toBe('chiusa');
    });

    test('la scheda non arriva (un errore, una pagina che non si apre di lato): non resta a metà', () => {
        prima('/admin/anagrafiche/12');
        expect(stato.fase).toBe('apertura');
        ascoltatori.dopoLaVisita();
        expect(stato.fase).toBe('chiusa');
    });

    test('con «Schede: a pagina intera» niente si mette in ascolto', () => {
        scelta.schede = 'pagina';
        inertia.on.mockClear();
        avviaSchedaLaterale();
        expect(inertia.on).not.toHaveBeenCalled();

        scelta.schede = 'lato';
        avviaSchedaLaterale();
        avviaSchedaLaterale();
        expect(inertia.on.mock.calls.map((c) => c[0])).toEqual(['before', 'navigate', 'success', 'finish']);
    });
});

describe('dentro la scheda', () => {
    test('un collegamento a un\'altra pagina da scheda (una linguetta) cambia il contenuto: la scheda resta', () => {
        apro();
        vado('/admin/anagrafiche/12/documenti');
        expect(stato.fase).toBe('aperta');
        expect(stato.passi).toBe(2);
        expect(stato.base).toBe(ELENCO);
    });

    test('un collegamento a un\'altra pagina chiude la scheda e ci va', () => {
        apro();
        vado('/admin/gestionale/1');
        expect(stato.fase).toBe('chiusa');
        expect(fondoDellaScheda().nodi).toBeNull();
        // Il pannello scivola via, invece di sparire di colpo.
        expect(stato.uscita).toBe(true);
        vi.advanceTimersByTime(DURATA_USCITA + 100);
        expect(stato.uscita).toBe(false);
    });

    test('un collegamento alla pagina di sotto («Torna all\'elenco») è «chiudi»: la visita non parte, si torna indietro', () => {
        apro();
        expect(prima(ELENCO)).toBe(false);
        expect(stato.fase).toBe('chiusura');
        vi.advanceTimersByTime(DURATA_USCITA);
        expect(indietro).toHaveBeenCalledWith(-1);
    });

    test('lo stesso elenco con un altro filtro non è la pagina di sotto: ci si va', () => {
        apro();
        expect(prima('/admin/anagrafiche?pagina=3')).toBe(true);
    });

    test('un campo toccato segna il modulo; cambiare pagina dentro la scheda lo azzera', () => {
        apro('/admin/anagrafiche', '/admin/anagrafiche/12/edit');
        segnaModificato();
        expect(stato.modificato).toBe(true);
        vado('/admin/anagrafiche/12');
        expect(stato.modificato).toBe(false);
    });
});

describe('salvare dentro la scheda', () => {
    test('errori di validazione: il server rimanda al modulo, la scheda resta e il modulo è ancora «modificato»', () => {
        apro('/admin/anagrafiche', '/admin/anagrafiche/create');
        segnaModificato();
        // Il salvataggio torna sulla stessa pagina con gli errori: non c'è il successo.
        prima('/admin/anagrafiche', { method: 'post' });
        arrivo('/admin/anagrafiche/create');
        ascoltatori.dopoLaVisita();

        expect(stato.fase).toBe('aperta');
        expect(stato.passi).toBe(1);
        expect(stato.modificato).toBe(true);
        expect(stato.salvato).toBe(false);
    });

    test('salvato, e il server porta all\'elenco: la scheda si chiude da sé', () => {
        apro('/admin/anagrafiche', '/admin/anagrafiche/create');
        segnaModificato();
        vado('/admin/anagrafiche', { method: 'post' }, '/admin/anagrafiche');

        expect(stato.fase).toBe('chiusa');
        expect(stato.uscita).toBe(true);
        // L'elenco è arrivato fresco dal server: non c'è da rileggerlo.
        expect(inertia.reload).not.toHaveBeenCalled();
    });

    test('salvato da un elenco con una ricerca: il server rimanda all\'elenco nudo, e si torna a quello che si aveva, con il messaggio', () => {
        apro(ELENCO, '/admin/anagrafiche/12/edit');
        segnaModificato();
        vado('/admin/anagrafiche/12', { method: 'put' }, '/admin/anagrafiche');

        expect(stato.fase).toBe('chiusa');
        expect(inertia.visit).not.toHaveBeenCalled();
        vi.advanceTimersByTime(0);
        // Stesso elenco, con la sua pagina; al posto di questa voce della cronologia; il messaggio di conferma non si richiede.
        expect(inertia.visit).toHaveBeenCalledTimes(1);
        expect(inertia.visit).toHaveBeenCalledWith(ELENCO, { replace: true, except: ['flash'] });
    });

    test('non si torna ai filtri se non c\'erano, se il server ne ha messi di suoi, o se ha portato a un\'altra pagina', () => {
        // L'elenco di sotto era senza ricerca: quello che arriva dal server è già lui.
        apro('/admin/anagrafiche', '/admin/anagrafiche/create');
        vado('/admin/anagrafiche', { method: 'post' }, '/admin/anagrafiche');
        // Il server ha scelto lui dove e come tornare.
        apro(ELENCO, '/admin/anagrafiche/create');
        vado('/admin/anagrafiche', { method: 'post' }, '/admin/anagrafiche?nuova=1');
        // Un'altra pagina.
        apro(ELENCO, '/admin/anagrafiche/create');
        vado('/admin/anagrafiche', { method: 'post' }, '/admin/fornitori');
        // «Indietro» fino all'elenco nudo, dopo un salvataggio rimasto nella scheda: è la cronologia, non un salvataggio.
        apro(ELENCO, '/admin/anagrafiche/12/edit');
        prima('/admin/anagrafiche', {});
        arrivo('/admin/anagrafiche', 'history');
        ascoltatori.dopoLaVisita();

        vi.advanceTimersByTime(10);
        expect(inertia.visit).not.toHaveBeenCalled();
    });

    test('salvato, e si resta nella scheda: chiudendo non si chiede, e la pagina di sotto si rilegge', () => {
        apro('/admin/gestionale/1/immobili', '/admin/gestionale/1/immobili/4/anagrafiche/create');
        segnaModificato();
        vado('/admin/gestionale/1/immobili/4/anagrafiche', { method: 'post' }, '/admin/gestionale/1/immobili/4/anagrafiche');
        expect([stato.fase, stato.salvato, stato.modificato, stato.passi]).toEqual(['aperta', true, false, 2]);

        chiudi();
        expect(stato.daConfermare).toBe(false);
        vi.advanceTimersByTime(DURATA_USCITA);
        expect(indietro).toHaveBeenCalledWith(-2);

        // La cronologia ridà l'elenco com'era prima del salvataggio: si rilegge.
        arrivo('/admin/gestionale/1/immobili', 'history');
        expect(stato.fase).toBe('chiusa');
        expect(inertia.reload).toHaveBeenCalledTimes(1);
    });
});

describe('chiudere', () => {
    test('senza modifiche: il pannello esce e si torna alla pagina di sotto, com\'era', () => {
        apro();
        chiudi();
        expect(stato.fase).toBe('chiusura');
        expect(indietro).not.toHaveBeenCalled();
        vi.advanceTimersByTime(DURATA_USCITA);
        expect(indietro).toHaveBeenCalledWith(-1);

        arrivo(ELENCO, 'history');
        expect(stato.fase).toBe('chiusa');
        expect(stato.uscita).toBe(false);
        expect(inertia.reload).not.toHaveBeenCalled();
    });

    test('con un modulo toccato: prima si chiede; «resta» lascia tutto com\'è, «chiudi senza salvare» chiude', () => {
        apro('/admin/anagrafiche', '/admin/anagrafiche/create');
        segnaModificato();

        chiudi();
        expect([stato.fase, stato.daConfermare]).toEqual(['aperta', true]);
        resta();
        expect([stato.fase, stato.daConfermare, stato.modificato]).toEqual(['aperta', false, true]);

        chiudi({ senzaChiedere: true });
        expect(stato.fase).toBe('chiusura');
    });

    test('«Indietro» del browser chiude la scheda', () => {
        apro();
        arrivo(ELENCO, 'history');
        expect(stato.fase).toBe('chiusa');
    });

    test('dopo «Indietro» o «Avanti» dentro la scheda il conto dei passi non è sicuro: si va dritti alla pagina di sotto', () => {
        apro();
        vado('/admin/anagrafiche/12/documenti');
        arrivo('/admin/anagrafiche/12', 'history');
        expect(stato.cronologiaIncerta).toBe(true);

        chiudi();
        vi.advanceTimersByTime(DURATA_USCITA);
        expect(indietro).not.toHaveBeenCalled();
        expect(inertia.visit).toHaveBeenCalledWith(ELENCO, { replace: true });
    });

    test('«Apri a pagina intera» toglie la scheda e lascia la pagina dov\'è', () => {
        apro();
        apriAPaginaIntera();
        expect(stato.fase).toBe('chiusa');
        expect(fondoDellaScheda().nodi).toBeNull();
        expect(indietro).not.toHaveBeenCalled();
        expect(inertia.visit).not.toHaveBeenCalled();
        // …e da lì le linguette restano a pagina intera.
        vado('/admin/anagrafiche/12/documenti');
        expect(stato.fase).toBe('chiusa');
    });

    test('chiudere due volte, o a scheda chiusa, non fa niente', () => {
        chiudi();
        expect(stato.fase).toBe('chiusa');
        apro();
        chiudi();
        chiudi();
        vi.advanceTimersByTime(DURATA_USCITA);
        expect(indietro).toHaveBeenCalledTimes(1);
    });
});

describe('ciò che l\'impalcatura guarda', () => {
    test('la pagina è nella scheda solo se c\'è una pagina di sotto e l\'indirizzo è di quelli che si aprono di lato', () => {
        const { inScheda, inArrivo } = useSchedaLaterale();
        inertia.page.url = ELENCO;
        expect([inScheda.value, inArrivo.value]).toEqual([false, false]);

        prima('/admin/anagrafiche/12');
        // La scheda sta arrivando: la pagina a video è ancora l'elenco.
        expect([inScheda.value, inArrivo.value]).toEqual([false, true]);
    });
});
