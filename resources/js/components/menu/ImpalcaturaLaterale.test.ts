// @vitest-environment jsdom

/**
 * L'impalcatura a menu laterale (Web Pro Italia, 18/11/2026): la cornice di ogni pagina — il menu, la barra in alto, l'area di
 * lavoro.
 *
 * Che cosa copre:
 *
 * - dentro un condominio l'impalcatura dice al menu qual è il **condominio in uso** (quello della pagina, con il suo
 *   esercizio), e il menu se lo ricorda anche nelle pagine dello studio; «Chiudi il condominio», da dentro una sua pagina, lo
 *   toglie, e non torna da solo;
 * - la **scheda laterale**: la pagina a cui si arriva da un elenco si disegna in un pannello a destra, con la sua testata al
 *   posto della barra, sopra la copia ferma della pagina di sotto; il velo, la × ed Esc la chiudono, e con un modulo
 *   toccato prima si chiede; «Apri a pagina intera» toglie il pannello **senza ricreare la pagina** (quello che si è scritto
 *   resta).
 *
 * Che cosa NON copre: il menu e la barra, che qui sono segnaposto (`MenuContenuto.test.ts`, `BarraSuperiore.test.ts`);
 * quando una visita diventa una scheda e i passi nella cronologia (`components/scheda/schedaLaterale.test.ts`); quali
 * indirizzi si aprono di lato (`components/scheda/indirizzi.test.ts`).
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { mount, shallowMount, type VueWrapper } from '@vue/test-utils';
import { defineComponent, h, nextTick, onMounted, reactive } from 'vue';

const stato = vi.hoisted(() => ({ pagina: { url: '/admin/dashboard', props: {} as Record<string, unknown> }, on: vi.fn(() => () => {}) }));

vi.mock('@inertiajs/vue3', async () => {
    const vue = await import('vue');
    stato.pagina = vue.reactive(stato.pagina);

    return { usePage: () => stato.pagina, router: { on: stato.on, visit: vi.fn(), reload: vi.fn() } };
});
vi.mock('laravel-vue-i18n', async () => ({ trans: (await import('./provaTraduzioni')).tradotto }));

const ImpalcaturaLaterale = (await import('./ImpalcaturaLaterale.vue')).default;
const { useCondominioInUso, azzeraCondominioInUso } = await import('./condominioInUso');
const { ascoltatori, useSchedaLaterale, dimenticaSchedaLaterale, DURATA_USCITA } = await import('@/components/scheda/schedaLaterale');

const CHIAVE = 'km-condominio-in-uso:5';
const ricordo = () => JSON.parse(localStorage.getItem(CHIAVE) ?? 'null');
const utente = { auth: { user: { id: 5, name: 'Anna Bianchi', roles: ['amministratore'], permissions: [] } } };

let w: VueWrapper | null = null;
afterEach(() => {
    w?.unmount();
    w = null;
    localStorage.clear();
    azzeraCondominioInUso();
    dimenticaSchedaLaterale();
    window.history.replaceState({}, '', '/');
    delete document.documentElement.dataset.kmMenu;
    vi.useRealTimers();
});

function monta(url: string, props: Record<string, unknown>, gestionale = false) {
    stato.pagina.url = url;
    stato.pagina.props = reactive({ ...utente, ...props });
    w = shallowMount(ImpalcaturaLaterale, { props: { gestionale }, slots: { default: '<p>pagina</p>' } });

    return w;
}

describe('il condominio in uso', () => {
    test('dentro un condominio è quello della pagina, con il suo esercizio: il menu se lo ricorda', () => {
        monta('/admin/gestionale/7/immobili', { condominio: { id: 7, nome: 'Le Rose' }, esercizio: { id: 3, nome: 'Esercizio 2026' } }, true);
        expect(ricordo()).toEqual({ id: 7, nome: 'Le Rose', esercizio: { id: 3, nome: 'Esercizio 2026' } });
    });

    test('cambiando esercizio nella stessa impalcatura il ricordo lo segue', async () => {
        monta('/admin/gestionale/7/esercizi/3/gestioni', { condominio: { id: 7, nome: 'Le Rose' }, esercizio: { id: 3, nome: 'Esercizio 2026' } }, true);
        stato.pagina.props.esercizio = { id: 4, nome: 'Esercizio 2027' };
        await nextTick();
        expect(ricordo().esercizio).toEqual({ id: 4, nome: 'Esercizio 2027' });
    });

    test('una pagina dello studio che porta un condominio (la sua scheda) non lo fa diventare quello in uso', () => {
        monta('/condomini/7/edit', { condominio: { id: 7, nome: 'Le Rose' } });
        expect(ricordo()).toBeNull();
    });

    test('nelle pagine dello studio il ricordo resta quello dell\'ultimo condominio aperto', () => {
        localStorage.setItem(CHIAVE, JSON.stringify({ id: 7, nome: 'Le Rose', esercizio: null }));
        monta('/admin/anagrafiche', {});
        expect(ricordo()).toEqual({ id: 7, nome: 'Le Rose', esercizio: null });
    });

    test('«Chiudi il condominio» da dentro una sua pagina: il ricordo non torna da solo mentre si va alla dashboard', async () => {
        monta('/admin/gestionale/7/immobili', { condominio: { id: 7, nome: 'Le Rose' }, esercizio: null }, true);
        expect(ricordo()?.id).toBe(7);

        useCondominioInUso().dimentica();
        await nextTick();
        expect(ricordo()).toBeNull();

        // Arriva la dashboard: la pagina non porta più un condominio, e il ricordo resta vuoto.
        stato.pagina.url = '/admin/dashboard';
        stato.pagina.props = reactive({ ...utente });
        await nextTick();
        expect(ricordo()).toBeNull();
    });

    test('l\'ascolto delle pagine parte con l\'impalcatura', () => {
        monta('/admin/dashboard', {});
        expect(stato.on.mock.calls.map(([evento]) => evento)).toEqual(expect.arrayContaining(['navigate', 'httpException']));
    });
});

// ─── La scheda laterale ──────────────────────────────────────────────────────────────────────────────────────────────────

const montaggi = { pagina: 0 };
/** La pagina dentro l'impalcatura: un campo di un modulo, e il conto di quante volte è stata creata. */
const Pagina = defineComponent({
    setup() {
        onMounted(() => { montaggi.pagina += 1; });

        return () => h('form', [h('input', { 'data-prova-campo': '' })]);
    },
});

const contenitore = { inheritAttrs: false, render() { return (this as any).$slots.default?.(); } };
const pulsante = { template: '<button type="button"><slot /></button>' };
const stubs = {
    SidebarProvider: { template: '<div><slot /></div>' },
    MenuLaterale: { props: { gestionale: Boolean, barra: Boolean }, template: '<aside data-prova-menu :data-barra="barra" :data-gestionale="gestionale" />' },
    BarraSuperiore: { template: '<header data-prova-barra />' },
    BarraStudio: { props: { gestionale: Boolean }, template: '<header data-prova-barra-studio :data-gestionale="gestionale" />' },
    PercorsoStudio: { template: '<nav data-prova-percorso />' },
    Footer: { template: '<footer data-prova-piede />' },
    SchedaFondo: { template: '<div data-prova-fondo />' },
    SchedaInArrivo: { props: { uscita: Boolean }, template: '<div data-prova-in-arrivo :data-uscita="uscita" />' },
    SchedaTestata: {
        emits: ['chiudi', 'pagina-intera'],
        template: '<div data-prova-testata><button type="button" data-prova-chiudi @click="$emit(\'chiudi\')" /><button type="button" data-prova-intera @click="$emit(\'pagina-intera\')" /></div>',
    },
    AlertDialog: { props: ['open'], template: '<div v-if="open" data-prova-domanda><slot /></div>' },
    AlertDialogContent: contenitore, AlertDialogHeader: contenitore, AlertDialogFooter: contenitore,
    AlertDialogTitle: contenitore, AlertDialogDescription: contenitore,
    AlertDialogCancel: pulsante, AlertDialogAction: pulsante,
};

const { stato: scheda } = useSchedaLaterale();

/** Si è su una pagina a schermo intero. */
function sono(indirizzo: string) {
    window.history.replaceState({}, '', indirizzo);
    stato.pagina.url = indirizzo;
}

/** Da un elenco si apre una pagina che sta nella scheda: gli avvisi di Inertia, dati a mano e nel suo ordine. */
async function apro(indirizzo: string) {
    ascoltatori.primaDellaVisita({ detail: { visit: { url: new URL(indirizzo, 'http://localhost'), method: 'get' } } });
    await nextTick();
    const inArrivo = w!.find('[data-prova-in-arrivo]').exists();
    sono(indirizzo);
    ascoltatori.dopoLaNavigazione({ detail: { page: { url: indirizzo }, type: 'visit' } });
    ascoltatori.dopoLaVisita();
    await nextTick();

    return { inArrivo };
}

function montaElenco(indirizzo = '/admin/anagrafiche') {
    montaggi.pagina = 0;
    sono(indirizzo);
    stato.pagina.props = reactive({ ...utente });
    w = mount(ImpalcaturaLaterale, { slots: { default: () => h(Pagina) }, global: { stubs }, attachTo: document.body });

    return w;
}

describe('la scheda laterale', () => {
    test('a pagina intera: il menu, la barra in alto, il piè di pagina; nessun pannello', () => {
        const pagina = montaElenco();
        expect([pagina.find('[data-prova-menu]').exists(), pagina.find('[data-prova-barra]').exists(), pagina.find('[data-prova-piede]').exists()]).toEqual([true, true, true]);
        expect(pagina.find('[data-scheda-laterale]').exists()).toBe(false);
        expect(pagina.find('[data-scheda-velo]').exists()).toBe(false);
        expect(pagina.find('[data-prova-testata]').exists()).toBe(false);
        expect(pagina.get('.km-impalcatura').attributes('data-scheda')).toBe('chiusa');
    });

    test('dall\'elenco alla scheda di una persona: il pannello entra subito, e la pagina ci si disegna dentro, sopra la copia ferma dell\'elenco', async () => {
        const pagina = montaElenco();
        const { inArrivo } = await apro('/admin/anagrafiche/5');
        // Mentre la pagina arriva il pannello è già a video, vuoto.
        expect(inArrivo).toBe(true);
        expect(pagina.find('[data-prova-in-arrivo]').exists()).toBe(false);

        const pannello = pagina.get('[data-scheda-laterale]');
        expect(pannello.attributes('role')).toBe('dialog');
        expect(pannello.attributes('aria-modal')).toBe('true');
        expect(pannello.classes()).toContain('km-scheda');
        expect(pannello.find('[data-prova-campo]').exists()).toBe(true);
        // La testata della scheda al posto della barra; sotto, la copia dell'elenco e il velo. Il menu vero non c'è: è nella copia.
        expect([pagina.find('[data-prova-testata]').exists(), pagina.find('[data-prova-fondo]').exists(), pagina.find('[data-scheda-velo]').exists()]).toEqual([true, true, true]);
        expect([pagina.find('[data-prova-barra]').exists(), pagina.find('[data-prova-menu]').exists(), pagina.find('[data-prova-piede]').exists()]).toEqual([false, false, false]);
        expect(pagina.get('.km-impalcatura').attributes('data-scheda')).toBe('aperta');
    });

    test('un indirizzo che non è fra quelli che si aprono di lato resta a pagina intera', async () => {
        const pagina = montaElenco();
        await apro('/admin/gestionale/7/assemblee/4');
        expect(pagina.find('[data-scheda-laterale]').exists()).toBe(false);
        expect(pagina.find('[data-prova-barra]').exists()).toBe(true);
    });

    test('«Apri a pagina intera»: via il pannello, torna la cornice, e la pagina è la stessa — non si ricrea, e quello che si è scritto resta', async () => {
        const pagina = montaElenco();
        await apro('/admin/anagrafiche/create');
        const [nodo, creazioni] = [pagina.get('main').element, montaggi.pagina];
        await pagina.get('[data-prova-campo]').setValue('Mario Rossi');

        await pagina.get('[data-prova-intera]').trigger('click');
        expect(pagina.find('[data-scheda-laterale]').exists()).toBe(false);
        expect([pagina.find('[data-prova-menu]').exists(), pagina.find('[data-prova-barra]').exists(), pagina.find('[data-scheda-velo]').exists()]).toEqual([true, true, false]);
        expect(pagina.get('main').element).toBe(nodo);
        expect(montaggi.pagina).toBe(creazioni);
        expect((pagina.get('[data-prova-campo]').element as HTMLInputElement).value).toBe('Mario Rossi');
        expect(window.location.pathname).toBe('/admin/anagrafiche/create');
    });

    test('entrando nella scheda la pagina si disegna una volta sola: la cornice cambia intorno, non lei', async () => {
        const pagina = montaElenco();
        const nodo = pagina.get('main').element;
        await apro('/admin/anagrafiche/5');
        expect(pagina.get('main').element).toBe(nodo);
        expect(montaggi.pagina).toBe(1);
    });

    test('il velo e la × chiudono la scheda: si torna alla pagina di sotto con «indietro»', async () => {
        vi.useFakeTimers();
        const indietro = vi.spyOn(window.history, 'go').mockImplementation(() => {});
        const pagina = montaElenco();
        await apro('/admin/anagrafiche/5');

        await pagina.get('[data-scheda-velo]').trigger('click');
        expect(scheda.fase).toBe('chiusura');
        expect(pagina.get('[data-scheda-laterale]').attributes('data-fase')).toBe('chiusura');
        expect(pagina.find('[data-prova-domanda]').exists()).toBe(false);
        vi.advanceTimersByTime(DURATA_USCITA);
        expect(indietro).toHaveBeenCalledWith(-1);
        indietro.mockRestore();
    });

    test('con un modulo toccato, chiudere chiede prima; «Resta nella scheda» lascia tutto com\'è', async () => {
        const pagina = montaElenco();
        await apro('/admin/anagrafiche/create');
        await pagina.get('[data-prova-campo]').setValue('Mario');
        expect(scheda.modificato).toBe(true);

        await pagina.get('[data-prova-chiudi]').trigger('click');
        expect(scheda.fase).toBe('aperta');
        const domanda = pagina.get('[data-prova-domanda]');
        expect(domanda.text()).toContain('Chiudere senza salvare?');

        await domanda.findAll('button').find((b) => b.text() === 'Resta nella scheda')!.trigger('click');
        expect(pagina.find('[data-prova-domanda]').exists()).toBe(false);
        expect(scheda.fase).toBe('aperta');
        expect((pagina.get('[data-prova-campo]').element as HTMLInputElement).value).toBe('Mario');
    });

    test('…e «Chiudi senza salvare» la chiude', async () => {
        const pagina = montaElenco();
        await apro('/admin/anagrafiche/create');
        await pagina.get('[data-prova-campo]').setValue('Mario');
        await pagina.get('[data-scheda-velo]').trigger('click');
        await pagina.get('[data-prova-domanda]').findAll('button').find((b) => b.text() === 'Chiudi senza salvare')!.trigger('click');
        expect(scheda.fase).toBe('chiusura');
        expect(pagina.find('[data-prova-domanda]').exists()).toBe(false);
    });

    test('la ricerca di un elenco dentro la scheda non è un modulo: scriverci non fa chiedere niente', async () => {
        montaggi.pagina = 0;
        sono('/admin/anagrafiche');
        stato.pagina.props = reactive({ ...utente });
        w = mount(ImpalcaturaLaterale, { slots: { default: () => h('input', { 'data-prova-ricerca': '' }) }, global: { stubs }, attachTo: document.body });
        await apro('/admin/fornitori/3/documenti');
        await w.get('[data-prova-ricerca]').setValue('fattura');
        expect(scheda.modificato).toBe(false);
    });

    test('Esc chiude la scheda; con una finestra aperta sopra, Esc è della finestra', async () => {
        vi.useFakeTimers();
        const pagina = montaElenco();
        await apro('/admin/anagrafiche/5');

        const finestra = document.createElement('div');
        finestra.setAttribute('role', 'dialog');
        finestra.setAttribute('data-state', 'open');
        document.body.appendChild(finestra);
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        vi.advanceTimersByTime(1);
        expect(scheda.fase).toBe('aperta');
        finestra.remove();

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        vi.advanceTimersByTime(1);
        expect(scheda.fase).toBe('chiusura');
        await nextTick();
        expect(pagina.get('[data-scheda-laterale]').attributes('data-fase')).toBe('chiusura');
    });

    test('a pagina intera Esc non fa niente', () => {
        vi.useFakeTimers();
        montaElenco();
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        vi.advanceTimersByTime(1);
        expect(scheda.fase).toBe('chiusa');
    });
});

// ─── La navigazione «Barra in alto» ──────────────────────────────────────────────────────────────────────────────────────

describe('con la barra in alto', () => {
    function montaConLaBarra(url: string, gestionale: boolean) {
        document.documentElement.dataset.kmMenu = 'barra';
        sono(url);
        stato.pagina.props = reactive({ ...utente, ...(gestionale ? { condominio: { id: 7, nome: 'Le Rose' }, esercizio: null } : {}) });
        w = mount(ImpalcaturaLaterale, { props: { gestionale }, slots: { default: () => h(Pagina) }, global: { stubs }, attachTo: document.body });

        return w;
    }

    test('nelle pagine dello studio: la barra dello studio al posto di quella chiara, e nessun percorso; al menu si dice che c\'è la barra', () => {
        const pagina = montaConLaBarra('/admin/anagrafiche', false);
        expect(pagina.get('.km-impalcatura').classes()).toContain('km-con-barra');
        expect(pagina.find('[data-prova-barra-studio]').exists()).toBe(true);
        expect(pagina.find('[data-prova-barra]').exists()).toBe(false);
        expect(pagina.find('[data-percorso-riga]').exists()).toBe(false);
        // La colonna la decide `MenuLaterale` (che c'è solo dentro un condominio): qui gli arriva che la barra è in alto.
        expect(pagina.get('[data-prova-menu]').attributes('data-barra')).toBe('true');
        expect(pagina.get('[data-prova-menu]').attributes('data-gestionale')).toBe('false');
    });

    test('dentro un condominio: la stessa barra, il menu, e sopra la pagina la riga del percorso con i selettori', () => {
        const pagina = montaConLaBarra('/admin/gestionale/7/immobili', true);
        expect(pagina.get('[data-prova-barra-studio]').attributes('data-gestionale')).toBe('true');
        expect(pagina.get('[data-prova-menu]').attributes('data-gestionale')).toBe('true');
        expect(pagina.get('[data-percorso-riga]').find('[data-prova-percorso]').exists()).toBe(true);
        expect(pagina.find('[data-prova-barra]').exists()).toBe(false);
    });

    test('nella scheda laterale la barra e il menu veri non ci sono: sono nella copia ferma di sotto', async () => {
        const pagina = montaConLaBarra('/admin/anagrafiche', false);
        await apro('/admin/anagrafiche/5');
        expect(pagina.find('[data-scheda-laterale]').exists()).toBe(true);
        expect([pagina.find('[data-prova-barra-studio]').exists(), pagina.find('[data-prova-menu]').exists(), pagina.find('[data-prova-fondo]').exists()]).toEqual([false, false, true]);
    });

    test('con il menu laterale di sempre niente di tutto questo: la barra chiara e il menu su ogni pagina', () => {
        document.documentElement.dataset.kmMenu = 'laterale';
        const pagina = montaElenco();
        expect(pagina.get('.km-impalcatura').classes()).not.toContain('km-con-barra');
        expect([pagina.find('[data-prova-barra-studio]').exists(), pagina.find('[data-prova-barra]').exists()]).toEqual([false, true]);
        expect(pagina.get('[data-prova-menu]').attributes('data-barra')).toBe('false');
    });
});
