// @vitest-environment jsdom

/**
 * Impostazioni → Aspetto: lo stile dell'area di lavoro (Web Pro Italia, 21/11/2026).
 *
 * «Professionale» (predefinito) o «morbido», quello di prima. La scelta si prova subito: la pagina prende lo stile scelto
 * (`<html data-km-stile>`), e uscendo senza salvare torna quello salvato. Lo stile professionale c'è solo con il menu
 * laterale, e con lui gli angoli sono quelli dello stile: la scelta degli angoli si spegne e lo dice.
 *
 * Che cosa NON copre: che cosa cambia nelle pagine (`resources/css/stile-professionale.css`, e il suo controllo in
 * `resources/js/composables/stileProfessionale.test.ts`); il salvataggio (`tests/Feature/Impostazioni/AspettoStileTest.php`).
 *
 * Le traduzioni non sono caricate: `trans()` restituisce la chiave.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { shallowMount, type VueWrapper } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<div />' },
    usePage: () => ({ props: { flash: {} } }),
}));
vi.mock('laravel-vue-i18n', () => ({ trans: (chiave: string) => chiave }));

const Pagina = (await import('./impostazioniAspetto.vue')).default;

const scelte = {
    colori: [{ valore: 'grafite', nome: 'Grafite (originale)', campione: '#171717' }, { valore: 'glicine', nome: 'Glicine', campione: '#71639e' }],
    angoli: [{ valore: 'standard', nome: 'Standard' }, { valore: 'morbidi', nome: 'Morbidi' }],
    menu: [{ valore: 'barra', nome: 'Barra in alto' }, { valore: 'laterale', nome: 'Laterale' }, { valore: 'alto', nome: 'In alto, com\'era prima' }],
    schede: [{ valore: 'lato', nome: 'Di lato' }, { valore: 'pagina', nome: 'A pagina intera' }],
    stile: [{ valore: 'professionale', nome: 'Professionale' }, { valore: 'morbido', nome: 'Morbido' }],
    anteprime: {},
};

let w: VueWrapper | null = null;
afterEach(() => {
    w?.unmount();
    w = null;
    delete document.documentElement.dataset.kmStile;
    delete document.documentElement.dataset.kmMenu;
});

/** La pagina com'è arrivata dal server: il menu e lo stile salvati sono scritti su `<html>`. */
function monta(aspetto: Partial<{ menu: string; stile: string }> = {}) {
    const salvato = { colore: 'glicine', angoli: 'standard', menu: 'laterale', schede: 'lato', stile: 'professionale', ...aspetto };
    document.documentElement.dataset.kmMenu = salvato.menu;
    document.documentElement.dataset.kmStile = salvato.menu !== 'alto' ? salvato.stile : 'morbido';
    w = shallowMount(Pagina, { props: { aspetto: salvato, scelte }, global: { renderStubDefaultSlot: true } });

    return w;
}

const stileDellaPagina = () => document.documentElement.dataset.kmStile;
const scelta = (pagina: VueWrapper, valore: string) => pagina.get(`[data-stile-scelta="${valore}"]`);
const angoli = (pagina: VueWrapper) => pagina.findAll('button').filter((b) => ['Standard', 'Morbidi'].includes(b.text()));

describe('lo stile dell\'area di lavoro', () => {
    test('le due scelte, con quella salvata segnata', () => {
        const pagina = monta();
        expect(pagina.findAll('[data-stile-scelta]').map((b) => b.attributes('data-stile-scelta'))).toEqual(['professionale', 'morbido']);
        expect(scelta(pagina, 'professionale').attributes('aria-pressed')).toBe('true');
        expect(scelta(pagina, 'morbido').attributes('aria-pressed')).toBe('false');
    });

    test('si prova subito: scegliendo «morbido» la pagina lo prende, e tornando a «professionale» lo riprende', async () => {
        const pagina = monta();
        await scelta(pagina, 'morbido').trigger('click');
        expect(stileDellaPagina()).toBe('morbido');
        expect(scelta(pagina, 'morbido').attributes('aria-pressed')).toBe('true');

        await scelta(pagina, 'professionale').trigger('click');
        expect(stileDellaPagina()).toBe('professionale');
    });

    test('uscendo senza salvare la pagina torna allo stile salvato', async () => {
        const pagina = monta();
        await scelta(pagina, 'morbido').trigger('click');
        pagina.unmount();
        w = null;
        expect(stileDellaPagina()).toBe('professionale');
    });

    test('con lo stile professionale gli angoli sono quelli dello stile: la scelta si spegne e lo dice; con «morbido» torna', async () => {
        const pagina = monta();
        expect(angoli(pagina).every((b) => b.attributes('disabled') !== undefined)).toBe(true);
        expect(pagina.find('[data-angoli-con-stile]').exists()).toBe(true);

        await scelta(pagina, 'morbido').trigger('click');
        expect(angoli(pagina).every((b) => b.attributes('disabled') === undefined)).toBe(true);
        expect(pagina.find('[data-angoli-con-stile]').exists()).toBe(false);
    });

    test('con il menu in alto lo stile professionale non c\'è: le scelte sono spente, e la pagina resta com\'è', async () => {
        const pagina = monta({ menu: 'alto' });
        expect(pagina.findAll('[data-stile-scelta]').every((b) => b.attributes('disabled') !== undefined)).toBe(true);
        expect(pagina.text()).toContain('impostazioni.aspetto.stile_solo_laterale');
        // Gli angoli si scelgono: lo stile di prima li rispetta.
        expect(angoli(pagina).every((b) => b.attributes('disabled') === undefined)).toBe(true);
        expect(stileDellaPagina()).toBe('morbido');
    });

    test('scegliendo «Menu: in alto» dalla pagina, lo stile a video non cambia finché non si salva: il menu cambia al salvataggio', async () => {
        const pagina = monta();
        await pagina.get('[data-menu-scelta="alto"]').trigger('click');
        // La scelta dello stile si spegne, e gli angoli tornano a scegliersi…
        expect(pagina.findAll('[data-stile-scelta]').every((b) => b.attributes('disabled') !== undefined)).toBe(true);
        expect(angoli(pagina).every((b) => b.attributes('disabled') === undefined)).toBe(true);
        // …e l'anteprima mostra ciò che si avrà: il menu in alto ha lo stile di prima.
        expect(stileDellaPagina()).toBe('morbido');
    });

    test('le tre scelte del menu; con la barra in alto lo stile e le schede si scelgono, come con il menu laterale', async () => {
        const pagina = monta({ menu: 'barra' });
        expect(pagina.findAll('[data-menu-scelta]').map((b) => b.attributes('data-menu-scelta'))).toEqual(['barra', 'laterale', 'alto']);
        expect(pagina.get('[data-menu-scelta="barra"]').attributes('aria-pressed')).toBe('true');
        expect(pagina.findAll('[data-stile-scelta], [data-schede-scelta]').every((b) => b.attributes('disabled') === undefined)).toBe(true);
        await scelta(pagina, 'morbido').trigger('click');
        expect(stileDellaPagina()).toBe('morbido');
    });
});
