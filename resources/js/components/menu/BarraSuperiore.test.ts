// @vitest-environment jsdom

/**
 * La barra in alto dell'impalcatura a menu laterale (Web Pro Italia, 18/11/2026): il tasto del menu, il percorso «Studio ›
 * condominio › esercizio» con i suoi selettori, la ricerca, la casella delle attività con il numero di quelle in sospeso e
 * l'aggiornamento da installare.
 *
 * Che cosa NON copre: gli elenchi a comparsa dei selettori e della ricerca (qui sono contenitori semplici) e dove porta il
 * cambio di condominio (`percorsoPagina.test.ts`).
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import { defineComponent, h, ref } from 'vue';

const pagina = vi.hoisted(() => ({ url: '/admin/dashboard', props: {} as Record<string, unknown> }));
const menu = vi.hoisted(() => ({ toggleSidebar: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => pagina,
    Link: { props: ['href'], setup: (p: { href: string }, { slots, attrs }: any) => () => h('a', { href: p.href, ...attrs }, slots.default?.()) },
    router: { visit: vi.fn() },
}));
vi.mock('laravel-vue-i18n', async () => ({ trans: (await import('./provaTraduzioni')).tradotto }));
vi.mock('@/components/ui/sidebar', () => ({
    useSidebar: () => ({ toggleSidebar: menu.toggleSidebar, state: ref('expanded'), isMobile: ref(false) }),
}));
vi.stubGlobal('route', (nome: string) => (nome === 'admin.inbox' ? '/admin/inbox' : `/${nome}`));

const BarraSuperiore = (await import('./BarraSuperiore.vue')).default;
const { offriPercorsoPagina } = await import('./percorsoPagina');

const contenitore = { inheritAttrs: false, render() { return (this as any).$slots.default?.(); } };
const nascosto = { inheritAttrs: false, render: () => null };
const stubs = {
    Tooltip: contenitore, TooltipTrigger: contenitore, TooltipContent: nascosto,
    Popover: contenitore, PopoverTrigger: contenitore, PopoverContent: nascosto,
    DropdownMenu: contenitore, DropdownMenuTrigger: { template: '<button v-bind="$attrs"><slot /></button>' }, DropdownMenuContent: nascosto,
    RicercaRapida: { props: ['open'], template: '<div data-ricerca :data-aperta="String(open)" />' },
};

const TUTTI = ['Accesso pannello amministratore', 'Gestisci impostazioni generali', 'Visualizza scadenze in agenda', 'Visualizza condomini'];

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; menu.toggleSidebar.mockClear(); });

function monta(url: string, props: Record<string, unknown> = {}, altro: Record<string, unknown> = {}, opzioni: { ruoli?: string[]; permessi?: string[]; slots?: Record<string, () => unknown>; pagina?: (p: ReturnType<typeof offriPercorsoPagina>) => void } = {}) {
    pagina.url = url;
    pagina.props = { auth: { user: { roles: opzioni.ruoli ?? ['amministratore'], permissions: opzioni.permessi ?? TUTTI } }, ...altro };
    // La barra sta dentro l'impalcatura, che offre il posto dove la testata della pagina dice che cosa la pagina fa scegliere.
    const Impalcatura = defineComponent({
        setup() {
            opzioni.pagina?.(offriPercorsoPagina(!! props.gestionale));

            return () => h(BarraSuperiore, props, opzioni.slots ?? {});
        },
    });
    w = mount(Impalcatura, { global: { stubs } });

    return w;
}

const condominio = { condominio: { id: 7, nome: 'Le Rose' }, esercizio: { id: 3, nome: 'Esercizio 2026', data_inizio: '2026-01-01', data_fine: '2026-12-31T00:00:00Z' } };
/** I pezzi del percorso, uno per voce. */
const percorso = (barra: VueWrapper) => [...barra.get('[data-percorso]').element.children].map((e) => (e.textContent ?? '').trim()).filter(Boolean);

describe('il tasto del menu e la ricerca', () => {
    test('il tasto apre e chiude il menu', async () => {
        const barra = monta('/admin/dashboard');
        await barra.get('[data-menu-tasto]').trigger('click');
        expect(menu.toggleSidebar).toHaveBeenCalledTimes(1);
        expect(barra.get('[data-menu-tasto]').attributes('aria-label')).toBe('Chiudi il menu');
    });

    test('il campo «Cerca o vai a…» apre la ricerca rapida', async () => {
        const barra = monta('/admin/dashboard');
        expect(barra.get('[data-ricerca]').attributes('data-aperta')).toBe('false');
        expect(barra.get('[data-ricerca-campo]').text()).toContain('Cerca o vai a…');
        await barra.get('[data-ricerca-campo]').trigger('click');
        expect(barra.get('[data-ricerca]').attributes('data-aperta')).toBe('true');
    });
});

describe('il percorso', () => {
    test('nello studio: «Studio» porta alla dashboard, poi la sezione in cui ci si trova', () => {
        const barra = monta('/admin/eventi/12');
        expect(barra.get('[data-percorso-studio]').attributes('href')).toBe('/admin/dashboard');
        expect(percorso(barra)).toEqual(['Studio', 'Agenda']);
    });

    test('una pagina dello studio senza una voce nel menu dice lo stesso dove si è', () => {
        expect(percorso(monta('/admin/inbox'))).toEqual(['Studio', 'Casella delle attività']);
        w?.unmount();
        expect(percorso(monta('/settings/profile'))).toEqual(['Studio', 'Il mio profilo']);
        w?.unmount();
        expect(percorso(monta('/pagina-qualunque'))).toEqual(['Studio']);
    });

    test('nello studio, con le briciole della pagina: quelle al posto della sezione', () => {
        const barra = monta('/impostazioni/aspetto', { breadcrumbs: [{ title: 'Impostazioni', href: '/impostazioni' }, { title: 'Aspetto', href: '/impostazioni/aspetto' }] });
        expect(percorso(barra)).toEqual(['Studio', 'Impostazioni', 'Aspetto']);
        expect(barra.get('[data-percorso] a[href="/impostazioni"]').text()).toBe('Impostazioni');
    });

    test('dentro un condominio: il condominio e l\'esercizio, con il periodo', () => {
        const barra = monta('/admin/gestionale/7/immobili', { gestionale: true }, condominio);
        expect(barra.get('[data-percorso-condominio]').text()).toBe('Le Rose');
        expect(barra.get('[data-percorso-esercizio]').text()).toBe('Esercizio 202601/01/2026 – 31/12/2026');
        // Un solo esercizio, o una pagina che non sta sotto un esercizio: è un'indicazione, non un selettore.
        expect(barra.get('[data-percorso-esercizio]').element.tagName).toBe('SPAN');
    });

    test('l\'esercizio si cambia dove sta nell\'indirizzo e la pagina ne offre più d\'uno', () => {
        const esercizi = [condominio.esercizio, { id: 2, nome: 'Esercizio 2025' }];
        const sotto = monta('/admin/gestionale/7/esercizi/3/gestioni', { gestionale: true }, { ...condominio, esercizi });
        expect(sotto.get('[data-percorso-esercizio]').element.tagName).toBe('BUTTON');
        sotto.unmount();

        const altrove = monta('/admin/gestionale/7/immobili', { gestionale: true }, { ...condominio, esercizi });
        expect(altrove.get('[data-percorso-esercizio]').element.tagName).toBe('SPAN');
    });

    test('gli esercizi che la pagina dà alla sua testata valgono anche se la pagina non li porta fra i suoi dati', () => {
        const barra = monta('/admin/gestionale/7/esercizi/3/gestioni', { gestionale: true }, condominio, {
            pagina: (p) => { p.esercizi = [condominio.esercizio, { id: 2, nome: 'Esercizio 2025' }] as never; },
        });
        expect(barra.get('[data-percorso-esercizio]').element.tagName).toBe('BUTTON');
    });

    test('la pagina riempie lo slot del condominio: al posto del selettore della barra c\'è il suo', () => {
        const barra = monta('/admin/gestionale/7/immobili', { gestionale: true }, condominio, { slots: { 'breadcrumb-condominio': () => h('button', { 'data-della-pagina': '' }, 'Le Rose ▾') } });
        expect(barra.get('[data-percorso-condominio] [data-della-pagina]').text()).toBe('Le Rose ▾');
        expect(barra.findAll('[data-percorso-condominio]').length).toBe(1);
    });

    test('fuori dal gestionale una pagina che porta un condominio (la sua scheda) non mette il condominio nel percorso', () => {
        const barra = monta('/condomini/7/edit', {}, condominio);
        expect(barra.find('[data-percorso-condominio]').exists()).toBe(false);
        expect(percorso(barra)).toEqual(['Studio', 'Condomini']);
    });
});

describe('la casella delle attività e l\'aggiornamento', () => {
    test('la casella con il numero delle attività in sospeso, per chi vede le scadenze in agenda', () => {
        const barra = monta('/admin/dashboard', {}, { inbox_count: 6 });
        const casella = barra.get('[data-barra-casella]');
        expect(casella.attributes('href')).toBe('/admin/inbox');
        expect(casella.text()).toBe('6');
        expect(casella.attributes('aria-label')).toBe('Casella delle attività: 6 in sospeso');
    });

    test('nessuna attività: la casella c\'è, senza numero', () => {
        expect(monta('/admin/dashboard', {}, { inbox_count: 0 }).get('[data-barra-casella]').text()).toBe('');
    });

    test('chi non vede le scadenze, e il condòmino, non hanno la casella', () => {
        expect(monta('/admin/dashboard', {}, { inbox_count: 6 }, { ruoli: ['collaboratore'], permessi: ['Accesso pannello amministratore'] }).find('[data-barra-casella]').exists()).toBe(false);
        w?.unmount();
        expect(monta('/user/dashboard', {}, { inbox_count: 6 }, { ruoli: ['utente'], permessi: ['Visualizza scadenze in agenda'] }).find('[data-barra-casella]').exists()).toBe(false);
    });

    test('l\'aggiornamento da installare: a chi può aprire le impostazioni, e solo quando c\'è', () => {
        const aggiornamento = { system_update: { available: true, new_version: '1.13.0' } };
        expect(monta('/admin/dashboard', {}, aggiornamento).get('[data-barra-aggiornamento]').attributes('href')).toBe('/impostazioni');
        w?.unmount();
        expect(monta('/admin/dashboard', {}, { system_update: { available: false } }).find('[data-barra-aggiornamento]').exists()).toBe(false);
        w?.unmount();
        expect(monta('/admin/dashboard', {}, aggiornamento, { ruoli: ['collaboratore'], permessi: ['Accesso pannello amministratore'] }).find('[data-barra-aggiornamento]').exists()).toBe(false);
    });
});
