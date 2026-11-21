// @vitest-environment jsdom

/**
 * `AppLayout` e `GestionaleLayout` scelgono l'impalcatura (Web Pro Italia, 18/11/2026): quella a menu laterale, o — con «Menu: in
 * alto» in Impostazioni → Aspetto — quella di sempre, che non cambia. Le pagine non se ne accorgono: passano le stesse briciole
 * e riempiono gli stessi slot.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';
import { h } from 'vue';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    usePage: () => ({ url: '/admin/dashboard', props: { auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));
vi.mock('laravel-vue-i18n', () => ({ trans: (chiave: string) => chiave }));

afterEach(() => {
    delete document.documentElement.dataset.kmMenu;
    // La scelta si legge quando il modulo dell'impalcatura si carica: ogni prova lo ricarica.
    vi.resetModules();
});

const briciole = [{ title: 'Impostazioni', href: '/impostazioni' }, { title: 'Aspetto', href: '/impostazioni/aspetto' }];

async function monta(file: 'AppLayout' | 'GestionaleLayout', menu: 'alto' | 'laterale' | null, slots: Record<string, () => unknown> = {}) {
    if (menu) {
        document.documentElement.dataset.kmMenu = menu;
    }
    const Layout = (file === 'AppLayout' ? await import('./AppLayout.vue') : await import('./GestionaleLayout.vue')).default;
    const [laterale, alto] = file === 'AppLayout'
        ? [(await import('./app/AppMenuLateraleLayout.vue')).default, (await import('./app/AppHeaderLayout.vue')).default]
        : [(await import('./gestionale/GestionaleMenuLateraleLayout.vue')).default, (await import('./gestionale/GestionaleHeaderLayout.vue')).default];
    const w = shallowMount(Layout, { props: { breadcrumbs: briciole }, slots: { default: () => h('p', 'pagina'), ...slots } });

    return { laterale: w.findComponent(laterale), alto: w.findComponent(alto) };
}

describe('AppLayout', () => {
    test('la pagina dice «laterale» (è il predefinito dell\'installazione): il menu laterale, con le briciole della pagina', async () => {
        const { laterale, alto } = await monta('AppLayout', 'laterale');
        expect(laterale.exists()).toBe(true);
        expect(laterale.props('breadcrumbs')).toEqual(briciole);
        expect(alto.exists()).toBe(false);
    });

    test('«Barra in alto»: la stessa impalcatura nuova (che mette la barra dello studio e, dentro un condominio, il menu)', async () => {
        const { laterale, alto } = await monta('AppLayout', 'barra' as 'laterale');
        expect([laterale.exists(), alto.exists()]).toEqual([true, false]);
        const gestionale = await monta('GestionaleLayout', 'barra' as 'laterale');
        expect([gestionale.laterale.exists(), gestionale.alto.exists()]).toEqual([true, false]);
    });

    test('«Menu: in alto»: l\'impalcatura di sempre, con le stesse briciole', async () => {
        const { laterale, alto } = await monta('AppLayout', 'alto');
        expect(alto.exists()).toBe(true);
        expect(alto.props('breadcrumbs')).toEqual(briciole);
        expect(laterale.exists()).toBe(false);
    });

    test('la pagina non dice niente: l\'impalcatura di sempre, che è la via di ritorno', async () => {
        const { laterale, alto } = await monta('AppLayout', null);
        expect([laterale.exists(), alto.exists()]).toEqual([false, true]);
    });
});

describe('GestionaleLayout', () => {
    test('la pagina dice «laterale»: il menu laterale', async () => {
        const { laterale, alto } = await monta('GestionaleLayout', 'laterale');
        expect([laterale.exists(), alto.exists()]).toEqual([true, false]);
        expect(laterale.props('breadcrumbs')).toEqual(briciole);
    });

    test('«Menu: in alto»: l\'impalcatura di sempre', async () => {
        const { laterale, alto } = await monta('GestionaleLayout', 'alto');
        expect([laterale.exists(), alto.exists()]).toEqual([false, true]);
        expect(alto.props('breadcrumbs')).toEqual(briciole);
    });

    test('gli slot dei selettori arrivano all\'impalcatura solo se la pagina li riempie: vuoti, la barra mette i suoi', async () => {
        const senza = await monta('GestionaleLayout', 'laterale');
        expect(Object.keys(senza.laterale.vm.$slots)).toEqual(['default']);
    });

    test('…e se la pagina li riempie, arrivano', async () => {
        const con = await monta('GestionaleLayout', 'laterale', { 'breadcrumb-condominio': () => h('span', 'Le Rose') });
        expect(Object.keys(con.laterale.vm.$slots).sort()).toEqual(['breadcrumb-condominio', 'default']);
    });
});
