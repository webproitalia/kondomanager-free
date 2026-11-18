// @vitest-environment jsdom

/**
 * La testata delle pagine nelle due impalcature (Web Pro Italia, 18/11/2026).
 *
 * Con il menu in alto `PageHeaderGuide` è quello di sempre: il percorso, i selettori del condominio e dell'esercizio,
 * «Dashboard social», il titolo. Con il menu laterale è una scheda con l'icona colorata della sezione; il condominio,
 * l'esercizio e il ritorno allo studio non ci sono più, perché stanno nella barra in alto, e la testata passa alla barra gli
 * elenchi che la pagina le dà. I comandi della pagina (indietro, guida, lo slot `actions`) restano in tutti e due i casi.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import { defineComponent, h } from 'vue';

const pagina = vi.hoisted(() => ({ url: '/admin/gestionale/7/esercizi/3/piani-rate', props: {} as Record<string, unknown> }));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => pagina,
    Link: { props: ['href'], setup: (p: { href: string }, { slots, attrs }: any) => () => h('a', { href: p.href, ...attrs }, slots.default?.()) },
    router: { visit: vi.fn() },
}));
vi.mock('laravel-vue-i18n', async () => ({ trans: (await import('./provaTraduzioni')).tradotto }));

let w: VueWrapper | null = null;
afterEach(() => {
    w?.unmount();
    w = null;
    delete document.documentElement.dataset.kmMenu;
    vi.resetModules();
});

const condominio = { id: 7, nome: 'Le Rose' };
const condomini = [condominio, { id: 9, nome: 'I Tigli' }];
const esercizi = [{ id: 3, nome: 'Esercizio 2026' }, { id: 2, nome: 'Esercizio 2025' }];
const dati = {
    pageTitle: 'Piani rate',
    pageSubtitle: 'Le rate dell\'esercizio.',
    guides: [],
    breadcrumbs: [{ title: 'Gestionale', href: '/admin/gestionale/7' }, { title: 'Esercizi', href: '/admin/gestionale/7/esercizi' }, { title: 'Piani rate', href: '#' }],
    condominio,
    condomini,
    esercizio: esercizi[0],
    esercizi,
    backUrl: '/admin/gestionale/7/esercizi',
    backText: 'Torna agli esercizi',
};

async function monta(menu: 'alto' | 'laterale', gestionale = true) {
    document.documentElement.dataset.kmMenu = menu;
    pagina.props = { auth: { user: { roles: ['amministratore'], permissions: [] } }, condominio, esercizio: esercizi[0] };
    const PageHeaderGuide = (await import('@/components/PageHeaderGuide.vue')).default;
    const { offriPercorsoPagina } = await import('./percorsoPagina');
    let percorso: ReturnType<typeof offriPercorsoPagina> | null = null;
    // L'impalcatura a menu laterale offre alla testata il posto dove dire che cosa la pagina fa scegliere.
    const Impalcatura = defineComponent({
        setup(_, { slots }) {
            if (menu === 'laterale') {
                percorso = offriPercorsoPagina(gestionale);
            }

            return () => slots.default?.();
        },
    });
    w = mount(Impalcatura, { slots: { default: () => h(PageHeaderGuide, dati, { actions: () => h('button', { 'data-comando': 'nuovo' }, 'Nuovo piano') }) } });

    return { w, percorso: percorso as ReturnType<typeof offriPercorsoPagina> | null };
}

describe('con il menu in alto la testata è quella di sempre', () => {
    test('il percorso, i due selettori, «Dashboard social», il titolo e i comandi della pagina', async () => {
        const { w } = await monta('alto');
        expect(w.find('[data-testata-pagina]').exists()).toBe(false);
        expect(w.text()).toContain('Gestionale');
        expect(w.text()).toContain('Le Rose');
        expect(w.text()).toContain('esercizio 2026');
        expect(w.text()).toContain('Dashboard social');
        expect(w.get('h1').text()).toBe('Piani rate');
        expect(w.find('[data-comando="nuovo"]').exists()).toBe(true);
        expect(w.text()).toContain('Torna agli esercizi');
    });
});

describe('con il menu laterale la testata è una scheda con l\'icona della sezione', () => {
    test('l\'icona e il colore sono quelli della voce del menu in cui ci si trova', async () => {
        const { w } = await monta('laterale');
        const testata = w.get('[data-testata-pagina]');
        expect(testata.get('.km-icona').attributes('style')).toContain('--c: #e0a020');
        expect(testata.get('h1').text()).toBe('Piani rate');
        expect(testata.text()).toContain('Le rate dell\'esercizio.');
    });

    test('il percorso della pagina: senza la pagina stessa e senza «Gestionale», che è il condominio della barra', async () => {
        const { w } = await monta('laterale');
        const percorso = w.get('[data-testata-pagina] nav');
        expect(percorso.text()).toBe('Esercizi');
        expect(percorso.get('a').attributes('href')).toBe('/admin/gestionale/7/esercizi');
    });

    test('il condominio, l\'esercizio e «Dashboard social» non sono in pagina: stanno nella barra', async () => {
        const { w } = await monta('laterale');
        expect(w.text()).not.toContain('Dashboard social');
        expect(w.text()).not.toContain('Le Rose');
        expect(w.text()).not.toContain('sercizio 2026');
    });

    test('i comandi della pagina restano: indietro e lo slot dei comandi', async () => {
        const { w } = await monta('laterale');
        expect(w.get('[data-testata-pagina] a[href="/admin/gestionale/7/esercizi"]:not(nav a)').text()).toBe('Torna agli esercizi');
        expect(w.get('[data-testata-pagina] [data-comando="nuovo"]').text()).toBe('Nuovo piano');
    });

    test('la testata dice alla barra che cosa la pagina fa scegliere: il cambio di condominio sul posto e gli esercizi', async () => {
        const { percorso } = await monta('laterale');
        expect(percorso?.cambiaCondominioSulPosto).toBe(true);
        expect(percorso?.esercizi.map((e) => e.id)).toEqual([3, 2]);
        expect(percorso?.esercizio?.id).toBe(3);
    });
});
