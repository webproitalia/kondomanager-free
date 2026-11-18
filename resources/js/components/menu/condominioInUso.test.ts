// @vitest-environment jsdom

/**
 * Il condominio in uso (Web Pro Italia, 18/11/2026): l'ultimo condominio aperto, che il menu tiene sotto le voci dello studio
 * anche fuori dal gestionale. Si ricorda nel browser, utente per utente, con l'esercizio in cui si lavorava.
 *
 * Che cosa copre: che cosa si scrive e si rilegge; l'esercizio, che una pagina senza esercizio non cancella; il ricordo
 * illeggibile; quando il ricordo si cancella (il condominio non è più nell'elenco, una sua sezione non si trova) e quando
 * no (una pagina qualunque non trovata); il gruppo ripiegato, che si ricorda per utente e si riapre da solo solo quando si
 * apre un condominio da fuori; l'ascolto delle pagine.
 *
 * Che cosa NON copre: come il menu lo mostra (`MenuContenuto.test.ts`) e a quali indirizzi porta (`vociMenu.test.ts`).
 */
import { beforeEach, describe, expect, test, vi } from 'vitest';

const pagina = vi.hoisted(() => ({ url: '/admin/dashboard', props: {} as Record<string, unknown> }));
const giro = vi.hoisted(() => ({ on: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({ usePage: () => pagina, router: { on: giro.on } }));

const { useCondominioInUso, paginaNonTrovata, arrivoA, ascoltaCondominioInUso, azzeraCondominioInUso } = await import('./condominioInUso');

const CHIAVE = 'km-condominio-in-uso:5';
const leRose = { id: 7, nome: 'Le Rose', esercizio: { id: 3, nome: 'Esercizio 2026' } };
const scritto = (chiave = CHIAVE) => JSON.parse(localStorage.getItem(chiave) ?? 'null');

function entra(utente: number | null = 5) {
    pagina.props = { auth: { user: utente ? { id: utente } : null } };
    azzeraCondominioInUso();

    return useCondominioInUso();
}

beforeEach(() => {
    localStorage.clear();
    azzeraCondominioInUso();
});

describe('ricordare', () => {
    test('la prima volta non c\'è un condominio in uso', () => {
        expect(entra().inUso.value).toBeNull();
    });

    test('entrando in un condominio lo si ricorda: nel browser, e per quell\'utente soltanto', () => {
        const { inUso, ricorda } = entra();
        ricorda(leRose);
        expect(inUso.value).toEqual(leRose);
        expect(scritto()).toEqual(leRose);

        // Chi rientra lo ritrova; un altro utente dello stesso browser no.
        expect(entra().inUso.value).toEqual(leRose);
        expect(entra(8).inUso.value).toBeNull();
        expect(entra(null).inUso.value).toBeNull();
    });

    test('una pagina del condominio che non porta l\'esercizio non cancella quello in cui si lavorava', () => {
        const { inUso, ricorda } = entra();
        ricorda(leRose);
        ricorda({ id: 7, nome: 'Le Rose', esercizio: null });
        expect(inUso.value?.esercizio).toEqual({ id: 3, nome: 'Esercizio 2026' });

        // Un altro esercizio dello stesso condominio prende il posto del primo.
        ricorda({ id: 7, nome: 'Le Rose', esercizio: { id: 4, nome: 'Esercizio 2027' } });
        expect(scritto().esercizio).toEqual({ id: 4, nome: 'Esercizio 2027' });
    });

    test('entrando in un altro condominio l\'esercizio del primo non lo segue', () => {
        const { inUso, ricorda } = entra();
        ricorda(leRose);
        ricorda({ id: 9, nome: 'I Tigli', esercizio: null });
        expect(inUso.value).toEqual({ id: 9, nome: 'I Tigli', esercizio: null });
        expect(scritto()).toEqual({ id: 9, nome: 'I Tigli', esercizio: null });
    });

    test('il nome cambiato si aggiorna', () => {
        const { ricorda } = entra();
        ricorda(leRose);
        ricorda({ ...leRose, nome: 'Le Rose (via Roma)' });
        expect(scritto().nome).toBe('Le Rose (via Roma)');
    });

    test('un ricordo illeggibile o a metà vale come nessun ricordo', () => {
        for (const guasto of ['{non è json', '"testo"', '{"id":"7","nome":"Le Rose"}', '{"id":7}', 'null']) {
            localStorage.setItem(CHIAVE, guasto);
            expect(entra().inUso.value, guasto).toBeNull();
        }
        // L'esercizio guasto si scarta, il condominio resta.
        localStorage.setItem(CHIAVE, '{"id":7,"nome":"Le Rose","esercizio":{"id":"x"}}');
        expect(entra().inUso.value).toEqual({ id: 7, nome: 'Le Rose', esercizio: null });
    });

    test('senza la memoria del browser il menu funziona lo stesso: il ricordo vale per la visita', () => {
        const { inUso, ricorda } = entra();
        const scrivere = vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => { throw new Error('memoria piena'); });
        expect(() => ricorda(leRose)).not.toThrow();
        expect(inUso.value).toEqual(leRose);
        scrivere.mockRestore();
    });
});

describe('dimenticare', () => {
    test('il condominio non è più nell\'elenco intero dei condomini: il ricordo si cancella', () => {
        const { inUso, ricorda, controllaCon } = entra();
        ricorda(leRose);
        controllaCon([{ id: 7 }, { id: 9 }]);
        expect(inUso.value).toEqual(leRose);

        controllaCon([{ id: 9 }]);
        expect(inUso.value).toBeNull();
        expect(localStorage.getItem(CHIAVE)).toBeNull();
    });

    test('un elenco vuoto non dice niente: il ricordo resta', () => {
        const { inUso, ricorda, controllaCon } = entra();
        ricorda(leRose);
        controllaCon([]);
        expect(inUso.value).toEqual(leRose);
    });

    test('una sua sezione non si trova: il condominio non c\'è più', () => {
        for (const indirizzo of ['/admin/gestionale/7', '/admin/gestionale/7/', '/admin/gestionale/7/immobili', '/admin/gestionale/7/movimenti-rate?pagina=2', '/admin/gestionale/7/esercizi']) {
            const { inUso, ricorda } = entra();
            ricorda(leRose);
            paginaNonTrovata(indirizzo);
            expect(inUso.value, indirizzo).toBeNull();
            expect(localStorage.getItem(CHIAVE), indirizzo).toBeNull();
        }
    });

    test('non si trova una voce del suo esercizio: si dimentica l\'esercizio, il condominio resta', () => {
        const { inUso, ricorda } = entra();
        ricorda(leRose);
        paginaNonTrovata('/admin/gestionale/7/esercizi/3/piani-conti');
        expect(inUso.value).toEqual({ id: 7, nome: 'Le Rose', esercizio: null });
        expect(scritto()).toEqual({ id: 7, nome: 'Le Rose', esercizio: null });
    });

    test('una pagina qualunque non trovata non dice niente sul condominio: il ricordo resta', () => {
        const { inUso, ricorda } = entra();
        ricorda(leRose);
        for (const indirizzo of [
            '/admin/gestionale/7/immobili/555',               // un'unità eliminata
            '/admin/gestionale/7/esercizi/8/piani-conti',     // un altro esercizio
            '/admin/gestionale/7/esercizi/3/gestioni/12/edit', // una gestione eliminata
            '/admin/gestionale/70/immobili',                  // un altro condominio
            '/admin/anagrafiche/7',
            '',
        ]) {
            paginaNonTrovata(indirizzo);
            expect(inUso.value, indirizzo).toEqual(leRose);
        }
    });
});

describe('ripiegare il gruppo del condominio', () => {
    test('nasce aperto; ripiegato si ricorda nel browser, per quell\'utente soltanto', () => {
        const { ripiegato, ripiega } = entra();
        expect(ripiegato.value).toBe(false);

        ripiega(true);
        expect(ripiegato.value).toBe(true);
        expect(entra().ripiegato.value).toBe(true);
        expect(entra(8).ripiegato.value).toBe(false);

        // Riaperto torna com'era nato: non resta niente da ricordare.
        entra().ripiega(false);
        expect(localStorage.getItem('km-condominio-ripiegato:5')).toBeNull();
        expect(entra().ripiegato.value).toBe(false);
    });

    test('entrare in un condominio, o in un\'altra sua pagina, non lo riapre', () => {
        const { ripiegato, ripiega, ricorda } = entra();
        ripiega(true);
        ricorda(leRose);
        ricorda({ id: 9, nome: 'I Tigli', esercizio: null });
        expect(ripiegato.value).toBe(true);
    });

    test('una scelta esplicita lo riapre', () => {
        const { ripiegato, ripiega, riapri } = entra();
        ripiega(true);
        riapri();
        expect(ripiegato.value).toBe(false);
        expect(entra().ripiegato.value).toBe(false);
    });

    test('togliere il condominio in uso non lo riapre: resta la sola riga «Scegli un condominio»', () => {
        const { ripiegato, ripiega, ricorda, dimentica } = entra();
        ricorda(leRose);
        ripiega(true);
        dimentica();
        expect(ripiegato.value).toBe(true);
    });

    test('aprire il cruscotto di un condominio da fuori lo riapre: dall\'elenco dei condomini, dalla dashboard, da un altro condominio', () => {
        for (const da of ['/condomini', '/admin/dashboard', '/admin/gestionale/9/immobili', '/admin/gestionale/70']) {
            const { ripiegato, ripiega } = entra();
            ripiega(true);
            arrivoA(da, '/admin/gestionale/7', 'visit');
            expect(ripiegato.value, da).toBe(false);
        }
    });

    test('non lo riaprono le altre pagine, il cruscotto del condominio in cui si è già, il «torna indietro» e la pagina caricata per intero', () => {
        const { ripiegato, ripiega } = entra();
        ripiega(true);
        arrivoA('/admin/dashboard', '/admin/gestionale/7/fatture/12', 'visit');   // un collegamento qualunque verso una sua pagina
        arrivoA('/admin/gestionale/7/immobili', '/admin/gestionale/7', 'visit'); // si è già dentro
        arrivoA('/admin/dashboard', '/admin/gestionale/7', 'history');           // «torna indietro»
        arrivoA('', '/admin/gestionale/7', 'initial');                           // la pagina aperta dai preferiti
        arrivoA('/admin/gestionale/7', '/admin/anagrafiche', 'visit');
        expect(ripiegato.value).toBe(true);
    });
});

describe('l\'ascolto delle pagine', () => {
    test('parte una volta sola: cancella il ricordo quando la sezione aperta dal menu risponde «non trovata», e riapre il gruppo quando si apre un condominio', () => {
        ascoltaCondominioInUso();
        ascoltaCondominioInUso();
        expect(giro.on.mock.calls.map(([evento]) => evento)).toEqual(['navigate', 'before', 'httpException']);
        const [[, arrivo], [, prima], [, errore]] = giro.on.mock.calls as [string, (e: unknown) => void][];

        const { inUso, ripiegato, ripiega, ricorda } = entra();
        ricorda(leRose);

        // Un errore del server non è una pagina non trovata.
        prima({ detail: { visit: { url: new URL('https://studio.test/admin/gestionale/7/immobili') } } });
        errore({ detail: { response: { status: 500 } } });
        expect(inUso.value).toEqual(leRose);

        // Una lettura in anticipo non è la pagina a cui si sta andando.
        prima({ detail: { visit: { url: new URL('https://studio.test/admin/anagrafiche'), prefetch: true } } });
        errore({ detail: { response: { status: 404 } } });
        expect(inUso.value).toBeNull();

        // Dall'elenco dei condomini al cruscotto di uno di loro: il gruppo ripiegato si riapre. Poi, dentro, resta com'è.
        ripiega(true);
        arrivo({ detail: { page: { url: '/condomini?pagina=2' }, type: 'visit' } });
        expect(ripiegato.value).toBe(true);
        arrivo({ detail: { page: { url: '/admin/gestionale/7' }, type: 'visit' } });
        expect(ripiegato.value).toBe(false);
        ripiega(true);
        arrivo({ detail: { page: { url: '/admin/gestionale/7/immobili' }, type: 'visit' } });
        arrivo({ detail: { page: { url: '/admin/gestionale/7' }, type: 'visit' } });
        expect(ripiegato.value).toBe(true);
    });
});
