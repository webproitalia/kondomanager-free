/**
 * Le voci del menu laterale (Web Pro Italia, 18/11/2026).
 *
 * ## Che cosa sono
 *
 * Le stesse voci delle due testate di sempre — `AppHeader.vue` (lo studio) e `GestionaleHeader.vue` (dentro un condominio) — e
 * del menu dei Movimenti (`layouts/gestionale/MovimentiLayout.vue`), con gli **stessi indirizzi, gli stessi permessi e le stesse
 * condizioni**. Cambia come si presentano: in gruppi, nell'ordine a cui è abituato chi viene dai gestionali da tavolo (Agenda,
 * Condomini, Unità, Tabelle, Conti, Rate, Movimenti, Assemblee, Persone, Fornitori), ognuna con la sua icona e il suo colore,
 * sempre gli stessi, così una sezione si riconosce al volo.
 *
 * ⚠️ Una voce aggiunta a una delle due testate va aggiunta anche qui (e viceversa): sono due impalcature della stessa
 * applicazione, e la scelta fra le due è di chi usa l'installazione (Impostazioni → Aspetto).
 *
 * ## Il menu non cambia mai
 *
 * I due gruppi — lo studio, e sotto le sezioni del condominio — ci sono su ogni pagina, nello stesso ordine e con gli stessi
 * nomi: è il punto fermo di chi lavora. Dentro un condominio le sezioni sono le sue. Nelle pagine dello studio sono quelle del
 * **condominio in uso**, l'ultimo aperto (`condominioInUso.ts`): «Unità» porta alle sue unità. Se non c'è un condominio in
 * uso le sezioni restano al loro posto, da scegliere (`daScegliere`): un clic apre la scelta del condominio e poi va lì.
 *
 * ## I nomi
 *
 * `nome` è il nome breve, del solo menu («Unità», «Conti», «Rate»); `titolo` è il nome intero della pagina («Immobili», «Piani
 * conti», «Piani rate»), che si legge al passaggio del mouse e nella ricerca rapida. Le pagine tengono i loro titoli.
 * Le voci dello studio prendono il nome dalle traduzioni della testata (`appHeader.*`), nella lingua dell'installazione; quelle
 * del condominio sono scritte qui, come nella testata del gestionale.
 *
 * ## La voce accesa
 *
 * Le testate accendono una voce solo sul suo indirizzo esatto: nella scheda di un'unità «Immobili» è spenta. Qui una voce resta
 * accesa in tutte le sue pagine: il confronto è per segmento (`/fatture` non si accende su `/fatture-altro`), e fra più voci che
 * corrispondono vince la più precisa (in `/esercizi/3/gestioni` si accende «Gestioni», non «Esercizi»).
 *
 * ## L'esercizio
 *
 * Gestioni, Conti, Rate e quattro voci dei Movimenti stanno sotto un esercizio. Si usa quello della pagina; se la pagina non lo
 * porta, quello aperto del condominio (dall'elenco dei condomini della pagina), poi quello in cui si lavorava l'ultima volta
 * (ricordato con il condominio in uso). Senza un esercizio le prime tre portano all'elenco degli esercizi, dove se ne sceglie
 * uno, e le voci dei Movimenti sono spente con la nota «Nessun esercizio aperto», come nel loro menu.
 */
import { computed, type Component } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import {
    ArrowUpDown, BookMarked, BookOpen, BookText, BriefcaseBusiness, Building, CalendarClock, DownloadCloud, Files, FileText, Folders,
    Gavel, GitGraph, HandCoins, House, HousePlus, LayoutGrid, Library, ListPlus, LogIn, LogOut, Megaphone, Mic, Percent, Repeat2, Scale,
    Settings, Table2, Tags, UsersRound, Wallet,
} from 'lucide-vue-next';
import { usePermission } from '@/composables/permissions';
import { Permission } from '@/enums/Permission';
import { Role } from '@/enums/Role';
import { SORGENTE } from '@/lib/sorgente';
import { useCondominioInUso } from './condominioInUso';
import type { Building as Condominio } from '@/types/buildings';
import type { Esercizio } from '@/types/gestionale/esercizi';

export interface VoceMenu {
    /** Il nome stabile della voce: lo usano i test, i colori e le schermate di prova. */
    chiave: string;
    /** Il nome breve, del solo menu. */
    nome: string;
    /** Il nome intero: al passaggio del mouse e nella ricerca rapida. */
    titolo: string;
    href: string;
    icona: Component;
    /** Il colore della sezione: sempre lo stesso. Le voci dentro «Movimenti» hanno quello di «Movimenti». */
    colore: string;
    attiva: boolean;
    /** Si apre in un'altra scheda (la guida, il codice sorgente). */
    esterna?: boolean;
    /** Un numero accanto alla voce (gli allegati in attesa). */
    contatore?: number;
    /** Un puntino arancione: c'è un aggiornamento da installare. */
    avviso?: boolean;
    /** Si mostra, non si clicca (le voci dei Movimenti senza un esercizio aperto). */
    spenta?: boolean;
    nota?: string;
    /**
     * Non c'è un condominio in uso: la voce resta al suo posto, e un clic apre la scelta del condominio. `sezione` è il pezzo
     * di indirizzo, sotto il condominio, a cui andare dopo la scelta (`/immobili`; per le voci sotto un esercizio, `/esercizi`).
     */
    daScegliere?: boolean;
    sezione?: string;
    figlie?: VoceMenu[];
}

export interface GruppoCondominio {
    /** `null` quando non c'è un condominio in uso: le voci ci sono lo stesso, da scegliere. */
    id: number | null;
    nome: string | null;
    esercizio: string | null;
    voci: VoceMenu[];
    /** Le voci sono quelle del condominio della pagina (si è dentro un condominio), non di quello ricordato. */
    dellaPagina: boolean;
}

/**
 * I colori delle sezioni. Toni pieni e non accesi, che reggono un'icona bianca e si leggono sul fondo scuro del menu.
 * Le sezioni parenti hanno colori vicini (il cruscotto come la dashboard, la struttura del condominio come i condomini).
 */
export const COLORI_SEZIONI: Record<string, string> = {
    dashboard: '#5b67e8',
    agenda: '#e2574c',
    condomini: '#2f8fd8',
    persone: '#8a63d2',
    fornitori: '#d9822b',
    bacheca: '#d6558f',
    segnalazioni: '#e0a020',
    documenti: '#1f9e8f',
    allegati: '#64748b',
    importa: '#4f9d4c',

    cruscotto: '#5b67e8',
    struttura: '#2f8fd8',
    unita: '#1f9e8f',
    tabelle: '#3aa655',
    esercizi: '#8a63d2',
    gestioni: '#b457b8',
    conti: '#d9822b',
    rate: '#e0a020',
    movimenti: '#e2574c',
    assemblee: '#d6558f',
    registrazioni: '#1f9eb5',

    impostazioni: '#64748b',
    guida: '#64748b',
    sorgente: '#64748b',
};

interface Definizione extends Omit<VoceMenu, 'attiva' | 'colore' | 'figlie'> {
    colore?: string;
    /** Altri indirizzi che accendono la voce (le pagine della sezione che stanno su un indirizzo loro). */
    anche?: string[];
    /** Si accende solo sul suo indirizzo esatto (le due dashboard: tutto il resto sta sotto di loro). */
    esatta?: boolean;
    roles?: Role[];
    permissions?: Permission[];
    figlie?: Definizione[];
}

/**
 * L'ordine delle sezioni dello studio con la navigazione «Barra in alto» (nella barra e nel cassetto del telefono).
 * «Condomini» è l'ultima (Web Pro Italia, 21/11/2026): è la voce che fa entrare il menu laterale, e in fondo non spezza la fila
 * delle altre; ed essendo la più usata, l'ultimo posto la fa ritrovare subito.
 */
export const ORDINE_BARRA = ['dashboard', 'persone', 'fornitori', 'agenda', 'bacheca', 'segnalazioni', 'documenti', 'allegati', 'importa', 'condomini'];

/** L'indirizzo senza la domanda e senza l'ancora: `/admin/eventi?mese=3` è la pagina `/admin/eventi`. */
export const percorsoDi = (url: string): string => url.split(/[?#]/)[0].replace(/\/+$/, '') || '/';

/** Quanto una voce corrisponde alla pagina: 0 se non corrisponde, altrimenti la lunghezza dell'indirizzo che corrisponde. */
const corrispondenza = (voce: Definizione, percorso: string): number => {
    if (voce.spenta || voce.esterna || voce.href === '#') {
        return 0;
    }
    const sotto = (href: string) => percorso === href || percorso.startsWith(href + '/');
    if (voce.esatta) {
        return percorso === voce.href ? voce.href.length : 0;
    }

    return Math.max(0, ...[voce.href, ...(voce.anche ?? [])].filter(sotto).map((href) => href.length));
};

export function useVociMenu(opzioni: { gestionale?: boolean } = {}) {
    const page = usePage();
    const { generatePath, canAccess, hasPermission, getRolePrefix } = usePermission();

    const percorso = computed(() => percorsoDi(page.url));
    const nelPannello = computed(() => getRolePrefix() === 'admin');

    const { inUso } = useCondominioInUso();

    /** Dentro un condominio, quello della pagina; nelle pagine dello studio, quello in uso (l'ultimo aperto). */
    const dellaPagina = computed(() => (opzioni.gestionale ? (page.props.condominio as Condominio | undefined) : undefined));
    const condominio = computed<{ id: number; nome: string } | null>(() => {
        if (dellaPagina.value?.id) {
            return { id: dellaPagina.value.id, nome: dellaPagina.value.nome };
        }

        return inUso.value ? { id: inUso.value.id, nome: inUso.value.nome } : null;
    });
    const esercizio = computed(() => (opzioni.gestionale ? (page.props.esercizio as Esercizio | null | undefined) : undefined));
    const condomini = computed(() => (page.props.condomini as (Condominio & { esercizio_aperto?: { id: number; nome?: string } | null })[] | undefined) ?? []);
    /** L'esercizio ricordato vale per il condominio a cui appartiene. */
    const ricordato = computed(() => (inUso.value && inUso.value.id === condominio.value?.id ? inUso.value.esercizio : null));

    /** L'esercizio delle voci che ne hanno bisogno: quello della pagina, poi quello aperto del condominio, poi quello ricordato. */
    const esercizioId = computed<number | null>(() => {
        if (esercizio.value?.id) {
            return esercizio.value.id;
        }
        const corrente = dellaPagina.value && Array.isArray(condomini.value) ? condomini.value.find((c) => c.id === dellaPagina.value?.id) : undefined;

        return corrente?.esercizio_aperto?.id ?? ricordato.value?.id ?? null;
    });

    const allegatiInAttesa = computed(() => Number(page.props.allegati_in_attesa ?? 0));
    const aggiornamento = computed(() => Boolean((page.props.system_update as { available?: boolean } | null | undefined)?.available));

    // ─── Lo studio: le voci di `AppHeader.vue` ───────────────────────────────────────────────────────────────────────────────
    const definizioniStudio = computed<Definizione[]>(() => [
        { chiave: 'dashboard', nome: trans('appHeader.dashboard'), titolo: trans('appHeader.dashboard'), href: generatePath('dashboard'), icona: LayoutGrid, esatta: true },
        { chiave: 'agenda', nome: trans('appHeader.agenda'), titolo: trans('appHeader.agenda'), href: generatePath('eventi'), icona: CalendarClock, permissions: [Permission.VIEW_EVENTS] },
        { chiave: 'condomini', nome: trans('appHeader.condomini'), titolo: trans('appHeader.elencoCondomini'), href: '/condomini', icona: House, permissions: [Permission.VIEW_CONDOMINI] },
        { chiave: 'persone', nome: trans('appHeader.persone'), titolo: `${trans('appHeader.rubrica')}: ${trans('appHeader.anagrafiche').toLowerCase()}`, href: generatePath('anagrafiche'), icona: UsersRound, permissions: [Permission.VIEW_ANAGRAFICHE] },
        { chiave: 'fornitori', nome: trans('appHeader.fornitori'), titolo: `${trans('appHeader.rubrica')}: ${trans('appHeader.fornitori').toLowerCase()}`, href: generatePath('fornitori'), icona: BriefcaseBusiness, permissions: [Permission.VIEW_ANAGRAFICHE] },
        { chiave: 'bacheca', nome: trans('appHeader.bacheca'), titolo: trans('appHeader.bacheca'), href: generatePath('comunicazioni'), icona: Megaphone, permissions: [Permission.VIEW_COMUNICAZIONI] },
        { chiave: 'segnalazioni', nome: trans('appHeader.segnalazioni'), titolo: trans('appHeader.segnalazioni'), href: generatePath('segnalazioni'), icona: Tags, permissions: [Permission.VIEW_SEGNALAZIONI] },
        {
            chiave: 'documenti',
            nome: trans('appHeader.documenti'),
            titolo: trans('appHeader.documenti'),
            // Chi ha il pannello apre l'archivio; il condòmino sfoglia per categoria: come nella testata.
            href: hasPermission([Permission.ACCESS_ADMIN_PANEL]) ? generatePath('documenti') : generatePath('categorie-documenti'),
            icona: Folders,
            permissions: [Permission.VIEW_ARCHIVE_DOCUMENTS],
        },
        // Gli allegati da moderare: solo a chi ha il pannello e li può vedere, con il numero di quelli in attesa.
        ...(hasPermission([Permission.ACCESS_ADMIN_PANEL]) && hasPermission([Permission.VIEW_ATTACHMENTS_SEGNALAZIONI, Permission.VIEW_ATTACHMENTS_COMUNICAZIONI])
            ? [{ chiave: 'allegati', nome: trans('appHeader.allegati'), titolo: trans('appHeader.allegati'), href: '/admin/allegati', icona: Files, contatore: allegatiInAttesa.value }]
            : []),
        // L'importazione è il modo in cui un condominio entra nel gestionale: nella testata sta sotto «Condomini».
        { chiave: 'importa', nome: trans('appHeader.importaDati'), titolo: trans('appHeader.importaDati'), href: '/importa-dati', icona: DownloadCloud, permissions: [Permission.IMPORTA_DATI] },
    ]);

    // ─── Dentro un condominio: le voci di `GestionaleHeader.vue` e, sotto «Movimenti», quelle di `MovimentiLayout.vue` ─────────
    const definizioniCondominio = computed<Definizione[]>(() => {
        // Chi non ha il pannello (un condòmino) non entra nel gestionale: per lui il gruppo non c'è.
        if (! nelPannello.value) {
            return [];
        }
        const id = condominio.value?.id ?? null;
        // Senza un condominio in uso le voci restano al loro posto: un clic apre la scelta, poi si va a `sezione`.
        const c = (sezione: string) => (id ? generatePath(`gestionale/:condominio${sezione}`, { condominio: id }) : '#');
        const e = (sezione: string) => (id && esercizioId.value
            ? generatePath(`gestionale/:condominio/esercizi/:esercizio${sezione}`, { condominio: id, esercizio: esercizioId.value })
            : null);
        const voce = (sezione: string) => ({ href: c(sezione), sezione, ...(id ? {} : { daScegliere: true }) });
        // Senza un esercizio le voci che stanno sotto un esercizio portano all'elenco degli esercizi: lì se ne apre uno.
        const sottoEsercizio = (sezione: string) => ({ ...voce('/esercizi'), ...(e(sezione) ? { href: e(sezione) as string } : {}) });
        const delMovimento = (sezione: string) => {
            if (! id) {
                return voce('/esercizi');
            }

            return e(sezione) ? { href: e(sezione) as string } : { href: '#', spenta: true, nota: 'Nessun esercizio aperto' };
        };
        const anche = (...indirizzi: (string | null)[]) => (id ? indirizzi.filter((h): h is string => !! h && h !== '#') : []);

        return [
            { chiave: 'cruscotto', nome: 'Cruscotto', titolo: 'Dashboard del condominio', icona: LayoutGrid, esatta: true, ...voce('') },
            { chiave: 'struttura', nome: 'Condominio', titolo: 'Condominio: struttura, casse e saldi', icona: Building, ...voce('/struttura'),
                anche: anche(c('/palazzine'), c('/scale'), c('/casse'), c('/saldi'), c('/contributi')) },
            { chiave: 'unita', nome: 'Unità', titolo: 'Immobili', icona: HousePlus, ...voce('/immobili') },
            { chiave: 'tabelle', nome: 'Tabelle', titolo: 'Tabelle millesimali', icona: Table2, ...voce('/tabelle') },
            { chiave: 'esercizi', nome: 'Esercizi', titolo: 'Esercizi', icona: Library, ...voce('/esercizi') },
            { chiave: 'gestioni', nome: 'Gestioni', titolo: 'Gestioni', icona: ListPlus, ...sottoEsercizio('/gestioni') },
            { chiave: 'conti', nome: 'Conti', titolo: 'Piani conti', icona: HandCoins, ...sottoEsercizio('/piani-conti'), anche: anche(e('/conti'), e('/voci')) },
            { chiave: 'rate', nome: 'Rate', titolo: 'Piani rate', icona: Wallet, ...sottoEsercizio('/piani-rate') },
            {
                chiave: 'movimenti',
                nome: 'Movimenti',
                titolo: 'Movimenti',
                icona: ArrowUpDown,
                ...voce('/movimenti'),
                figlie: [
                    { chiave: 'incassi', nome: 'Incassi', titolo: 'Incassi rate', icona: LogIn, ...voce('/movimenti-rate'), anche: anche(c('/ricevute')) },
                    { chiave: 'rate-scadute', nome: 'Rate scadute', titolo: 'Rate scadute', icona: CalendarClock, ...voce('/rate-scadute') },
                    { chiave: 'fatture', nome: 'Fatture', titolo: 'Fatture passive', icona: FileText, ...voce('/fatture'), anche: anche(c('/regolazioni-immediate')) },
                    { chiave: 'pagamenti', nome: 'Pagamenti', titolo: 'Pagamenti fornitori', icona: LogOut, ...voce('/pagamenti-fornitori') },
                    { chiave: 'giroconti', nome: 'Giroconti', titolo: 'Giroconti', icona: Repeat2, ...voce('/giroconti') },
                    { chiave: 'f24', nome: 'Ritenute e F24', titolo: 'Ritenute e F24', icona: Percent, ...voce('/f24') },
                    { chiave: 'giornale', nome: 'Libro giornale', titolo: 'Libro Giornale', icona: BookOpen, ...delMovimento('/scritture') },
                    { chiave: 'registro', nome: 'Registro', titolo: 'Registro di contabilità', icona: Wallet, ...delMovimento('/registro-contabilita') },
                    { chiave: 'rendiconto', nome: 'Rendiconto', titolo: 'Rendiconto', icona: Scale, ...delMovimento('/rendiconto'), anche: anche(e('/stato-patrimoniale')) },
                    { chiave: 'fascicolo', nome: 'Fascicolo di bilancio', titolo: 'Fascicolo di bilancio', icona: BookMarked, ...delMovimento('/fascicolo-bilancio') },
                ],
            },
            // Le assemblee e le registrazioni: il ruolo amministratore o il permesso, come le rotte.
            { chiave: 'assemblee', nome: 'Assemblee', titolo: 'Assemblee', icona: Gavel, roles: [Role.AMMINISTRATORE], permissions: [Permission.VIEW_ASSEMBLEE], ...voce('/assemblee') },
            { chiave: 'registrazioni', nome: 'Registrazioni', titolo: 'Registrazioni delle riunioni', icona: Mic, roles: [Role.AMMINISTRATORE], permissions: [Permission.VIEW_ASSEMBLEE], ...voce('/registrazioni') },
        ];
    });

    // ─── In fondo ────────────────────────────────────────────────────────────────────────────────────────────────────────────
    const definizioniFondo = computed<Definizione[]>(() => [
        // Gli utenti, i ruoli, i permessi e gli inviti si aprono dalle impostazioni («Gestione utenti»), su indirizzi loro.
        { chiave: 'impostazioni', nome: trans('appHeader.settings'), titolo: trans('appHeader.settings'), href: '/impostazioni', icona: Settings, permissions: [Permission.MANAGE_GENERAL_SETTINGS], avviso: aggiornamento.value,
            anche: ['/utenti', '/ruoli', '/permessi', '/inviti'] },
        { chiave: 'guida', nome: trans('appHeader.guida'), titolo: trans('appHeader.documentation'), href: 'https://kondomanager.com/docs/index.html', icona: BookText, esterna: true },
    ]);

    /** Il codice sorgente (AGPL, sezione 13): nella testata è un'icona; qui sta nella ricerca rapida e nel piè di pagina. */
    const sorgente = computed<VoceMenu>(() => ({ chiave: 'sorgente', nome: 'Codice sorgente', titolo: trans('appHeader.repository'), href: SORGENTE, icona: GitGraph, colore: COLORI_SEZIONI.sorgente, attiva: false, esterna: true }));

    // ─── Dalle definizioni alle voci: i permessi, il colore, la voce accesa ──────────────────────────────────────────────────
    const accessibili = (definizioni: Definizione[]): Definizione[] => definizioni.filter((d) => canAccess({ roles: d.roles, permissions: d.permissions }));

    /** La voce più precisa fra tutte quelle che corrispondono alla pagina. */
    const accesa = computed<string | null>(() => {
        let migliore: { chiave: string; punti: number } | null = null;
        const guarda = (d: Definizione, prefisso = '') => {
            const punti = corrispondenza(d, percorso.value);
            if (punti > 0 && (! migliore || punti > migliore.punti)) {
                migliore = { chiave: prefisso + d.chiave, punti };
            }
            (d.figlie ?? []).forEach((f) => guarda(f, d.chiave + '/'));
        };
        [...accessibili(definizioniCondominio.value), ...accessibili(definizioniStudio.value), ...accessibili(definizioniFondo.value)].forEach((d) => guarda(d));

        return (migliore as { chiave: string; punti: number } | null)?.chiave ?? null;
    });

    const inVoce = (d: Definizione, madre?: Definizione): VoceMenu => {
        const { anche, esatta, roles, permissions, figlie, ...resto } = d;
        void anche; void esatta; void roles; void permissions;
        const chiaveIntera = madre ? `${madre.chiave}/${d.chiave}` : d.chiave;
        const sue = figlie ? accessibili(figlie).map((f) => inVoce(f, d)) : undefined;

        return {
            ...resto,
            colore: d.colore ?? COLORI_SEZIONI[madre?.chiave ?? d.chiave] ?? COLORI_SEZIONI.impostazioni,
            // Una voce con le sue figlie è accesa anche quando lo è una di loro: «Movimenti» nelle pagine dei movimenti.
            attiva: accesa.value === chiaveIntera || !! sue?.some((f) => f.attiva),
            ...(sue ? { figlie: sue } : {}),
        };
    };

    const studio = computed<VoceMenu[]>(() => accessibili(definizioniStudio.value).map((d) => inVoce(d)));
    const fondo = computed<VoceMenu[]>(() => accessibili(definizioniFondo.value).map((d) => inVoce(d)));
    /** Il gruppo del condominio: c'è sempre per chi ha il pannello, anche senza un condominio in uso (voci da scegliere). */
    const gruppoCondominio = computed<GruppoCondominio | null>(() => (nelPannello.value
        ? {
            id: condominio.value?.id ?? null,
            nome: condominio.value?.nome ?? null,
            esercizio: condominio.value ? (esercizio.value?.nome ?? ricordato.value?.nome ?? null) : null,
            voci: accessibili(definizioniCondominio.value).map((d) => inVoce(d)),
            dellaPagina: !! dellaPagina.value?.id,
        }
        : null));

    /** La voce in cui ci si trova, fino alla figlia: dà l'icona alla testata della pagina e il nome al percorso. */
    const corrente = computed<VoceMenu | null>(() => {
        for (const voce of [...(gruppoCondominio.value?.voci ?? []), ...studio.value, ...fondo.value]) {
            if (! voce.attiva) {
                continue;
            }

            return voce.figlie?.find((f) => f.attiva) ?? voce;
        }

        return null;
    });

    /** Il nome del gruppo dello studio: chi non ha il pannello (un condòmino) non sta in uno studio. */
    const nomeStudio = computed(() => (nelPannello.value ? 'Studio' : 'Il mio condominio'));

    return { studio, gruppoCondominio, fondo, sorgente, corrente, nomeStudio, nelPannello, esercizioId, percorso };
}
