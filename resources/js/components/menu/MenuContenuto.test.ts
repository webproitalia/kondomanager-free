// @vitest-environment jsdom

/**
 * Il menu laterale a video (Web Pro Italia, 18/11/2026): aperto mostra l'icona e il nome di ogni voce; chiuso solo le icone,
 * con il nome per chi non le vede (`aria-label`) e al passaggio del mouse; dentro un condominio lo studio resta in cima,
 * compatto, e le voci dei Movimenti stanno nel loro cassettino.
 *
 * Che cosa NON copre: quali voci ci sono e per chi (`vociMenu.test.ts`); i suggerimenti e gli elenchi a comparsa, che qui
 * sono sostituiti da contenitori semplici.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import { h } from 'vue';

const pagina = vi.hoisted(() => ({ url: '/admin/dashboard', props: {} as Record<string, unknown> }));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => pagina,
    Link: { props: ['href'], setup: (p: { href: string }, { slots, attrs }: any) => () => h('a', { href: p.href, ...attrs }, slots.default?.()) },
    router: { post: vi.fn(), visit: vi.fn(), flushAll: vi.fn() },
}));
vi.mock('laravel-vue-i18n', async () => ({ trans: (await import('./provaTraduzioni')).tradotto }));

const menu = vi.hoisted(() => ({ toggleSidebar: vi.fn(), telefono: false }));
vi.mock('@/components/ui/sidebar', async () => {
    const { ref } = await import('vue');

    return { useSidebar: () => ({ toggleSidebar: menu.toggleSidebar, isMobile: ref(menu.telefono) }) };
});

const MenuContenuto = (await import('./MenuContenuto.vue')).default;

// I suggerimenti e gli elenchi a comparsa: contenitori che mostrano il loro contenuto.
const contenitore = { inheritAttrs: false, render() { return (this as any).$slots.default?.(); } };
const nascosto = { inheritAttrs: false, render: () => null };
const stubs = {
    Tooltip: contenitore, TooltipTrigger: contenitore, TooltipContent: nascosto,
    DropdownMenu: contenitore, DropdownMenuTrigger: contenitore, DropdownMenuContent: nascosto,
    UserMenuContent: nascosto,
};

const TUTTI = ['Accesso pannello amministratore', 'Gestisci impostazioni generali', 'Visualizza scadenze in agenda', 'Visualizza condomini', 'Visualizza anagrafiche',
    'Visualizza comunicazioni', 'Visualizza segnalazioni', 'Visualizza documenti archivio', 'Visualizza allegati segnalazioni', 'Importa dati'];

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; localStorage.clear(); menu.toggleSidebar.mockClear(); menu.telefono = false; });

function monta(url: string, props: Record<string, unknown> = {}, altro: Record<string, unknown> = {}) {
    pagina.url = url;
    pagina.props = { name: 'Studio Bianchi', auth: { user: { name: 'Anna Bianchi', email: 'anna@example.test', roles: ['amministratore'], permissions: TUTTI } }, ...altro };
    w = mount(MenuContenuto, { props, global: { stubs } });

    return w;
}

const condominio = { condominio: { id: 7, nome: 'Le Rose' }, esercizio: { id: 3, nome: 'Esercizio 2026' } };
const voci = (wrapper: VueWrapper, dove = 'nav') => wrapper.findAll(`${dove} [data-menu-voce]`).map((v) => v.attributes('data-menu-voce'));

describe('il menu aperto, nello studio', () => {
    test('il marchio porta alla dashboard e dice il nome dell\'installazione', () => {
        const marchio = monta('/admin/dashboard').get('a[title="Studio Bianchi"]');
        expect(marchio.attributes('href')).toBe('/admin/dashboard');
        expect(marchio.text()).toContain('Studio Bianchi');
    });

    test('il gruppo «Studio» con le voci in ordine: l\'icona nel suo quadratino colorato e il nome', () => {
        const menu = monta('/admin/eventi');
        expect(menu.get('nav').text()).toContain('Studio');
        expect(voci(menu)).toEqual(['dashboard', 'agenda', 'condomini', 'persone', 'fornitori', 'bacheca', 'segnalazioni', 'documenti', 'allegati', 'importa']);
        const agenda = menu.get('[data-menu-voce="agenda"]');
        expect(agenda.text()).toBe('Agenda');
        expect(agenda.get('.km-icona').attributes('style')).toContain('--c: #e2574c');
        expect(agenda.get('.km-icona svg').exists()).toBe(true);
    });

    test('la voce della pagina è accesa, e solo lei', () => {
        const menu = monta('/admin/eventi/12');
        expect(menu.findAll('[data-attiva="true"]').map((v) => v.attributes('data-menu-voce'))).toEqual(['agenda']);
        expect(menu.get('[data-menu-voce="agenda"]').attributes('aria-current')).toBe('page');
    });

    test('gli allegati in attesa: il numero accanto alla voce', () => {
        expect(monta('/admin/dashboard', {}, { allegati_in_attesa: 3 }).get('[data-menu-voce="allegati"]').text()).toBe('Allegati3');
        expect(monta('/admin/dashboard', {}, { allegati_in_attesa: 0 }).get('[data-menu-voce="allegati"]').text()).toBe('Allegati');
    });

    test('in fondo le impostazioni, la guida (in un\'altra scheda) e l\'utente', () => {
        const menu = monta('/admin/dashboard');
        const fondo = menu.findAll('[data-menu-voce]').slice(-2);
        expect(fondo.map((v) => v.attributes('data-menu-voce'))).toEqual(['impostazioni', 'guida']);
        expect(fondo[1].attributes('target')).toBe('_blank');
        expect(menu.get('[data-menu-utente]').text()).toContain('Anna Bianchi');
        expect(menu.get('[data-menu-utente]').text()).toContain('anna@example.test');
    });
});

describe('il menu aperto, dentro un condominio', () => {
    test('lo studio resta in cima, compatto: le sole icone, con il nome per chi non le vede', () => {
        const menu = monta('/admin/gestionale/7/immobili', { gestionale: true }, condominio);
        const compatto = menu.get('[data-menu-studio-compatto]');
        expect(voci(menu, '[data-menu-studio-compatto]')).toEqual(['dashboard', 'agenda', 'condomini', 'persone', 'fornitori', 'bacheca', 'segnalazioni', 'documenti', 'allegati', 'importa']);
        expect(compatto.text()).toBe('');
        expect(compatto.get('[data-menu-voce="condomini"]').attributes('aria-label')).toBe('Elenco condomìni');
    });

    test('il gruppo ha il nome del condominio e dell\'esercizio, e le sue voci con il nome', () => {
        const menu = monta('/admin/gestionale/7/immobili', { gestionale: true }, condominio);
        expect(menu.get('[data-menu-condominio]').text()).toBe('Le RoseEsercizio 2026');
        expect(menu.get('[data-menu-voce="unita"]').text()).toBe('Unità');
        expect(menu.get('[data-menu-voce="unita"]').attributes('title')).toBe('Immobili');
        expect(menu.get('[data-menu-voce="unita"]').attributes('data-attiva')).toBe('true');
    });

    test('«Movimenti» è un cassettino: chiuso fuori dai movimenti, aperto da solo nelle loro pagine con la voce accesa', async () => {
        const fuori = monta('/admin/gestionale/7/immobili', { gestionale: true }, condominio);
        expect(fuori.get('[data-menu-voce="movimenti"]').element.tagName).toBe('BUTTON');
        expect(fuori.find('[data-menu-voce="fatture"]').exists()).toBe(false);
        fuori.unmount();

        const dentro = monta('/admin/gestionale/7/fatture/9', { gestionale: true }, condominio);
        expect(dentro.get('[data-menu-voce="movimenti"]').attributes('data-attiva')).toBe('dentro');
        expect(dentro.get('[data-menu-voce="fatture"]').attributes('data-attiva')).toBe('true');
        expect(dentro.findAll('[data-attiva="true"]').length).toBe(1);
    });

    test('il cassettino si apre e si chiude con un clic, e si ricorda', async () => {
        const menu = monta('/admin/gestionale/7/immobili', { gestionale: true }, condominio);
        await menu.get('[data-menu-voce="movimenti"]').trigger('click');
        expect(menu.find('[data-menu-voce="fatture"]').exists()).toBe(true);
        expect(localStorage.getItem('km-menu-aperta-movimenti')).toBe('true');
    });
});

describe('richiudere e riaprire il menu dal menu stesso', () => {
    test('aperto: accanto al nome c\'è la freccia che lo richiude', async () => {
        const aperto = monta('/admin/dashboard');
        expect(aperto.find('[data-menu-riapri]').exists()).toBe(false);
        const freccia = aperto.get('[data-menu-richiudi]');
        expect(freccia.attributes('aria-label')).toBe('Richiudi il menu');
        await freccia.trigger('click');
        expect(menu.toggleSidebar).toHaveBeenCalledTimes(1);
    });

    test('chiuso: sotto il marchio c\'è la freccia che lo riapre', async () => {
        const chiuso = monta('/admin/dashboard', { chiuso: true });
        expect(chiuso.find('[data-menu-richiudi]').exists()).toBe(false);
        const freccia = chiuso.get('[data-menu-riapri]');
        expect(freccia.attributes('aria-label')).toBe('Apri il menu');
        await freccia.trigger('click');
        expect(menu.toggleSidebar).toHaveBeenCalledTimes(1);
    });

    test('nel cassetto del telefono la freccia è una × che lo chiude', () => {
        menu.telefono = true;
        expect(monta('/admin/dashboard').get('[data-menu-richiudi]').attributes('aria-label')).toBe('Chiudi il menu');
    });
});

describe('il menu chiuso', () => {
    test('solo le icone: nessun nome scritto, ma ogni voce dice il suo nome a chi non la vede', () => {
        const menu = monta('/admin/eventi', { chiuso: true });
        expect(menu.get('nav').text()).toBe('');
        expect(menu.get('[data-menu-voce="agenda"]').attributes('aria-label')).toBe('Agenda');
        expect(menu.get('[data-menu-voce="agenda"]').attributes('data-attiva')).toBe('true');
        // La voce accesa ha anche la tacca sul bordo, e solo lei.
        expect(menu.findAll('.km-tacca').length).toBe(1);
        expect(menu.get('[data-menu-voce="agenda"]').find('.km-tacca').exists()).toBe(true);
        expect(menu.get('[data-menu-utente]').text()).toBe('AB');
    });

    test('dentro un condominio lo studio è una sola icona, e «Movimenti» un\'icona che apre l\'elenco accanto', () => {
        const menu = monta('/admin/gestionale/7/fatture', { gestionale: true, chiuso: true }, condominio);
        expect(menu.find('[data-menu-studio-compatto]').exists()).toBe(false);
        expect(menu.get('[data-menu-voce="studio"]').attributes('aria-label')).toBe('Studio');
        expect(menu.get('[data-menu-voce="movimenti"]').attributes('data-attiva')).toBe('true');
        expect(menu.find('[data-menu-condominio]').exists()).toBe(false);
    });
});
