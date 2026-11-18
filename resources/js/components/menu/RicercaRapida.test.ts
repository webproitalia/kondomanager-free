// @vitest-environment jsdom

/**
 * La ricerca rapida (Web Pro Italia, 18/11/2026): Ctrl+K o ⌘K la apre da qualunque punto; elenca le sezioni che l'utente può
 * aprire — le stesse voci del menu, con quelle dei Movimenti una per una — e i condomini per nome; una scelta porta alla pagina.
 *
 * Che cosa NON copre: il filtro mentre si scrive, che è del componente `Command` del kit; qui la finestra e l'elenco sono
 * contenitori semplici.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const pagina = vi.hoisted(() => ({ url: '/admin/dashboard', props: {} as Record<string, unknown> }));
const giro = vi.hoisted(() => ({ visit: vi.fn(), get: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({ usePage: () => pagina, router: { visit: giro.visit } }));
vi.mock('laravel-vue-i18n', async () => ({ trans: (await import('./provaTraduzioni')).tradotto }));
vi.mock('axios', () => ({ default: { get: giro.get } }));
vi.stubGlobal('route', (nome: string) => (nome === 'admin.inbox' ? '/admin/inbox' : `/${nome}`));

const RicercaRapida = (await import('./RicercaRapida.vue')).default;
const { dimenticaElencoCondomini } = await import('./elencoCondomini');

const contenitore = { inheritAttrs: false, render() { return (this as any).$slots.default?.(); } };
const stubs = {
    Dialog: { props: ['open'], template: '<div v-if="open" data-finestra><slot /></div>' },
    DialogContent: contenitore, DialogTitle: contenitore, DialogDescription: contenitore,
    Command: contenitore, CommandList: contenitore, CommandEmpty: { render: () => null },
    CommandInput: { template: '<input data-campo />' },
    CommandGroup: { props: ['heading'], template: '<section :data-gruppo="heading"><slot /></section>' },
    CommandItem: { emits: ['select'], template: '<button type="button" data-voce @click="$emit(\'select\')"><slot /></button>' },
};

const TUTTI = ['Accesso pannello amministratore', 'Gestisci impostazioni generali', 'Visualizza scadenze in agenda', 'Visualizza condomini', 'Visualizza anagrafiche',
    'Visualizza comunicazioni', 'Visualizza segnalazioni', 'Visualizza documenti archivio', 'Importa dati'];

let w: VueWrapper | null = null;
afterEach(() => {
    w?.unmount();
    w = null;
    giro.visit.mockClear();
    giro.get.mockReset();
    dimenticaElencoCondomini();
});

function monta(url: string, props: Record<string, unknown> = {}, altro: Record<string, unknown> = {}, chi: { ruoli?: string[]; permessi?: string[] } = {}) {
    pagina.url = url;
    pagina.props = { auth: { user: { roles: chi.ruoli ?? ['amministratore'], permissions: chi.permessi ?? TUTTI } }, ...altro };
    giro.get.mockResolvedValue({ data: [{ id: 1, nome: 'Le Rose' }, { id: 2, nome: 'I Tigli' }] });
    w = mount(RicercaRapida, { props: { open: true, ...props }, global: { stubs }, attachTo: document.body });

    return w;
}

const gruppi = (ricerca: VueWrapper) => ricerca.findAll('[data-gruppo]').map((g) => g.attributes('data-gruppo'));
/** Il nome di ogni voce di un gruppo: il primo testo dopo l'icona. */
const vociDi = (ricerca: VueWrapper, gruppo: string) => ricerca.findAll(`[data-gruppo="${gruppo}"] [data-voce]`).map((v) => v.findAll('span').map((s) => s.text()).find(Boolean));
const scegli = async (ricerca: VueWrapper, nome: string) => {
    await ricerca.findAll('[data-voce]').find((v) => v.text().startsWith(nome))!.trigger('click');
};

describe('aprire la ricerca', () => {
    test('Ctrl+K e ⌘K la aprono e la chiudono, da qualunque punto della pagina', async () => {
        const ricerca = monta('/admin/dashboard', { open: false });
        expect(ricerca.find('[data-finestra]').exists()).toBe(false);

        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'k', ctrlKey: true }));
        await flushPromises();
        expect(ricerca.emitted('update:open')?.at(-1)).toEqual([true]);

        await ricerca.setProps({ open: true });
        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'K', metaKey: true }));
        await flushPromises();
        expect(ricerca.emitted('update:open')?.at(-1)).toEqual([false]);
    });

    test('la lettera da sola non fa niente', async () => {
        const ricerca = monta('/admin/dashboard', { open: false });
        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'k' }));
        await flushPromises();
        expect(ricerca.emitted('update:open')).toBeUndefined();
    });
});

describe('che cosa si trova', () => {
    test('nello studio: le sezioni dello studio con la casella delle attività, i condomini, le impostazioni, la guida e il codice sorgente', async () => {
        const ricerca = monta('/admin/dashboard', {}, { inbox_count: 6 });
        await flushPromises();
        expect(gruppi(ricerca)).toEqual(['Studio', 'Apri un condominio', 'Altro']);
        expect(vociDi(ricerca, 'Studio')).toEqual(['Dashboard', 'Agenda', 'Condomini', 'Persone', 'Fornitori', 'Bacheca', 'Segnalazioni', 'Documenti', 'Importa dati', 'Casella delle attività']);
        expect(vociDi(ricerca, 'Apri un condominio')).toEqual(['Le Rose', 'I Tigli']);
        expect(vociDi(ricerca, 'Altro')).toEqual(['Impostazioni', 'Guida', 'Codice sorgente']);
    });

    test('i condomini si chiedono alla prima apertura, non prima', async () => {
        const ricerca = monta('/admin/dashboard', { open: false });
        await flushPromises();
        expect(giro.get).not.toHaveBeenCalled();
        await ricerca.setProps({ open: true });
        await flushPromises();
        expect(giro.get).toHaveBeenCalledTimes(1);
    });

    test('dentro un condominio: prima le sue sezioni, con le voci dei Movimenti una per una e il nome intero accanto', async () => {
        const ricerca = monta('/admin/gestionale/7/immobili', { gestionale: true }, { condominio: { id: 7, nome: 'Le Rose' }, esercizio: { id: 3, nome: 'Esercizio 2026' }, condomini: [{ id: 7, nome: 'Le Rose' }] });
        await flushPromises();
        expect(gruppi(ricerca)[0]).toBe('Le Rose');
        expect(vociDi(ricerca, 'Le Rose')).toEqual(['Cruscotto', 'Condominio', 'Unità', 'Tabelle', 'Esercizi', 'Gestioni', 'Conti', 'Rate',
            'Incassi', 'Rate scadute', 'Fatture', 'Pagamenti', 'Giroconti', 'Ritenute e F24', 'Libro giornale', 'Registro', 'Rendiconto', 'Fascicolo di bilancio',
            'Assemblee', 'Registrazioni']);
        // «Unità» si trova anche scrivendo «immobili»: il nome intero è scritto accanto.
        expect(ricerca.findAll('[data-gruppo="Le Rose"] [data-voce]')[2].text()).toBe('UnitàImmobili');
        // I condomini sono quelli della pagina: nessuna chiamata.
        expect(giro.get).not.toHaveBeenCalled();
    });

    test('il condòmino trova le sue sezioni: né i condomini, né la casella, né le impostazioni', async () => {
        const ricerca = monta('/user/dashboard', {}, {}, { ruoli: ['utente'], permessi: ['Visualizza comunicazioni', 'Visualizza scadenze in agenda'] });
        await flushPromises();
        expect(gruppi(ricerca)).toEqual(['Il mio condominio', 'Altro']);
        expect(vociDi(ricerca, 'Il mio condominio')).toEqual(['Dashboard', 'Agenda', 'Bacheca']);
        expect(vociDi(ricerca, 'Altro')).toEqual(['Guida', 'Codice sorgente']);
        expect(giro.get).not.toHaveBeenCalled();
    });
});

describe('scegliere', () => {
    test('una sezione: si va alla sua pagina e la ricerca si chiude', async () => {
        const ricerca = monta('/admin/dashboard');
        await flushPromises();
        await scegli(ricerca, 'Agenda');
        expect(giro.visit).toHaveBeenCalledWith('/admin/eventi');
        expect(ricerca.emitted('update:open')?.at(-1)).toEqual([false]);
    });

    test('un condominio: si apre il suo cruscotto', async () => {
        const ricerca = monta('/admin/dashboard');
        await flushPromises();
        await scegli(ricerca, 'I Tigli');
        expect(giro.visit).toHaveBeenCalledWith('/admin/gestionale/2');
    });

    test('la guida e il codice sorgente si aprono in un\'altra scheda', async () => {
        const apri = vi.spyOn(window, 'open').mockImplementation(() => null);
        const ricerca = monta('/admin/dashboard');
        await flushPromises();
        await scegli(ricerca, 'Guida');
        expect(apri).toHaveBeenCalledWith('https://kondomanager.com/docs/index.html', '_blank', 'noopener');
        expect(giro.visit).not.toHaveBeenCalled();
        apri.mockRestore();
    });
});
