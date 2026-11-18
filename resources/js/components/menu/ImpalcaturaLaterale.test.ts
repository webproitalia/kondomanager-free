// @vitest-environment jsdom

/**
 * L'impalcatura a menu laterale (Web Pro Italia, 18/11/2026): la cornice di ogni pagina — il menu, la barra in alto, l'area di
 * lavoro.
 *
 * Che cosa copre: dentro un condominio l'impalcatura dice al menu qual è il **condominio in uso** (quello della pagina, con
 * il suo esercizio), e il menu se lo ricorda anche nelle pagine dello studio; «Chiudi il condominio», da dentro una sua
 * pagina, lo toglie, e non torna da solo.
 *
 * Che cosa NON copre: il menu e la barra, che qui sono segnaposto (`MenuContenuto.test.ts`, `BarraSuperiore.test.ts`).
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { shallowMount, type VueWrapper } from '@vue/test-utils';
import { nextTick, reactive } from 'vue';

const stato = vi.hoisted(() => ({ pagina: { url: '/admin/dashboard', props: {} as Record<string, unknown> }, on: vi.fn(() => () => {}) }));

vi.mock('@inertiajs/vue3', async () => {
    const vue = await import('vue');
    stato.pagina = vue.reactive(stato.pagina);

    return { usePage: () => stato.pagina, router: { on: stato.on, visit: vi.fn(), reload: vi.fn() } };
});
vi.mock('laravel-vue-i18n', async () => ({ trans: (await import('./provaTraduzioni')).tradotto }));

const ImpalcaturaLaterale = (await import('./ImpalcaturaLaterale.vue')).default;
const { useCondominioInUso, azzeraCondominioInUso } = await import('./condominioInUso');

const CHIAVE = 'km-condominio-in-uso:5';
const ricordo = () => JSON.parse(localStorage.getItem(CHIAVE) ?? 'null');
const utente = { auth: { user: { id: 5, name: 'Anna Bianchi', roles: ['amministratore'], permissions: [] } } };

let w: VueWrapper | null = null;
afterEach(() => {
    w?.unmount();
    w = null;
    localStorage.clear();
    azzeraCondominioInUso();
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
