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
 * ## I nomi
 *
 * `nome` è il nome breve, del solo menu («Unità», «Conti», «Rate»); `titolo` è il nome intero della pagina («Immobili», «Piani
 * conti», «Piani rate»), che si legge al passaggio del mouse e nella ricerca rapida. Le pagine tengono i loro titoli.
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
 * porta, quello aperto del condominio (dall'elenco dei condomini della pagina). Senza un esercizio le prime tre portano
 * all'elenco degli esercizi, e le voci dei Movimenti sono spente con la nota «Nessun esercizio aperto», come nel loro menu.
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
    figlie?: VoceMenu[];
}

export interface GruppoCondominio {
    id: number;
    nome: string;
    esercizio: string | null;
    voci: VoceMenu[];
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

    const condominio = computed(() => (opzioni.gestionale ? (page.props.condominio as Condominio | undefined) : undefined));
    const esercizio = computed(() => (opzioni.gestionale ? (page.props.esercizio as Esercizio | null | undefined) : undefined));
    const condomini = computed(() => (page.props.condomini as (Condominio & { esercizio_aperto?: { id: number; nome?: string } | null })[] | undefined) ?? []);

    /** L'esercizio delle voci che ne hanno bisogno: quello della pagina, altrimenti quello aperto del condominio. */
    const esercizioId = computed<number | null>(() => {
        if (esercizio.value?.id) {
            return esercizio.value.id;
        }
        const corrente = Array.isArray(condomini.value) ? condomini.value.find((c) => c.id === condominio.value?.id) : undefined;

        return corrente?.esercizio_aperto?.id ?? null;
    });

    const allegatiInAttesa = computed(() => Number(page.props.allegati_in_attesa ?? 0));
    const aggiornamento = computed(() => Boolean((page.props.system_update as { available?: boolean } | null | undefined)?.available));

    // ─── Lo studio: le voci di `AppHeader.vue` ───────────────────────────────────────────────────────────────────────────────
    const definizioniStudio = computed<Definizione[]>(() => [
        { chiave: 'dashboard', nome: 'Dashboard', titolo: trans('appHeader.dashboard'), href: generatePath('dashboard'), icona: LayoutGrid, esatta: true },
        { chiave: 'agenda', nome: 'Agenda', titolo: trans('appHeader.agenda'), href: generatePath('eventi'), icona: CalendarClock, permissions: [Permission.VIEW_EVENTS] },
        { chiave: 'condomini', nome: 'Condomini', titolo: trans('appHeader.elencoCondomini'), href: '/condomini', icona: House, permissions: [Permission.VIEW_CONDOMINI] },
        { chiave: 'persone', nome: 'Persone', titolo: `${trans('appHeader.rubrica')}: ${trans('appHeader.anagrafiche').toLowerCase()}`, href: generatePath('anagrafiche'), icona: UsersRound, permissions: [Permission.VIEW_ANAGRAFICHE] },
        { chiave: 'fornitori', nome: 'Fornitori', titolo: `${trans('appHeader.rubrica')}: ${trans('appHeader.fornitori').toLowerCase()}`, href: generatePath('fornitori'), icona: BriefcaseBusiness, permissions: [Permission.VIEW_ANAGRAFICHE] },
        { chiave: 'bacheca', nome: 'Bacheca', titolo: trans('appHeader.bacheca'), href: generatePath('comunicazioni'), icona: Megaphone, permissions: [Permission.VIEW_COMUNICAZIONI] },
        { chiave: 'segnalazioni', nome: 'Segnalazioni', titolo: trans('appHeader.segnalazioni'), href: generatePath('segnalazioni'), icona: Tags, permissions: [Permission.VIEW_SEGNALAZIONI] },
        {
            chiave: 'documenti',
            nome: 'Documenti',
            titolo: trans('appHeader.documenti'),
            // Chi ha il pannello apre l'archivio; il condòmino sfoglia per categoria: come nella testata.
            href: hasPermission([Permission.ACCESS_ADMIN_PANEL]) ? generatePath('documenti') : generatePath('categorie-documenti'),
            icona: Folders,
            permissions: [Permission.VIEW_ARCHIVE_DOCUMENTS],
        },
        // Gli allegati da moderare: solo a chi ha il pannello e li può vedere, con il numero di quelli in attesa.
        ...(hasPermission([Permission.ACCESS_ADMIN_PANEL]) && hasPermission([Permission.VIEW_ATTACHMENTS_SEGNALAZIONI, Permission.VIEW_ATTACHMENTS_COMUNICAZIONI])
            ? [{ chiave: 'allegati', nome: 'Allegati', titolo: trans('appHeader.allegati'), href: '/admin/allegati', icona: Files, contatore: allegatiInAttesa.value }]
            : []),
        // L'importazione è il modo in cui un condominio entra nel gestionale: nella testata sta sotto «Condomini».
        { chiave: 'importa', nome: 'Importa dati', titolo: trans('appHeader.importaDati'), href: '/importa-dati', icona: DownloadCloud, permissions: [Permission.IMPORTA_DATI] },
    ]);

    // ─── Dentro un condominio: le voci di `GestionaleHeader.vue` e, sotto «Movimenti», quelle di `MovimentiLayout.vue` ─────────
    const definizioniCondominio = computed<Definizione[]>(() => {
        const id = condominio.value?.id;
        if (! id) {
            return [];
        }
        const c = (percorsoVoce: string) => generatePath(`gestionale/:condominio${percorsoVoce}`, { condominio: id });
        const e = (percorsoVoce: string) => (esercizioId.value
            ? generatePath(`gestionale/:condominio/esercizi/:esercizio${percorsoVoce}`, { condominio: id, esercizio: esercizioId.value })
            : null);
        // Senza un esercizio le voci che stanno sotto un esercizio portano all'elenco degli esercizi: lì se ne apre uno.
        const oElenco = (href: string | null) => href ?? c('/esercizi');
        const delMovimento = (href: string | null) => (href ? { href } : { href: '#', spenta: true, nota: 'Nessun esercizio aperto' });

        return [
            { chiave: 'cruscotto', nome: 'Cruscotto', titolo: 'Dashboard del condominio', href: c(''), icona: LayoutGrid, esatta: true },
            { chiave: 'struttura', nome: 'Condominio', titolo: 'Condominio: struttura, casse e saldi', href: c('/struttura'), icona: Building,
                anche: [c('/palazzine'), c('/scale'), c('/casse'), c('/saldi'), c('/contributi')] },
            { chiave: 'unita', nome: 'Unità', titolo: 'Immobili', href: c('/immobili'), icona: HousePlus },
            { chiave: 'tabelle', nome: 'Tabelle', titolo: 'Tabelle millesimali', href: c('/tabelle'), icona: Table2 },
            { chiave: 'esercizi', nome: 'Esercizi', titolo: 'Esercizi', href: c('/esercizi'), icona: Library },
            { chiave: 'gestioni', nome: 'Gestioni', titolo: 'Gestioni', href: oElenco(e('/gestioni')), icona: ListPlus },
            { chiave: 'conti', nome: 'Conti', titolo: 'Piani conti', href: oElenco(e('/piani-conti')), icona: HandCoins, anche: [e('/conti'), e('/voci')].filter((h): h is string => !! h) },
            { chiave: 'rate', nome: 'Rate', titolo: 'Piani rate', href: oElenco(e('/piani-rate')), icona: Wallet },
            {
                chiave: 'movimenti',
                nome: 'Movimenti',
                titolo: 'Movimenti',
                href: c('/movimenti'),
                icona: ArrowUpDown,
                figlie: [
                    { chiave: 'incassi', nome: 'Incassi', titolo: 'Incassi rate', href: c('/movimenti-rate'), icona: LogIn, anche: [c('/ricevute')] },
                    { chiave: 'rate-scadute', nome: 'Rate scadute', titolo: 'Rate scadute', href: c('/rate-scadute'), icona: CalendarClock },
                    { chiave: 'fatture', nome: 'Fatture', titolo: 'Fatture passive', href: c('/fatture'), icona: FileText, anche: [c('/regolazioni-immediate')] },
                    { chiave: 'pagamenti', nome: 'Pagamenti', titolo: 'Pagamenti fornitori', href: c('/pagamenti-fornitori'), icona: LogOut },
                    { chiave: 'giroconti', nome: 'Giroconti', titolo: 'Giroconti', href: c('/giroconti'), icona: Repeat2 },
                    { chiave: 'f24', nome: 'Ritenute e F24', titolo: 'Ritenute e F24', href: c('/f24'), icona: Percent },
                    { chiave: 'giornale', nome: 'Libro giornale', titolo: 'Libro Giornale', icona: BookOpen, ...delMovimento(e('/scritture')) },
                    { chiave: 'registro', nome: 'Registro', titolo: 'Registro di contabilità', icona: Wallet, ...delMovimento(e('/registro-contabilita')) },
                    { chiave: 'rendiconto', nome: 'Rendiconto', titolo: 'Rendiconto', icona: Scale, ...delMovimento(e('/rendiconto')),
                        anche: [e('/stato-patrimoniale')].filter((h): h is string => !! h) },
                    { chiave: 'fascicolo', nome: 'Fascicolo di bilancio', titolo: 'Fascicolo di bilancio', icona: BookMarked, ...delMovimento(e('/fascicolo-bilancio')) },
                ],
            },
            // Le assemblee e le registrazioni: il ruolo amministratore o il permesso, come le rotte.
            { chiave: 'assemblee', nome: 'Assemblee', titolo: 'Assemblee', href: c('/assemblee'), icona: Gavel, roles: [Role.AMMINISTRATORE], permissions: [Permission.VIEW_ASSEMBLEE] },
            { chiave: 'registrazioni', nome: 'Registrazioni', titolo: 'Registrazioni delle riunioni', href: c('/registrazioni'), icona: Mic, roles: [Role.AMMINISTRATORE], permissions: [Permission.VIEW_ASSEMBLEE] },
        ];
    });

    // ─── In fondo ────────────────────────────────────────────────────────────────────────────────────────────────────────────
    const definizioniFondo = computed<Definizione[]>(() => [
        { chiave: 'impostazioni', nome: 'Impostazioni', titolo: trans('appHeader.settings'), href: '/impostazioni', icona: Settings, permissions: [Permission.MANAGE_GENERAL_SETTINGS], avviso: aggiornamento.value },
        { chiave: 'guida', nome: 'Guida', titolo: trans('appHeader.documentation'), href: 'https://kondomanager.com/docs/index.html', icona: BookText, esterna: true },
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
    const gruppoCondominio = computed<GruppoCondominio | null>(() => (condominio.value
        ? {
            id: condominio.value.id,
            nome: condominio.value.nome,
            esercizio: esercizio.value?.nome ?? null,
            voci: accessibili(definizioniCondominio.value).map((d) => inVoce(d)),
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
