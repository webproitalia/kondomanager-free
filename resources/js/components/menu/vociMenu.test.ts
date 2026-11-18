// @vitest-environment jsdom

/**
 * Le voci del menu laterale (Web Pro Italia, 18/11/2026): sono quelle delle due testate di sempre e del menu dei Movimenti, con
 * gli stessi indirizzi, gli stessi permessi e le stesse condizioni; cambiano l'ordine, i nomi brevi e le icone colorate.
 *
 * Che cosa copre: l'ordine e gli indirizzi delle voci dello studio e del condominio; chi vede che cosa (l'amministratore, il
 * collaboratore senza un permesso, il condòmino); l'esercizio da cui dipendono alcune voci; la voce accesa, che resta accesa
 * in tutte le pagine della sezione; i contatori. Che le voci dentro «Movimenti» sono le stesse del menu dei movimenti
 * (`MovimentiLayout.vue`): una voce aggiunta là e non qui fa fallire questo test. E che il menu è un punto fermo: le sezioni
 * del condominio ci sono su ogni pagina, le stesse e nello stesso ordine — dentro un condominio le sue, nelle pagine dello
 * studio quelle del condominio in uso (l'ultimo aperto), e senza un condominio in uso restano al loro posto, da scegliere.
 *
 * Che cosa NON copre: come le voci si disegnano (`MenuContenuto.test.ts`) e le due testate, che restano com'erano.
 *
 * Le traduzioni sono quelle italiane della testata (`provaTraduzioni.ts`).
 */
import { beforeEach, describe, expect, test, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';

const pagina = vi.hoisted(() => ({ url: '/admin/dashboard', props: {} as Record<string, unknown> }));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    usePage: () => pagina,
}));
vi.mock('laravel-vue-i18n', async () => ({ trans: (await import('./provaTraduzioni')).tradotto }));

const { useVociMenu, COLORI_SEZIONI, percorsoDi } = await import('./vociMenu');
const { azzeraCondominioInUso } = await import('./condominioInUso');
const MovimentiLayout = (await import('@/layouts/gestionale/MovimentiLayout.vue')).default;

const TUTTI = [
    'Accesso pannello amministratore', 'Gestisci impostazioni generali', 'Visualizza scadenze in agenda', 'Visualizza condomini', 'Visualizza anagrafiche',
    'Visualizza comunicazioni', 'Visualizza segnalazioni', 'Visualizza documenti archivio', 'Visualizza allegati segnalazioni', 'Importa dati',
];

function entra(ruoli: string[], permessi: string[], altro: Record<string, unknown> = {}, url = '/admin/dashboard') {
    pagina.url = url;
    pagina.props = { auth: { user: { id: 5, roles: ruoli, permissions: permessi } }, ...altro };
}

/** Il condominio in uso, come lo ha lasciato nel browser l'ultima visita a un condominio. */
function inUso(ricordo: { id: number; nome: string; esercizio: { id: number; nome: string } | null } | null) {
    localStorage.clear();
    if (ricordo) {
        localStorage.setItem('km-condominio-in-uso:5', JSON.stringify(ricordo));
    }
    azzeraCondominioInUso();
}

const condominio = { id: 7, nome: 'Le Rose' };
const nelCondominio = (url: string, altro: Record<string, unknown> = {}) => entra(['amministratore'], TUTTI, { condominio, esercizio: { id: 3, nome: 'Esercizio 2026' }, ...altro }, url);
const chiavi = (voci: { chiave: string }[]) => voci.map((v) => v.chiave);

const SEZIONI = ['cruscotto', 'struttura', 'unita', 'tabelle', 'esercizi', 'gestioni', 'conti', 'rate', 'movimenti', 'assemblee', 'registrazioni'];

beforeEach(() => {
    inUso(null);
    entra(['amministratore'], TUTTI);
});

describe('lo studio: le voci della testata, nell\'ordine di chi viene dai gestionali da tavolo', () => {
    test('l\'amministratore le vede tutte, con gli indirizzi di sempre', () => {
        const { studio, fondo } = useVociMenu();
        expect(studio.value.map((v) => [v.chiave, v.nome, v.href])).toEqual([
            ['dashboard', 'Dashboard', '/admin/dashboard'],
            ['agenda', 'Agenda', '/admin/eventi'],
            ['condomini', 'Condomini', '/condomini'],
            ['persone', 'Persone', '/admin/anagrafiche'],
            ['fornitori', 'Fornitori', '/admin/fornitori'],
            ['bacheca', 'Bacheca', '/admin/comunicazioni'],
            ['segnalazioni', 'Segnalazioni', '/admin/segnalazioni'],
            ['documenti', 'Documenti', '/admin/documenti'],
            ['allegati', 'Allegati', '/admin/allegati'],
            ['importa', 'Importa dati', '/importa-dati'],
        ]);
        expect(fondo.value.map((v) => [v.chiave, v.href, !! v.esterna])).toEqual([
            ['impostazioni', '/impostazioni', false],
            ['guida', 'https://kondomanager.com/docs/index.html', true],
        ]);
    });

    test('il nome breve è del menu; il nome intero resta quello della pagina', () => {
        const { studio } = useVociMenu();
        const persone = studio.value.find((v) => v.chiave === 'persone')!;
        expect(persone.nome).toBe('Persone');
        expect(persone.titolo).toBe('Rubrica: anagrafiche');
    });

    test('ogni voce ha la sua icona e il suo colore, sempre lo stesso', () => {
        const { studio } = useVociMenu();
        for (const voce of studio.value) {
            expect(voce.icona, voce.chiave).toBeTruthy();
            expect(voce.colore, voce.chiave).toBe(COLORI_SEZIONI[voce.chiave]);
        }
    });

    test('il collaboratore senza «Importa dati» e senza le impostazioni non vede né l\'una né le altre', () => {
        entra(['collaboratore'], TUTTI.filter((p) => ! ['Importa dati', 'Gestisci impostazioni generali'].includes(p)));
        const { studio, fondo } = useVociMenu();
        expect(chiavi(studio.value)).not.toContain('importa');
        expect(chiavi(fondo.value)).toEqual(['guida']);
    });

    test('gli allegati solo a chi ha il pannello e li può vedere, con il numero di quelli in attesa', () => {
        entra(['amministratore'], TUTTI, { allegati_in_attesa: 4 });
        expect(useVociMenu().studio.value.find((v) => v.chiave === 'allegati')?.contatore).toBe(4);

        entra(['collaboratore'], TUTTI.filter((p) => p !== 'Visualizza allegati segnalazioni'));
        expect(chiavi(useVociMenu().studio.value)).not.toContain('allegati');
    });

    test('il condòmino vede il suo portale: gli indirizzi sono quelli del portale e i documenti si sfogliano per categoria', () => {
        entra(['utente'], ['Visualizza scadenze in agenda', 'Visualizza comunicazioni', 'Visualizza segnalazioni', 'Visualizza documenti archivio'], {}, '/user/dashboard');
        const { studio, fondo, nomeStudio, nelPannello } = useVociMenu();
        expect(studio.value.map((v) => [v.chiave, v.href])).toEqual([
            ['dashboard', '/user/dashboard'],
            ['agenda', '/user/eventi'],
            ['bacheca', '/user/comunicazioni'],
            ['segnalazioni', '/user/segnalazioni'],
            ['documenti', '/user/categorie-documenti'],
        ]);
        expect(chiavi(fondo.value)).toEqual(['guida']);
        expect(nelPannello.value).toBe(false);
        expect(nomeStudio.value).toBe('Il mio condominio');
    });

    test('c\'è un aggiornamento da installare: il puntino sulle impostazioni', () => {
        entra(['amministratore'], TUTTI, { system_update: { available: true } });
        expect(useVociMenu().fondo.value.find((v) => v.chiave === 'impostazioni')?.avviso).toBe(true);
        entra(['amministratore'], TUTTI, { system_update: { available: false } });
        expect(useVociMenu().fondo.value.find((v) => v.chiave === 'impostazioni')?.avviso).toBe(false);
    });
});

describe('dentro un condominio: le voci della testata del gestionale', () => {
    test('le voci, nell\'ordine, con il nome del condominio e dell\'esercizio', () => {
        nelCondominio('/admin/gestionale/7');
        const gruppo = useVociMenu({ gestionale: true }).gruppoCondominio.value!;
        expect([gruppo.id, gruppo.nome, gruppo.esercizio]).toEqual([7, 'Le Rose', 'Esercizio 2026']);
        expect(gruppo.voci.map((v) => [v.chiave, v.nome, v.href])).toEqual([
            ['cruscotto', 'Cruscotto', '/admin/gestionale/7'],
            ['struttura', 'Condominio', '/admin/gestionale/7/struttura'],
            ['unita', 'Unità', '/admin/gestionale/7/immobili'],
            ['tabelle', 'Tabelle', '/admin/gestionale/7/tabelle'],
            ['esercizi', 'Esercizi', '/admin/gestionale/7/esercizi'],
            ['gestioni', 'Gestioni', '/admin/gestionale/7/esercizi/3/gestioni'],
            ['conti', 'Conti', '/admin/gestionale/7/esercizi/3/piani-conti'],
            ['rate', 'Rate', '/admin/gestionale/7/esercizi/3/piani-rate'],
            ['movimenti', 'Movimenti', '/admin/gestionale/7/movimenti'],
            ['assemblee', 'Assemblee', '/admin/gestionale/7/assemblee'],
            ['registrazioni', 'Registrazioni', '/admin/gestionale/7/registrazioni'],
        ]);
        // I nomi interi delle pagine, per il passaggio del mouse e la ricerca.
        expect(gruppo.voci.filter((v) => ['unita', 'conti', 'rate'].includes(v.chiave)).map((v) => v.titolo)).toEqual(['Immobili', 'Piani conti', 'Piani rate']);
    });

    test('dentro «Movimenti» ci sono le voci del menu dei movimenti: le stesse, nello stesso ordine, con gli stessi indirizzi', () => {
        nelCondominio('/admin/gestionale/7/fatture');
        const delLayout = shallowMount(MovimentiLayout).findComponent({ name: 'NavSezione' }).props('items') as { title: string; href: string }[];
        const figlie = useVociMenu({ gestionale: true }).gruppoCondominio.value!.voci.find((v) => v.chiave === 'movimenti')!.figlie!;
        expect(figlie.map((v) => [v.titolo, v.href])).toEqual(delLayout.map((v) => [v.title, v.href]));
        // Hanno il colore di «Movimenti»: sono la stessa sezione.
        expect(figlie.every((v) => v.colore === COLORI_SEZIONI.movimenti)).toBe(true);
    });

    test('le assemblee e le registrazioni: all\'amministratore o a chi ha il permesso, come le rotte', () => {
        entra(['collaboratore'], TUTTI, { condominio }, '/admin/gestionale/7');
        expect(chiavi(useVociMenu({ gestionale: true }).gruppoCondominio.value!.voci)).not.toEqual(expect.arrayContaining(['assemblee', 'registrazioni']));

        entra(['collaboratore'], [...TUTTI, 'Visualizza assemblee'], { condominio }, '/admin/gestionale/7');
        expect(chiavi(useVociMenu({ gestionale: true }).gruppoCondominio.value!.voci)).toEqual(expect.arrayContaining(['assemblee', 'registrazioni']));
    });
});

describe('il menu è un punto fermo: le sezioni del condominio ci sono su ogni pagina', () => {
    const gruppoIn = (url: string, altro: Record<string, unknown> = {}, gestionale = false) => {
        entra(['amministratore'], TUTTI, altro, url);

        return useVociMenu({ gestionale }).gruppoCondominio.value!;
    };

    test('le stesse voci, nello stesso ordine: dentro un condominio, nelle pagine dello studio e senza un condominio in uso', () => {
        const senza = gruppoIn('/admin/dashboard');
        inUso({ id: 7, nome: 'Le Rose', esercizio: { id: 3, nome: 'Esercizio 2026' } });
        const nelloStudio = gruppoIn('/admin/anagrafiche');
        const dentro = gruppoIn('/admin/gestionale/7/immobili', { condominio, esercizio: { id: 3, nome: 'Esercizio 2026' } }, true);

        for (const gruppo of [senza, nelloStudio, dentro]) {
            expect(chiavi(gruppo.voci)).toEqual(SEZIONI);
            expect(gruppo.voci.map((v) => v.nome)).toEqual(dentro.voci.map((v) => v.nome));
            expect(chiavi(gruppo.voci.find((v) => v.chiave === 'movimenti')!.figlie!)).toEqual(chiavi(dentro.voci.find((v) => v.chiave === 'movimenti')!.figlie!));
        }
        // Anche lo studio: le sue voci non cambiano entrando in un condominio.
        entra(['amministratore'], TUTTI, {}, '/admin/dashboard');
        const fuori = chiavi(useVociMenu().studio.value);
        nelCondominio('/admin/gestionale/7/immobili');
        expect(chiavi(useVociMenu({ gestionale: true }).studio.value)).toEqual(fuori);
    });

    test('nelle pagine dello studio le sezioni sono quelle del condominio in uso, con il suo esercizio', () => {
        inUso({ id: 7, nome: 'Le Rose', esercizio: { id: 3, nome: 'Esercizio 2026' } });
        const gruppo = gruppoIn('/admin/anagrafiche');
        expect([gruppo.id, gruppo.nome, gruppo.esercizio, gruppo.dellaPagina]).toEqual([7, 'Le Rose', 'Esercizio 2026', false]);
        expect(gruppo.voci.map((v) => v.href)).toEqual([
            '/admin/gestionale/7',
            '/admin/gestionale/7/struttura',
            '/admin/gestionale/7/immobili',
            '/admin/gestionale/7/tabelle',
            '/admin/gestionale/7/esercizi',
            '/admin/gestionale/7/esercizi/3/gestioni',
            '/admin/gestionale/7/esercizi/3/piani-conti',
            '/admin/gestionale/7/esercizi/3/piani-rate',
            '/admin/gestionale/7/movimenti',
            '/admin/gestionale/7/assemblee',
            '/admin/gestionale/7/registrazioni',
        ]);
        expect(gruppo.voci.find((v) => v.chiave === 'movimenti')!.figlie!.find((v) => v.chiave === 'rendiconto')!.href).toBe('/admin/gestionale/7/esercizi/3/rendiconto');
        expect(gruppo.voci.some((v) => v.daScegliere)).toBe(false);
    });

    test('nelle pagine dello studio nessuna sezione del condominio è accesa: accesa è la voce dello studio', () => {
        inUso({ id: 7, nome: 'Le Rose', esercizio: null });
        entra(['amministratore'], TUTTI, {}, '/admin/anagrafiche/12');
        const { gruppoCondominio, studio, corrente } = useVociMenu();
        expect(gruppoCondominio.value!.voci.flatMap((v) => [v, ...(v.figlie ?? [])]).filter((v) => v.attiva)).toEqual([]);
        expect(studio.value.filter((v) => v.attiva).map((v) => v.chiave)).toEqual(['persone']);
        expect(corrente.value?.chiave).toBe('persone');
    });

    test('il condominio in uso senza un esercizio ricordato: Gestioni, Conti e Rate portano all\'elenco degli esercizi', () => {
        inUso({ id: 7, nome: 'Le Rose', esercizio: null });
        const gruppo = gruppoIn('/admin/dashboard');
        expect(gruppo.esercizio).toBeNull();
        expect(gruppo.voci.filter((v) => ['gestioni', 'conti', 'rate'].includes(v.chiave)).map((v) => v.href)).toEqual(Array(3).fill('/admin/gestionale/7/esercizi'));
        expect(chiavi(gruppo.voci.find((v) => v.chiave === 'movimenti')!.figlie!.filter((v) => v.spenta))).toEqual(['giornale', 'registro', 'rendiconto', 'fascicolo']);
    });

    test('senza un condominio in uso le sezioni restano al loro posto, da scegliere: nessun indirizzo, e la sezione a cui andare dopo', () => {
        const gruppo = gruppoIn('/admin/dashboard');
        expect([gruppo.id, gruppo.nome, gruppo.esercizio, gruppo.dellaPagina]).toEqual([null, null, null, false]);
        expect(gruppo.voci.every((v) => v.daScegliere && v.href === '#' && ! v.attiva && ! v.spenta)).toBe(true);
        expect(gruppo.voci.map((v) => [v.chiave, v.sezione])).toEqual([
            ['cruscotto', ''], ['struttura', '/struttura'], ['unita', '/immobili'], ['tabelle', '/tabelle'], ['esercizi', '/esercizi'],
            // Le voci che stanno sotto un esercizio: prima si sceglie l'esercizio.
            ['gestioni', '/esercizi'], ['conti', '/esercizi'], ['rate', '/esercizi'],
            ['movimenti', '/movimenti'], ['assemblee', '/assemblee'], ['registrazioni', '/registrazioni'],
        ]);
        const figlie = gruppo.voci.find((v) => v.chiave === 'movimenti')!.figlie!;
        expect(figlie.every((v) => v.daScegliere && ! v.spenta)).toBe(true);
        expect(figlie.find((v) => v.chiave === 'fatture')!.sezione).toBe('/fatture');
        expect(JSON.stringify(gruppo)).not.toContain('undefined');
    });

    test('il condominio della scheda (una pagina dello studio che porta un condominio) non è quello in uso', () => {
        const gruppo = gruppoIn('/condomini/7/edit', { condominio });
        expect(gruppo.id).toBeNull();
        expect(useVociMenu().studio.value.filter((v) => v.attiva).map((v) => v.chiave)).toEqual(['condomini']);
    });

    test('dentro un condominio vale il suo, anche se quello ricordato è un altro; l\'esercizio ricordato dell\'altro non lo segue', () => {
        inUso({ id: 9, nome: 'I Tigli', esercizio: { id: 12, nome: 'Esercizio dei Tigli' } });
        const gruppo = gruppoIn('/admin/gestionale/7/assemblee', { condominio }, true);
        expect([gruppo.id, gruppo.nome, gruppo.esercizio, gruppo.dellaPagina]).toEqual([7, 'Le Rose', null, true]);
        expect(gruppo.voci.find((v) => v.chiave === 'conti')!.href).toBe('/admin/gestionale/7/esercizi');
    });

    test('dentro un condominio, su una pagina senza esercizio: vale quello in cui si lavorava lì l\'ultima volta', () => {
        inUso({ id: 7, nome: 'Le Rose', esercizio: { id: 3, nome: 'Esercizio 2026' } });
        const gruppo = gruppoIn('/admin/gestionale/7/assemblee', { condominio }, true);
        expect(gruppo.esercizio).toBe('Esercizio 2026');
        expect(gruppo.voci.find((v) => v.chiave === 'conti')!.href).toBe('/admin/gestionale/7/esercizi/3/piani-conti');
    });

    test('il condòmino non entra nel gestionale: per lui il gruppo del condominio non c\'è', () => {
        inUso({ id: 7, nome: 'Le Rose', esercizio: null });
        entra(['utente'], ['Visualizza comunicazioni'], {}, '/user/dashboard');
        expect(useVociMenu().gruppoCondominio.value).toBeNull();
    });
});

describe('le voci che stanno sotto un esercizio', () => {
    test('la pagina non porta l\'esercizio: si usa quello aperto del condominio, dall\'elenco dei condomini', () => {
        entra(['amministratore'], TUTTI, { condominio, condomini: [{ id: 5, nome: 'Altro', esercizio_aperto: { id: 9 } }, { id: 7, nome: 'Le Rose', esercizio_aperto: { id: 4 } }] }, '/admin/gestionale/7/assemblee');
        const { gruppoCondominio, esercizioId } = useVociMenu({ gestionale: true });
        expect(esercizioId.value).toBe(4);
        expect(gruppoCondominio.value!.voci.find((v) => v.chiave === 'gestioni')!.href).toBe('/admin/gestionale/7/esercizi/4/gestioni');
    });

    test('senza un esercizio: Gestioni, Conti e Rate portano all\'elenco degli esercizi, e mai a un indirizzo con «undefined»', () => {
        entra(['amministratore'], TUTTI, { condominio }, '/admin/gestionale/7/assemblee');
        const voci = useVociMenu({ gestionale: true }).gruppoCondominio.value!.voci;
        expect(voci.filter((v) => ['gestioni', 'conti', 'rate'].includes(v.chiave)).map((v) => v.href)).toEqual(Array(3).fill('/admin/gestionale/7/esercizi'));
        expect(JSON.stringify(voci)).not.toContain('undefined');

        // …e nell'elenco degli esercizi accesa è «Esercizi»: le tre non si accendono al suo posto.
        entra(['amministratore'], TUTTI, { condominio }, '/admin/gestionale/7/esercizi');
        expect(useVociMenu({ gestionale: true }).gruppoCondominio.value!.voci.filter((v) => v.attiva).map((v) => v.chiave)).toEqual(['esercizi']);
    });

    test('senza un esercizio le quattro voci dei Movimenti che ne hanno bisogno sono spente, con la nota del loro menu', () => {
        entra(['amministratore'], TUTTI, { condominio }, '/admin/gestionale/7/fatture');
        const figlie = useVociMenu({ gestionale: true }).gruppoCondominio.value!.voci.find((v) => v.chiave === 'movimenti')!.figlie!;
        const spente = figlie.filter((v) => v.spenta);
        expect(chiavi(spente)).toEqual(['giornale', 'registro', 'rendiconto', 'fascicolo']);
        expect(spente.every((v) => v.href === '#' && v.nota === 'Nessun esercizio aperto')).toBe(true);
    });
});

describe('la voce accesa', () => {
    const accese = (url: string, altro: Record<string, unknown> = {}) => {
        nelCondominio(url, altro);
        const { gruppoCondominio, studio, fondo } = useVociMenu({ gestionale: true });

        return [...gruppoCondominio.value!.voci, ...studio.value, ...fondo.value].flatMap((v) => [v, ...(v.figlie ?? [])]).filter((v) => v.attiva).map((v) => v.chiave);
    };

    test('resta accesa in tutte le pagine della sezione, non solo sull\'elenco', () => {
        expect(accese('/admin/gestionale/7/immobili')).toEqual(['unita']);
        expect(accese('/admin/gestionale/7/immobili/12/anagrafiche/create')).toEqual(['unita']);
        expect(accese('/admin/gestionale/7/assemblee/4')).toEqual(['assemblee']);
    });

    test('il cruscotto solo sul suo indirizzo: tutto il resto del condominio sta sotto di lui', () => {
        expect(accese('/admin/gestionale/7')).toEqual(['cruscotto']);
        expect(accese('/admin/gestionale/7/tabelle')).toEqual(['tabelle']);
    });

    test('fra più voci che corrispondono vince la più precisa: in una gestione è accesa «Gestioni», non «Esercizi»', () => {
        expect(accese('/admin/gestionale/7/esercizi')).toEqual(['esercizi']);
        expect(accese('/admin/gestionale/7/esercizi/3/edit')).toEqual(['esercizi']);
        expect(accese('/admin/gestionale/7/esercizi/3/gestioni/5/edit')).toEqual(['gestioni']);
        expect(accese('/admin/gestionale/7/esercizi/3/piani-rate/8')).toEqual(['rate']);
        expect(accese('/admin/gestionale/7/esercizi/3/piani-conti/2')).toEqual(['conti']);
    });

    test('le pagine della struttura che stanno su un indirizzo loro accendono «Condominio»', () => {
        for (const pagina of ['struttura', 'palazzine', 'scale', 'casse', 'saldi', 'contributi']) {
            expect(accese(`/admin/gestionale/7/${pagina}`), pagina).toEqual(['struttura']);
        }
    });

    test('in una pagina dei movimenti sono accese «Movimenti» e la sua voce', () => {
        expect(accese('/admin/gestionale/7/fatture/9/edit')).toEqual(['movimenti', 'fatture']);
        expect(accese('/admin/gestionale/7/movimenti-rate/create')).toEqual(['movimenti', 'incassi']);
        expect(accese('/admin/gestionale/7/esercizi/3/rendiconto')).toEqual(['movimenti', 'rendiconto']);
        // La situazione patrimoniale è una scheda del rendiconto, sul suo indirizzo di sempre.
        expect(accese('/admin/gestionale/7/esercizi/3/stato-patrimoniale')).toEqual(['movimenti', 'rendiconto']);
    });

    test('il confronto è per segmento e senza la domanda: «/fatture» non si accende su «/fatture-simili»', () => {
        expect(accese('/admin/gestionale/7/fatture?stato=aperte')).toEqual(['movimenti', 'fatture']);
        expect(accese('/admin/gestionale/7/fatture-simili')).toEqual([]);
        expect(percorsoDi('/admin/eventi/?mese=3#oggi')).toBe('/admin/eventi');
    });

    test('nello studio: la sezione, le sue schede, le impostazioni con le loro pagine', () => {
        const nelloStudio = (url: string) => {
            entra(['amministratore'], TUTTI, {}, url);
            const { studio, fondo } = useVociMenu();

            return [...studio.value, ...fondo.value].filter((v) => v.attiva).map((v) => v.chiave);
        };
        expect(nelloStudio('/admin/dashboard')).toEqual(['dashboard']);
        expect(nelloStudio('/condomini/7/edit')).toEqual(['condomini']);
        expect(nelloStudio('/admin/fornitori/5/documenti')).toEqual(['fornitori']);
        expect(nelloStudio('/impostazioni/aspetto')).toEqual(['impostazioni']);
        // Gli utenti e i ruoli si aprono dalle impostazioni, su indirizzi loro.
        expect(nelloStudio('/utenti')).toEqual(['impostazioni']);
        expect(nelloStudio('/ruoli/3/edit')).toEqual(['impostazioni']);
        expect(nelloStudio('/admin/inbox')).toEqual([]);
    });

    test('la voce in cui ci si trova, fino alla figlia: dà l\'icona e il colore alla testata della pagina', () => {
        nelCondominio('/admin/gestionale/7/esercizi/3/rendiconto');
        const { corrente } = useVociMenu({ gestionale: true });
        expect([corrente.value?.chiave, corrente.value?.colore]).toEqual(['rendiconto', COLORI_SEZIONI.movimenti]);

        nelCondominio('/admin/gestionale/7/immobili/2');
        expect(useVociMenu({ gestionale: true }).corrente.value?.chiave).toBe('unita');

        entra(['amministratore'], TUTTI, {}, '/admin/inbox');
        expect(useVociMenu().corrente.value).toBeNull();
    });
});
