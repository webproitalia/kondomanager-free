// @vitest-environment jsdom

/**
 * La barra dello studio (Web Pro Italia, 21/11/2026): la navigazione «Barra in alto».
 *
 * È il punto fermo: le stesse voci, nello stesso ordine, su ogni pagina, dentro e fuori dai condomìni. Le voci sono quelle
 * di `vociMenu.ts`, raggruppate; una tendina con una voce sola è un collegamento; resta accesa la sezione in cui si è (dentro
 * un condominio, «Condomini»). «Condomini ▾» è l'ultima voce — è quella che fa entrare il menu laterale, e in fondo non
 * spezza la fila delle altre — ed elenca gli ultimi condomini aperti. A destra: ricerca, notifiche, impostazioni, guida, utente.
 *
 * Che cosa NON copre: quante voci stanno nella barra e quante in «Altro ▾» (si misura a video: qui non c'è una vista, e
 * stanno tutte); le tendine vere, che qui sono contenitori che mostrano il loro contenuto.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import { h } from 'vue';

const pagina = vi.hoisted(() => ({ url: '/admin/dashboard', props: {} as Record<string, unknown> }));
const giro = vi.hoisted(() => ({ visit: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => pagina,
    Link: { props: ['href'], setup: (p: { href: string }, { slots, attrs }: any) => () => h('a', { href: p.href, ...attrs }, slots.default?.()) },
    router: { visit: giro.visit },
}));
vi.mock('laravel-vue-i18n', async () => ({ trans: (await import('./provaTraduzioni')).tradotto }));
vi.mock('@/components/ui/sidebar', () => ({ useSidebar: () => ({ toggleSidebar: giro.visit }) }));
vi.stubGlobal('route', (nome: string) => (nome === 'admin.inbox' ? '/admin/inbox' : `/${nome}`));
vi.stubGlobal('ResizeObserver', class { observe() {} unobserve() {} disconnect() {} });

const BarraStudio = (await import('./BarraStudio.vue')).default;
const { azzeraCondominioInUso } = await import('./condominioInUso');

const contenitore = { inheritAttrs: false, render() { return (this as any).$slots.default?.(); } };
const stubs = {
    DropdownMenu: contenitore, DropdownMenuTrigger: contenitore, DropdownMenuSeparator: { render: () => null },
    DropdownMenuContent: { template: '<div data-prova-tendina><slot /></div>' },
    DropdownMenuLabel: { template: '<p data-prova-titoletto><slot /></p>' },
    DropdownMenuItem: { emits: ['select'], template: '<div data-prova-voce @click="$emit(\'select\')"><slot /></div>' },
    Tooltip: contenitore, TooltipTrigger: contenitore, TooltipContent: { render: () => null },
    UserMenuContent: { render: () => null },
    RicercaRapida: { props: ['open'], template: '<div v-if="open" data-prova-ricerca />' },
};

const TUTTI = ['Accesso pannello amministratore', 'Gestisci impostazioni generali', 'Visualizza scadenze in agenda', 'Visualizza condomini', 'Visualizza anagrafiche',
    'Visualizza comunicazioni', 'Visualizza segnalazioni', 'Visualizza documenti archivio', 'Visualizza allegati segnalazioni', 'Importa dati'];

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; localStorage.clear(); azzeraCondominioInUso(); giro.visit.mockClear(); });

function monta(url: string, props: Record<string, unknown> = {}, chi: { ruoli?: string[]; permessi?: string[] } = {}, altro: Record<string, unknown> = {}) {
    pagina.url = url;
    pagina.props = { name: 'Studio Bianchi', auth: { user: { id: 5, name: 'Anna Bianchi', email: 'anna@example.test', roles: chi.ruoli ?? ['amministratore'], permissions: chi.permessi ?? TUTTI } }, ...altro };
    w = mount(BarraStudio, { props, global: { stubs } });

    return w;
}

/** Le voci della barra, nell'ordine (non quelle dentro le tendine, né la fila di misura). */
const voci = (barra: VueWrapper) => barra.findAll('[data-barra-voci] > [data-barra-voce]').map((v) => v.attributes('data-barra-voce'));
const accese = (barra: VueWrapper) => barra.findAll('[data-barra-voci] > [data-barra-voce][data-attiva="true"]').map((v) => v.attributes('data-barra-voce'));
const tendina = (barra: VueWrapper, chiave: string) => barra.get(`[data-barra-voce="${chiave}"] + [data-prova-tendina]`);
const nelCondominio = { condominio: { id: 7, nome: 'Le Rose' }, esercizio: { id: 3, nome: 'Esercizio 2026' } };

describe('le voci dello studio', () => {
    test('nell\'ordine, raggruppate: Dashboard · Anagrafiche · Agenda · Comunicazioni · Documenti · Importa dati · Condomini, che è l\'ultima', () => {
        const barra = monta('/admin/dashboard');
        expect(voci(barra)).toEqual(['dashboard', 'anagrafiche', 'agenda', 'comunicazioni', 'archivio', 'importa', 'condomini']);
        expect(barra.findAll('[data-barra-voci] > [data-barra-voce]').map((v) => v.text())).toEqual(['Dashboard', 'Anagrafiche', 'Agenda', 'Comunicazioni', 'Documenti', 'Importa dati', 'Condomini']);
        expect(tendina(barra, 'anagrafiche').findAll('a').map((a) => [a.text(), a.attributes('href')])).toEqual([['Persone', '/admin/anagrafiche'], ['Fornitori', '/admin/fornitori']]);
        expect(tendina(barra, 'comunicazioni').findAll('a').map((a) => a.attributes('href'))).toEqual(['/admin/comunicazioni', '/admin/segnalazioni']);
        expect(tendina(barra, 'archivio').findAll('a').map((a) => a.attributes('href'))).toEqual(['/admin/documenti', '/admin/allegati']);
        expect(barra.get('[data-barra-voce="agenda"]').attributes('href')).toBe('/admin/eventi');
    });

    test('il marchio porta alla dashboard', () => {
        const marchio = monta('/admin/eventi').get('[data-barra-marchio]');
        expect(marchio.attributes('href')).toBe('/admin/dashboard');
        expect(marchio.text()).toContain('Studio Bianchi');
    });

    test('una tendina in cui si vede una voce sola è un collegamento semplice; una senza voci non c\'è', () => {
        const barra = monta('/admin/dashboard', {}, { permessi: TUTTI.filter((p) => ! ['Visualizza allegati segnalazioni', 'Visualizza segnalazioni', 'Importa dati'].includes(p)) });
        expect(voci(barra)).toEqual(['dashboard', 'anagrafiche', 'agenda', 'bacheca', 'documenti', 'condomini']);
        expect(barra.get('[data-barra-voce="documenti"]').attributes('href')).toBe('/admin/documenti');
        expect(barra.get('[data-barra-voce="bacheca"]').element.tagName).toBe('A');
    });

    test('il condòmino ha le sue: né i condomini, né le anagrafiche, né le impostazioni', () => {
        const barra = monta('/user/dashboard', {}, { ruoli: ['utente'], permessi: ['Visualizza scadenze in agenda', 'Visualizza comunicazioni', 'Visualizza segnalazioni', 'Visualizza documenti archivio'] });
        expect(voci(barra)).toEqual(['dashboard', 'agenda', 'comunicazioni', 'documenti']);
        expect(barra.find('[data-barra-strumenti] [data-barra-voce="impostazioni"]').exists()).toBe(false);
        expect(barra.find('[data-barra-casella]').exists()).toBe(false);
    });
});

describe('il punto fermo', () => {
    test('le stesse voci nello stesso ordine su ogni pagina: nello studio, nelle impostazioni, dentro un condominio', () => {
        const attese = voci(monta('/admin/dashboard'));
        for (const [url, props, altro] of [['/admin/anagrafiche/12', {}, {}], ['/impostazioni/aspetto', {}, {}], ['/admin/gestionale/7/immobili', { gestionale: true }, nelCondominio], ['/admin/gestionale/7/esercizi/3/rendiconto', { gestionale: true }, nelCondominio]] as const) {
            w!.unmount();
            expect(voci(monta(url, props, {}, altro)), url).toEqual(attese);
        }
    });

    test('resta accesa la sezione in cui si è, e solo lei; dentro un condominio, «Condomini»', () => {
        expect(accese(monta('/admin/dashboard'))).toEqual(['dashboard']);
        w!.unmount();
        expect(accese(monta('/admin/fornitori/5/documenti'))).toEqual(['anagrafiche']);
        w!.unmount();
        expect(accese(monta('/condomini'))).toEqual(['condomini']);
        w!.unmount();
        expect(accese(monta('/admin/gestionale/7/immobili', { gestionale: true }, {}, nelCondominio))).toEqual(['condomini']);
        w!.unmount();
        // Le impostazioni non sono una voce a sinistra: è accesa la loro icona, a destra.
        const impostazioni = monta('/impostazioni/aspetto');
        expect(accese(impostazioni)).toEqual([]);
        expect(impostazioni.get('[data-barra-strumenti] [data-barra-voce="impostazioni"]').attributes('data-attiva')).toBe('true');
    });
});

describe('«Condomini ▾»: tutti, e gli ultimi aperti', () => {
    const recenti = (n: number) => Array.from({ length: n }, (_, i) => ({ id: 10 + i, nome: `Condominio ${i + 1}` }));

    test('senza condomini aperti di recente: «Tutti i condomini» soltanto', () => {
        const menu = tendina(monta('/admin/dashboard'), 'condomini');
        expect(menu.findAll('a').map((a) => [a.text(), a.attributes('href')])).toEqual([['Tutti i condomini', '/condomini']]);
        expect(menu.find('[data-barra-condominio]').exists()).toBe(false);
    });

    test('gli ultimi aperti, dal più recente; un clic e ci si entra', async () => {
        localStorage.setItem('km-condomini-recenti:5', JSON.stringify(recenti(3)));
        azzeraCondominioInUso();
        const menu = tendina(monta('/admin/dashboard'), 'condomini');
        expect(menu.get('[data-prova-titoletto]').text()).toBe('Ultimi aperti');
        expect(menu.findAll('[data-barra-condominio]').map((c) => c.text())).toEqual(['Condominio 1', 'Condominio 2', 'Condominio 3']);

        await menu.get('[data-barra-condominio="11"]').trigger('click');
        expect(giro.visit).toHaveBeenCalledWith('/admin/gestionale/11');
    });

    test('al più cinque', () => {
        localStorage.setItem('km-condomini-recenti:5', JSON.stringify(recenti(8)));
        azzeraCondominioInUso();
        expect(tendina(monta('/admin/dashboard'), 'condomini').findAll('[data-barra-condominio]').length).toBe(5);
    });
});

describe('a destra', () => {
    test('la ricerca rapida, la casella con il suo numero, le impostazioni, la guida in un\'altra scheda, l\'utente', async () => {
        const barra = monta('/admin/dashboard', {}, {}, { inbox_count: 4 });
        expect(barra.find('[data-prova-ricerca]').exists()).toBe(false);
        await barra.get('[data-ricerca-campo]').trigger('click');
        expect(barra.find('[data-prova-ricerca]').exists()).toBe(true);

        expect(barra.get('[data-barra-casella]').attributes('href')).toBe('/admin/inbox');
        expect(barra.get('[data-barra-casella]').text()).toBe('4');
        const strumenti = barra.findAll('[data-barra-strumenti] [data-barra-voce]');
        expect(strumenti.map((v) => [v.attributes('data-barra-voce'), v.attributes('href')])).toEqual([['impostazioni', '/impostazioni'], ['guida', 'https://kondomanager.com/docs/index.html']]);
        expect(strumenti[1].attributes('target')).toBe('_blank');
        expect(barra.get('[data-menu-utente]').text()).toContain('AB');
    });

    test('c\'è un aggiornamento da installare: la sua icona e il puntino sulle impostazioni', () => {
        const barra = monta('/admin/dashboard', {}, {}, { system_update: { available: true, new_version: '1.13.0' } });
        expect(barra.get('[data-barra-aggiornamento]').attributes('title')).toContain('1.13.0');
    });
});
