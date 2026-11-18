// @vitest-environment jsdom

/**
 * L'elenco in cui si sceglie un condominio (Web Pro Italia, 18/11/2026): lo aprono il selettore della barra in alto e la riga
 * di testa del gruppo del condominio nel menu. Segna quello in cui si è; in fondo porta all'elenco di tutti e — solo nel menu,
 * e solo se c'è un condominio in uso — offre «Chiudi il condominio».
 *
 * Che cosa NON copre: la ricerca per nome, che è del componente `Command` del kit (qui l'elenco è fatto di contenitori
 * semplici); che cosa succede dopo la scelta (`MenuContenuto.test.ts`, `BarraSuperiore.test.ts`).
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';

const pagina = vi.hoisted(() => ({ url: '/admin/dashboard', props: {} as Record<string, unknown> }));
const giro = vi.hoisted(() => ({ visit: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({ usePage: () => pagina, router: { visit: giro.visit } }));

const ElencoCondomini = (await import('./ElencoCondomini.vue')).default;

const contenitore = { inheritAttrs: false, render() { return (this as any).$slots.default?.(); } };
const stubs = {
    Command: contenitore, CommandList: contenitore, CommandGroup: contenitore,
    CommandEmpty: { render: () => null }, CommandSeparator: { render: () => null },
    CommandInput: { template: '<input data-campo />' },
    CommandItem: { emits: ['select'], template: '<button type="button" data-voce @click="$emit(\'select\')"><slot /></button>' },
};

const condomini = [{ id: 7, nome: 'Le Rose' }, { id: 9, nome: 'I Tigli' }];

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; giro.visit.mockClear(); });

function monta(props: Record<string, unknown> = {}, permessi: string[] = ['Visualizza condomini']) {
    pagina.props = { auth: { user: { id: 5, roles: ['amministratore'], permissions: permessi } } };
    w = mount(ElencoCondomini, { props: { condomini, ...props }, global: { stubs } });

    return w;
}

const nomi = (elenco: VueWrapper) => elenco.findAll('[data-voce]').map((v) => v.text());

describe('l\'elenco dei condomini', () => {
    test('i condomini per nome, e in fondo «Tutti i condomini», che porta al loro elenco', async () => {
        const elenco = monta({ attuale: 7 });
        expect(nomi(elenco)).toEqual(['Le Rose', 'I Tigli', 'Tutti i condomini']);
        await elenco.findAll('[data-voce]')[2].trigger('click');
        expect(giro.visit).toHaveBeenCalledWith('/condomini');
    });

    test('quello in cui si è ha la spunta ed è in grassetto', () => {
        const voci = monta({ attuale: 9 }).findAll('[data-voce]');
        expect(voci[1].find('svg.lucide-check-icon').exists()).toBe(true);
        expect(voci[1].get('span').classes()).toContain('font-semibold');
        expect(voci[0].find('svg.lucide-check-icon').exists()).toBe(false);
    });

    test('la scelta dice quale condominio', async () => {
        const elenco = monta();
        await elenco.findAll('[data-voce]')[1].trigger('click');
        expect(elenco.emitted('scelto')).toEqual([[{ id: 9, nome: 'I Tigli' }]]);
    });

    test('chi non può vedere l\'elenco dei condomini non ha «Tutti i condomini»', () => {
        expect(nomi(monta({ attuale: 7 }, []))).toEqual(['Le Rose', 'I Tigli']);
    });
});

describe('«Chiudi il condominio»', () => {
    test('nel menu, con un condominio in uso: è l\'ultima voce, e avvisa chi ha aperto l\'elenco', async () => {
        const elenco = monta({ attuale: 7, chiudibile: true });
        expect(nomi(elenco)).toEqual(['Le Rose', 'I Tigli', 'Tutti i condomini', 'Chiudi il condominio']);
        await elenco.get('[data-chiudi-condominio]').trigger('click');
        expect(elenco.emitted('chiudi')).toHaveLength(1);
        expect(elenco.emitted('scelto')).toBeUndefined();
        expect(giro.visit).not.toHaveBeenCalled();
    });

    test('c\'è anche per chi non può vedere l\'elenco dei condomini', () => {
        expect(nomi(monta({ attuale: 7, chiudibile: true }, []))).toEqual(['Le Rose', 'I Tigli', 'Chiudi il condominio']);
    });

    test('senza un condominio in uso non c\'è niente da chiudere', () => {
        expect(monta({ attuale: null, chiudibile: true }).find('[data-chiudi-condominio]').exists()).toBe(false);
    });

    test('nel selettore della barra in alto non c\'è', () => {
        expect(monta({ attuale: 7 }).find('[data-chiudi-condominio]').exists()).toBe(false);
    });
});
