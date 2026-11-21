// @vitest-environment jsdom

/**
 * Il menu laterale a video (Web Pro Italia, 18/11/2026): aperto mostra l'icona e il nome di ogni voce; chiuso solo le icone,
 * con il nome per chi non le vede (`aria-label`) e al passaggio del mouse.
 *
 * **Il menu è un punto fermo**: lo studio a righe intere e, sotto, il condominio in uso con le sue sezioni rientrate, su ogni
 * pagina, nello stesso ordine. Dentro un condominio le sezioni sono le sue; nelle pagine dello studio quelle dell'ultimo
 * aperto; senza un condominio in uso restano al loro posto, da scegliere. Le voci dei Movimenti stanno nel loro cassettino.
 * La riga di testa del condominio ha due comandi: il nome (si sceglie un altro condominio, o lo si chiude) e la freccia che
 * ripiega le sezioni; ripiegato resta la sola riga di testa, accesa quando la pagina è di quel condominio.
 *
 * Che cosa NON copre: quali voci ci sono e per chi (`vociMenu.test.ts`); che cosa si ricorda del condominio in uso
 * (`condominioInUso.test.ts`); i suggerimenti e gli elenchi a comparsa, che qui sono sostituiti da contenitori semplici.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { h } from 'vue';

const pagina = vi.hoisted(() => ({ url: '/admin/dashboard', props: {} as Record<string, unknown> }));
const giro = vi.hoisted(() => ({ visit: vi.fn(), get: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => pagina,
    Link: { props: ['href'], setup: (p: { href: string }, { slots, attrs }: any) => () => h('a', { href: p.href, ...attrs }, slots.default?.()) },
    router: { post: vi.fn(), visit: giro.visit, flushAll: vi.fn() },
}));
vi.mock('laravel-vue-i18n', async () => ({ trans: (await import('./provaTraduzioni')).tradotto }));
vi.mock('axios', () => ({ default: { get: giro.get } }));

const menu = vi.hoisted(() => ({ toggleSidebar: vi.fn(), telefono: false }));
vi.mock('@/components/ui/sidebar', async () => {
    const { ref } = await import('vue');

    return { useSidebar: () => ({ toggleSidebar: menu.toggleSidebar, isMobile: ref(menu.telefono) }) };
});

const MenuContenuto = (await import('./MenuContenuto.vue')).default;
const { azzeraCondominioInUso } = await import('./condominioInUso');
const { dimenticaElencoCondomini } = await import('./elencoCondomini');

// I suggerimenti e gli elenchi a comparsa: contenitori che mostrano il loro contenuto.
const contenitore = { inheritAttrs: false, render() { return (this as any).$slots.default?.(); } };
const nascosto = { inheritAttrs: false, render: () => null };
const stubs = {
    Tooltip: contenitore, TooltipTrigger: contenitore, TooltipContent: nascosto,
    DropdownMenu: contenitore, DropdownMenuTrigger: contenitore, DropdownMenuContent: nascosto,
    UserMenuContent: nascosto,
    // La scelta del condominio: un clic su chi la apre la apre e la chiude, la finestrella c'è quando è aperta, e l'elenco è
    // un pulsante per condominio.
    Popover: { props: ['open'], emits: ['update:open'], provide() { return { finestrella: this }; }, render() { return (this as any).$slots.default?.(); } },
    PopoverTrigger: { inject: ['finestrella'], template: '<span style="display: contents" @click="finestrella.$emit(\'update:open\', ! finestrella.open)"><slot /></span>' },
    PopoverContent: { inject: ['finestrella'], template: '<div v-if="finestrella.open" data-prova-scelta><slot /></div>' },
    ElencoCondomini: {
        props: { condomini: Array, attuale: Number, chiudibile: Boolean },
        emits: ['scelto', 'chiudi'],
        template: '<button v-for="c in condomini" :key="c.id" type="button" :data-prova-condominio="c.id" :data-attuale="c.id === attuale" @click="$emit(\'scelto\', c)">{{ c.nome }}</button>'
            + '<button v-if="chiudibile && attuale" type="button" data-prova-chiudi @click="$emit(\'chiudi\')">Chiudi il condominio</button>',
    },
};

const TUTTI = ['Accesso pannello amministratore', 'Gestisci impostazioni generali', 'Visualizza scadenze in agenda', 'Visualizza condomini', 'Visualizza anagrafiche',
    'Visualizza comunicazioni', 'Visualizza segnalazioni', 'Visualizza documenti archivio', 'Visualizza allegati segnalazioni', 'Importa dati'];
const STUDIO = ['dashboard', 'agenda', 'condomini', 'persone', 'fornitori', 'bacheca', 'segnalazioni', 'documenti', 'allegati', 'importa'];
const SEZIONI = ['cruscotto', 'struttura', 'unita', 'tabelle', 'esercizi', 'gestioni', 'conti', 'rate', 'movimenti', 'assemblee', 'registrazioni'];

let w: VueWrapper | null = null;
afterEach(() => {
    w?.unmount();
    w = null;
    localStorage.clear();
    azzeraCondominioInUso();
    dimenticaElencoCondomini();
    menu.toggleSidebar.mockClear();
    menu.telefono = false;
    giro.visit.mockClear();
    giro.get.mockReset();
});

function monta(url: string, props: Record<string, unknown> = {}, altro: Record<string, unknown> = {}) {
    pagina.url = url;
    pagina.props = { name: 'Studio Bianchi', auth: { user: { id: 5, name: 'Anna Bianchi', email: 'anna@example.test', roles: ['amministratore'], permissions: TUTTI } }, ...altro };
    giro.get.mockResolvedValue({ data: [{ id: 7, nome: 'Le Rose' }, { id: 9, nome: 'I Tigli' }] });
    w = mount(MenuContenuto, { props, global: { stubs } });

    return w;
}

/** L'ultimo condominio aperto, come lo ha lasciato nel browser una visita precedente. */
function inUso(ricordo: { id: number; nome: string; esercizio: { id: number; nome: string } | null }) {
    localStorage.setItem('km-condominio-in-uso:5', JSON.stringify(ricordo));
    azzeraCondominioInUso();
}

const condominio = { condominio: { id: 7, nome: 'Le Rose' }, esercizio: { id: 3, nome: 'Esercizio 2026' } };
const leRose = { id: 7, nome: 'Le Rose', esercizio: { id: 3, nome: 'Esercizio 2026' } };
const voci = (wrapper: VueWrapper, dove = 'nav') => wrapper.findAll(`${dove} [data-menu-voce]`).map((v) => v.attributes('data-menu-voce'));
/** Tutto ciò che il menu mette in fila: le voci, e fra loro la riga di testa del condominio. */
const fila = (wrapper: VueWrapper) => wrapper.findAll('nav [data-menu-voce], nav [data-menu-condominio]').map((v) => v.attributes('data-menu-voce') ?? '(condominio)');

describe('il menu aperto, nello studio', () => {
    test('il marchio porta alla dashboard e dice il nome dell\'installazione', () => {
        const marchio = monta('/admin/dashboard').get('a[title="Studio Bianchi"]');
        expect(marchio.attributes('href')).toBe('/admin/dashboard');
        expect(marchio.text()).toContain('Studio Bianchi');
    });

    test('il gruppo «Studio» con le voci in ordine: l\'icona nel suo quadratino colorato e il nome', () => {
        const menu = monta('/admin/eventi');
        expect(menu.get('[data-menu-gruppo="studio"]').text()).toBe('Studio');
        expect(voci(menu, '[data-menu-voci="studio"]')).toEqual(STUDIO);
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

    test('in fondo, compatti: l\'utente con nome e indirizzo, e le icone delle impostazioni e della guida (in un\'altra scheda)', () => {
        const menu = monta('/admin/dashboard');
        const fondo = menu.findAll('[data-menu-fondo] [data-menu-voce]');
        expect(fondo.map((v) => [v.attributes('data-menu-voce'), v.attributes('aria-label'), v.text()])).toEqual([['impostazioni', 'Impostazioni', ''], ['guida', 'Documentazione', '']]);
        expect(fondo[1].attributes('target')).toBe('_blank');
        expect(menu.get('[data-menu-utente]').text()).toContain('Anna Bianchi');
        expect(menu.get('[data-menu-utente]').text()).toContain('anna@example.test');
        // Il fondo sta fuori dall'elenco che scorre: resta sempre in vista.
        expect(menu.find('nav [data-menu-fondo]').exists()).toBe(false);
    });
});

describe('il menu è un punto fermo', () => {
    const ovunque = (chiuso: boolean) => {
        const file: string[][] = [];
        file.push(fila(monta('/admin/dashboard', { chiuso })));
        w!.unmount();
        inUso(leRose);
        file.push(fila(monta('/admin/anagrafiche/12', { chiuso })));
        w!.unmount();
        file.push(fila(monta('/admin/gestionale/7/immobili', { gestionale: true, chiuso }, condominio)));
        w!.unmount();
        file.push(fila(monta('/impostazioni/aspetto', { chiuso })));

        return file;
    };

    test('le stesse voci nello stesso ordine su ogni pagina: senza un condominio in uso, nello studio, dentro un condominio', () => {
        for (const voci of ovunque(false)) {
            expect(voci).toEqual([...STUDIO, '(condominio)', ...SEZIONI]);
        }
    });

    test('anche chiuso: le stesse icone nello stesso ordine, con un separatore fra lo studio e il condominio', () => {
        for (const voci of ovunque(true)) {
            expect(voci).toEqual([...STUDIO, '(condominio)', ...SEZIONI]);
        }
        expect(w!.findAll('[data-menu-separatore]').length).toBe(1);
    });

    test('entrando in un condominio lo studio resta a righe intere, con i nomi: niente griglia di icone', () => {
        const menu = monta('/admin/gestionale/7/immobili', { gestionale: true }, condominio);
        expect(menu.find('[data-menu-studio-compatto]').exists()).toBe(false);
        expect(menu.findAll('[data-menu-voci="studio"] [data-menu-voce]').map((v) => v.text())).toEqual(['Dashboard', 'Agenda', 'Condomini', 'Persone', 'Fornitori', 'Bacheca', 'Segnalazioni', 'Documenti', 'Allegati', 'Importa dati']);
        expect(menu.get('[data-menu-gruppo="studio"]').text()).toBe('Studio');
    });

    test('le sezioni del condominio stanno un livello sotto la sua riga di testa: rientrate, con l\'icona più piccola', () => {
        const menu = monta('/admin/gestionale/7/immobili', { gestionale: true }, condominio);
        expect(menu.get('[data-menu-voci="condominio"]').classes()).toContain('km-rientro');
        expect(menu.get('[data-menu-voce="unita"] .km-icona').classes()).toContain('km-icona-voce2');
        expect(menu.get('[data-menu-voce="persone"] .km-icona').classes()).toContain('km-icona-voce');
        // Chiuso non c'è rientro: una colonna sola di icone.
        menu.unmount();
        expect(monta('/admin/gestionale/7/immobili', { gestionale: true, chiuso: true }, condominio).get('[data-menu-voci="condominio"]').classes()).not.toContain('km-rientro');
    });
});

describe('nelle pagine dello studio: sotto c\'è il condominio in uso', () => {
    test('la riga di testa dice il suo nome e l\'esercizio, e le sezioni portano alle sue pagine', () => {
        inUso(leRose);
        const menu = monta('/admin/anagrafiche');
        expect(menu.get('[data-menu-condominio]').text()).toBe('Le RoseEsercizio 2026');
        expect(menu.get('[data-menu-condominio]').attributes('data-senza-condominio')).toBeUndefined();
        expect(menu.get('[data-menu-condominio]').find('svg.lucide-chevrons-up-down-icon').exists()).toBe(true);
        expect(menu.get('[data-menu-voce="unita"]').attributes('href')).toBe('/admin/gestionale/7/immobili');
        expect(menu.get('[data-menu-voce="conti"]').attributes('href')).toBe('/admin/gestionale/7/esercizi/3/piani-conti');
    });

    test('accesa è la voce dello studio, e solo lei: nessuna sezione del condominio', () => {
        inUso(leRose);
        const menu = monta('/admin/anagrafiche/12');
        expect(menu.findAll('[data-attiva="true"], [data-attiva="dentro"]').map((v) => v.attributes('data-menu-voce'))).toEqual(['persone']);
    });

    test('senza un esercizio ricordato la riga di testa dice «Condominio in uso»', () => {
        inUso({ ...leRose, esercizio: null });
        expect(monta('/admin/dashboard').get('[data-menu-condominio]').text()).toBe('Le RoseCondominio in uso');
    });
});

describe('senza un condominio in uso', () => {
    test('la riga di testa chiede di sceglierne uno, e le sezioni restano al loro posto: pulsanti tenui, senza indirizzo', () => {
        const menu = monta('/admin/dashboard');
        const testa = menu.get('[data-menu-condominio]');
        expect(testa.text()).toBe('Scegli un condominioPer aprire le sue sezioni');
        expect(testa.attributes('data-senza-condominio')).toBe('');
        // La frase deve leggersi intera: le due freccette del selettore ci sono solo accanto a un nome.
        expect(testa.find('svg.lucide-chevrons-up-down-icon').exists()).toBe(false);
        expect(voci(menu, '[data-menu-voci="condominio"]')).toEqual(SEZIONI);
        const unita = menu.get('[data-menu-voce="unita"]');
        expect(unita.element.tagName).toBe('BUTTON');
        expect(unita.attributes('data-da-scegliere')).toBe('');
        expect(unita.attributes('href')).toBeUndefined();
        expect(unita.attributes('title')).toBe('Immobili: scegli prima un condominio');
        expect(unita.text()).toBe('Unità');
    });

    test('un clic su una sezione apre la scelta del condominio, e dopo la scelta si va a quella sezione', async () => {
        const menu = monta('/admin/dashboard');
        expect(menu.find('[data-prova-scelta]').exists()).toBe(false);

        await menu.get('[data-menu-voce="unita"]').trigger('click');
        await flushPromises();
        expect(menu.get('[data-prova-scelta]').text()).toContain('Scegli il condominio: si apre «Unità».');
        expect(giro.get).toHaveBeenCalledWith('/fetch-condomini');

        await menu.get('[data-prova-condominio="9"]').trigger('click');
        expect(giro.visit).toHaveBeenCalledWith('/admin/gestionale/9/immobili');
        expect(menu.find('[data-prova-scelta]').exists()).toBe(false);
    });

    test('«Movimenti» non si apre a vuoto: è una sezione da scegliere come le altre', async () => {
        const menu = monta('/admin/dashboard');
        await menu.get('[data-menu-voce="movimenti"]').trigger('click');
        await flushPromises();
        expect(menu.find('[data-menu-voce="fatture"]').exists()).toBe(false);
        expect(menu.get('[data-prova-scelta]').text()).toContain('si apre «Movimenti»');
        await menu.get('[data-prova-condominio="7"]').trigger('click');
        expect(giro.visit).toHaveBeenCalledWith('/admin/gestionale/7/movimenti');
    });

    test('un clic sulla riga di testa: si sceglie il condominio e si apre il suo cruscotto', async () => {
        const menu = monta('/admin/dashboard');
        await menu.get('[data-menu-condominio]').trigger('click');
        await flushPromises();
        // Nessuna sezione in attesa: la finestrella non ne nomina.
        expect(menu.get('[data-prova-scelta]').text()).not.toContain('si apre');
        await menu.get('[data-prova-condominio="7"]').trigger('click');
        expect(giro.visit).toHaveBeenCalledWith('/admin/gestionale/7');
    });
});

describe('cambiare il condominio in uso dalla riga di testa', () => {
    test('nelle pagine dello studio: l\'elenco segna quello in uso, e la scelta apre il cruscotto dell\'altro', async () => {
        inUso(leRose);
        const menu = monta('/admin/anagrafiche');
        await menu.get('[data-menu-condominio]').trigger('click');
        await flushPromises();
        expect(menu.get('[data-prova-condominio="7"]').attributes('data-attuale')).toBe('true');
        await menu.get('[data-prova-condominio="9"]').trigger('click');
        expect(giro.visit).toHaveBeenCalledWith('/admin/gestionale/9');
    });

    test('il condominio in uso non è più nell\'elenco dei condomini: il menu torna a chiedere di sceglierne uno', async () => {
        inUso({ id: 44, nome: 'Eliminato', esercizio: null });
        const menu = monta('/admin/anagrafiche');
        expect(menu.get('[data-menu-condominio]').text()).toContain('Eliminato');
        await menu.get('[data-menu-condominio]').trigger('click');
        await flushPromises();
        expect(menu.get('[data-menu-condominio]').text()).toContain('Scegli un condominio');
        expect(localStorage.getItem('km-condominio-in-uso:5')).toBeNull();
    });

    test('dentro un condominio: l\'elenco è quello della pagina, senza chiamate, e la scelta porta nell\'altro', async () => {
        const menu = monta('/admin/gestionale/7/assemblee/4', { gestionale: true }, { ...condominio, condomini: [{ id: 7, nome: 'Le Rose' }, { id: 9, nome: 'I Tigli' }] });
        await menu.get('[data-menu-condominio]').trigger('click');
        await flushPromises();
        expect(giro.get).not.toHaveBeenCalled();
        await menu.get('[data-prova-condominio="9"]').trigger('click');
        // La scheda di un'assemblea non esiste nell'altro condominio: si apre il suo cruscotto.
        expect(giro.visit).toHaveBeenCalledWith('/admin/gestionale/9', { preserveState: false });
    });
});

describe('il menu aperto, dentro un condominio', () => {
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

describe('ripiegare il gruppo del condominio', () => {
    const ripiegatoNelBrowser = () => localStorage.getItem('km-condominio-ripiegato:5');

    test('la riga di testa ha due comandi distinti: il nome sceglie il condominio, la freccia ripiega le sezioni', async () => {
        inUso(leRose);
        const menu = monta('/admin/dashboard');
        const freccia = menu.get('[data-menu-testa-condominio] [data-menu-ripiega]');
        expect(freccia.attributes('aria-label')).toBe('Nascondi le sezioni');
        expect(freccia.attributes('aria-expanded')).toBe('true');
        expect(menu.get('[data-menu-testa-condominio] [data-menu-condominio]').text()).toBe('Le RoseEsercizio 2026');

        await freccia.trigger('click');
        await flushPromises();
        // Le sezioni spariscono, resta la riga di testa; la scelta del condominio non si è aperta.
        expect(voci(menu, 'nav')).toEqual(STUDIO);
        expect(menu.get('[data-menu-condominio]').text()).toBe('Le RoseEsercizio 2026');
        expect(menu.find('[data-prova-scelta]').exists()).toBe(false);
        expect(freccia.attributes('aria-label')).toBe('Mostra le sezioni');
        expect(freccia.attributes('aria-expanded')).toBe('false');
        expect(ripiegatoNelBrowser()).toBe('true');

        await freccia.trigger('click');
        await flushPromises();
        expect(voci(menu, '[data-menu-voci="condominio"]')).toEqual(SEZIONI);
        expect(ripiegatoNelBrowser()).toBeNull();
    });

    test('si ricorda: alla pagina dopo il gruppo è ancora ripiegato, anche entrando in una pagina del condominio', () => {
        inUso(leRose);
        localStorage.setItem('km-condominio-ripiegato:5', 'true');
        azzeraCondominioInUso();
        expect(voci(monta('/admin/anagrafiche'), 'nav')).toEqual(STUDIO);
        w!.unmount();
        expect(voci(monta('/admin/gestionale/7/immobili', { gestionale: true }, condominio), 'nav')).toEqual(STUDIO);
    });

    test('ripiegato, la riga di testa è accesa quando la pagina è di quel condominio: si capisce dove si è', async () => {
        const menu = monta('/admin/gestionale/7/immobili', { gestionale: true }, condominio);
        // Aperto: accesa è la sezione, non la riga di testa.
        expect(menu.get('[data-menu-testa-condominio]').attributes('data-attiva')).toBeUndefined();
        expect(menu.findAll('[data-attiva="true"]').map((v) => v.attributes('data-menu-voce'))).toEqual(['unita']);

        await menu.get('[data-menu-ripiega]').trigger('click');
        await flushPromises();
        expect(menu.get('[data-menu-testa-condominio]').attributes('data-attiva')).toBe('true');
        expect(menu.findAll('[data-attiva="true"]').length).toBe(1);
    });

    test('ripiegato, nelle pagine dello studio la riga di testa non è accesa: accesa resta la voce dello studio', async () => {
        inUso(leRose);
        const menu = monta('/admin/anagrafiche');
        await menu.get('[data-menu-ripiega]').trigger('click');
        await flushPromises();
        expect(menu.get('[data-menu-testa-condominio]').attributes('data-attiva')).toBeUndefined();
        expect(menu.findAll('[data-attiva="true"]').map((v) => v.attributes('data-menu-voce'))).toEqual(['persone']);
    });

    test('scegliere un condominio dalla riga di testa riapre il gruppo; ripiegarlo e cambiare pagina no', async () => {
        inUso(leRose);
        const menu = monta('/admin/anagrafiche');
        await menu.get('[data-menu-ripiega]').trigger('click');
        await menu.get('[data-menu-condominio]').trigger('click');
        await flushPromises();
        await menu.get('[data-prova-condominio="9"]').trigger('click');
        await flushPromises();
        expect(giro.visit).toHaveBeenCalledWith('/admin/gestionale/9');
        expect(ripiegatoNelBrowser()).toBeNull();
        expect(voci(menu, '[data-menu-voci="condominio"]')).toEqual(SEZIONI);
    });

    test('senza un condominio in uso e con il gruppo ripiegato resta la sola riga «Scegli un condominio»', async () => {
        const menu = monta('/admin/dashboard');
        await menu.get('[data-menu-ripiega]').trigger('click');
        await flushPromises();
        expect(fila(menu)).toEqual([...STUDIO, '(condominio)']);
        expect(menu.get('[data-menu-condominio]').text()).toBe('Scegli un condominioPer aprire le sue sezioni');
    });

    test('nel menu chiuso la freccia sta sul separatore: ripiegato resta la sola icona del condominio, che dice il suo nome', async () => {
        const menu = monta('/admin/gestionale/7/immobili', { gestionale: true, chiuso: true }, condominio);
        const freccia = menu.get('[data-menu-separatore][data-menu-ripiega]');
        expect(freccia.attributes('aria-label')).toBe('Nascondi le sezioni');
        expect(menu.find('[data-menu-testa-condominio] [data-menu-ripiega]').exists()).toBe(false);

        await freccia.trigger('click');
        await flushPromises();
        expect(fila(menu)).toEqual([...STUDIO, '(condominio)']);
        const icona = menu.get('[data-menu-condominio]');
        expect(icona.attributes('aria-label')).toBe('Le Rose: scegli il condominio');
        // Si è dentro quel condominio: l'icona è accesa, con la tacca sul bordo.
        expect(icona.attributes('data-attiva')).toBe('true');
        expect(icona.find('.km-tacca').exists()).toBe(true);
        expect(menu.findAll('[data-attiva="true"]').length).toBe(1);
        expect(freccia.attributes('aria-label')).toBe('Mostra le sezioni');

        // Riaprendo tornano le icone delle sezioni, e accesa è di nuovo la sezione.
        await freccia.trigger('click');
        await flushPromises();
        expect(fila(menu)).toEqual([...STUDIO, '(condominio)', ...SEZIONI]);
        expect(menu.findAll('[data-attiva="true"]').map((v) => v.attributes('data-menu-voce'))).toEqual(['unita']);
    });
});

describe('«Chiudi il condominio»: nessun condominio in uso', () => {
    test('dalle pagine dello studio: il gruppo torna a «Scegli un condominio», con le sezioni da scegliere, e si resta dove si è', async () => {
        inUso(leRose);
        const menu = monta('/admin/anagrafiche');
        await menu.get('[data-menu-condominio]').trigger('click');
        await flushPromises();
        await menu.get('[data-prova-chiudi]').trigger('click');
        await flushPromises();

        expect(menu.get('[data-menu-condominio]').text()).toBe('Scegli un condominioPer aprire le sue sezioni');
        expect(menu.get('[data-menu-voce="unita"]').attributes('data-da-scegliere')).toBe('');
        expect(localStorage.getItem('km-condominio-in-uso:5')).toBeNull();
        expect(menu.find('[data-prova-scelta]').exists()).toBe(false);
        expect(giro.visit).not.toHaveBeenCalled();
    });

    test('da dentro una pagina di quel condominio: si torna alla dashboard dello studio', async () => {
        inUso(leRose);
        const menu = monta('/admin/gestionale/7/immobili', { gestionale: true }, { ...condominio, condomini: [{ id: 7, nome: 'Le Rose' }] });
        await menu.get('[data-menu-condominio]').trigger('click');
        await flushPromises();
        await menu.get('[data-prova-chiudi]').trigger('click');
        await flushPromises();
        expect(giro.visit).toHaveBeenCalledWith('/admin/dashboard');
        expect(localStorage.getItem('km-condominio-in-uso:5')).toBeNull();
    });

    test('senza un condominio in uso non c\'è niente da chiudere', async () => {
        const menu = monta('/admin/dashboard');
        await menu.get('[data-menu-condominio]').trigger('click');
        await flushPromises();
        expect(menu.find('[data-prova-chiudi]').exists()).toBe(false);
    });

    test('con il gruppo ripiegato resta ripiegato: la sola riga «Scegli un condominio»', async () => {
        inUso(leRose);
        const menu = monta('/admin/anagrafiche');
        await menu.get('[data-menu-ripiega]').trigger('click');
        await menu.get('[data-menu-condominio]').trigger('click');
        await flushPromises();
        await menu.get('[data-prova-chiudi]').trigger('click');
        await flushPromises();
        expect(fila(menu)).toEqual([...STUDIO, '(condominio)']);
        expect(menu.get('[data-menu-condominio]').text()).toContain('Scegli un condominio');
    });
});

describe('con la barra in alto: la colonna ha le sole sezioni del condominio', () => {
    test('dentro un condominio: né il marchio, né lo studio, né l\'utente — stanno nella barra; la riga di testa e le sezioni', () => {
        const menu = monta('/admin/gestionale/7/immobili', { gestionale: true, barra: true }, condominio);
        expect(fila(menu)).toEqual(['(condominio)', ...SEZIONI]);
        expect(menu.find('[data-menu-zona="studio"]').exists()).toBe(false);
        expect(menu.find('[data-menu-fondo]').exists()).toBe(false);
        expect(menu.find('a[title="Studio Bianchi"]').exists()).toBe(false);
        expect(menu.get('[data-menu-condominio]').text()).toBe('Le RoseEsercizio 2026');
        expect(menu.get('[data-menu-voce="unita"]').attributes('data-attiva')).toBe('true');
    });

    test('le sezioni sono tutto il menu: non si ripiegano, e la freccia della riga di testa richiude la colonna a sole icone', async () => {
        localStorage.setItem('km-condominio-ripiegato:5', 'true');
        azzeraCondominioInUso();
        const colonna = monta('/admin/gestionale/7/immobili', { gestionale: true, barra: true }, condominio);
        expect(voci(colonna, '[data-menu-voci="condominio"]')).toEqual(SEZIONI);
        expect(colonna.find('[data-menu-ripiega]').exists()).toBe(false);

        await colonna.get('[data-menu-testa-condominio] [data-menu-richiudi]').trigger('click');
        expect(menu.toggleSidebar).toHaveBeenCalledTimes(1);
    });

    test('a sole icone: la freccia che riapre, l\'icona del condominio e quelle delle sezioni; nessun separatore', () => {
        const colonna = monta('/admin/gestionale/7/immobili', { gestionale: true, barra: true, chiuso: true }, condominio);
        expect(colonna.find('[data-menu-riapri]').exists()).toBe(true);
        expect(fila(colonna)).toEqual(['(condominio)', ...SEZIONI]);
        expect(colonna.find('[data-menu-separatore]').exists()).toBe(false);
        expect(colonna.get('nav').text()).toBe('');
    });

    test('nel cassetto del telefono ci sono anche le sezioni dello studio, nell\'ordine della barra («Condomini» ultima); quelle del condominio solo dentro un condominio', () => {
        menu.telefono = true;
        // L'ordine della barra: le anagrafiche, l'agenda, le comunicazioni, i documenti, l'importazione, e in fondo i condomini.
        const STUDIO = ['dashboard', 'persone', 'fornitori', 'agenda', 'bacheca', 'segnalazioni', 'documenti', 'allegati', 'importa', 'condomini'];
        const dentro = monta('/admin/gestionale/7/immobili', { gestionale: true, barra: true }, condominio);
        expect(fila(dentro)).toEqual([...STUDIO, '(condominio)', ...SEZIONI]);
        expect(dentro.find('[data-menu-fondo]').exists()).toBe(true);
        dentro.unmount();

        inUso(leRose);
        const fuori = monta('/admin/anagrafiche', { barra: true });
        expect(fila(fuori)).toEqual(STUDIO);
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

    test('la riga di testa del condominio è un\'icona che dice il suo nome; «Movimenti» un\'icona che apre l\'elenco accanto', () => {
        const menu = monta('/admin/gestionale/7/fatture', { gestionale: true, chiuso: true }, condominio);
        expect(menu.get('nav').text()).toBe('');
        expect(menu.get('[data-menu-condominio]').attributes('aria-label')).toBe('Le Rose: scegli il condominio');
        expect(menu.get('[data-menu-voce="movimenti"]').attributes('data-attiva')).toBe('true');
        expect(menu.get('[data-menu-voce="condomini"]').attributes('aria-label')).toBe('Elenco condomìni');
        // Lo studio non si riduce a un'icona sola: le sue voci ci sono tutte.
        expect(menu.find('[data-menu-voce="studio"]').exists()).toBe(false);
        expect(voci(menu, '[data-menu-voci="studio"]')).toEqual(STUDIO);
    });

    test('in fondo, in colonna: le impostazioni, la guida e l\'utente con le sole iniziali', () => {
        const menu = monta('/admin/dashboard', { chiuso: true });
        expect(voci(menu, '[data-menu-fondo]')).toEqual(['impostazioni', 'guida']);
        expect(menu.get('[data-menu-fondo]').text()).toBe('AB');
    });
});
