<script setup lang="ts">
/**
 * La barra dello studio (Web Pro Italia, 21/11/2026): la navigazione «Barra in alto».
 *
 * ## Il punto fermo
 *
 * È una fascia del colore principale, larga tutto lo schermo, **identica su ogni pagina**, dentro e fuori dai condomìni: a
 * sinistra il marchio e le sezioni dello studio, a destra la ricerca, le notifiche, le impostazioni, la guida e l'utente.
 * Il menu laterale è il contesto che si aggiunge: compare entrando in un condominio (`MenuLaterale.vue`, con le sole
 * sezioni del condominio) e sparisce uscendo. Non sostituisce mai la barra.
 *
 * ## Le voci
 *
 * Sono quelle di `vociMenu.ts` (stessi indirizzi, stessi permessi), raggruppate: Dashboard · Anagrafiche ▾ (Persone,
 * Fornitori) · Agenda · Comunicazioni ▾ (Bacheca, Segnalazioni) · Documenti ▾ (Documenti, Allegati) · Importa dati ·
 * Condomini ▾. Una tendina in cui l'utente vede una voce sola diventa un collegamento semplice. Resta accesa la sezione in
 * cui si è; dentro un condominio, «Condomini».
 *
 * **«Condomini ▾» è l'ultima** (Web Pro Italia, 21/11/2026): è la voce che fa entrare il menu laterale, e in fondo non spezza la
 * fila delle altre; ed essendo la più usata, l'ultimo posto la fa ritrovare subito. Porta all'elenco di tutti e, sotto, agli
 * ultimi aperti (cinque al più, `condominioInUso.ts`): è il modo rapido di tornare in un condominio, ora che nelle pagine
 * dello studio il menu laterale non c'è.
 *
 * ## Quando non c'è posto
 *
 * Le voci che non entrano finiscono in «Altro ▾» (si misura quanto spazio c'è), a partire dalle ultime; **«Condomini» non
 * ci finisce mai**: resta l'ultima voce della barra, dopo «Altro». Da telefono le voci stanno nel cassetto, che si apre dal
 * tasto a sinistra: le sezioni dello studio e, dentro un condominio, le sue.
 */
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { useResizeObserver } from '@vueuse/core';
import { trans } from 'laravel-vue-i18n';
import { Bell, Building2, ChevronDown, CircleArrowUp, List, Menu, Search } from 'lucide-vue-next';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import UserMenuContent from '@/components/UserMenuContent.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { useSidebar } from '@/components/ui/sidebar';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { getInitials } from '@/composables/useInitials';
import { usePermission } from '@/composables/permissions';
import { Permission } from '@/enums/Permission';
import { useCambioCondominio } from './cambioCondominio';
import { useCondominioInUso } from './condominioInUso';
import RicercaRapida from './RicercaRapida.vue';
import { scorciatoia, suMac } from './tasti';
import { useVociMenu, type VoceMenu } from './vociMenu';
import type { Auth } from '@/types';

const props = defineProps<{ gestionale?: boolean }>();

const page = usePage();
const { generatePath, hasPermission, getRolePrefix } = usePermission();
const { toggleSidebar } = useSidebar();
const { studio, fondo, nelPannello } = useVociMenu({ gestionale: props.gestionale });
const { recenti } = useCondominioInUso();
const { vaiAlCondominio } = useCambioCondominio({ gestionale: props.gestionale });

const utente = computed(() => (page.props.auth as Auth).user);
const nomeApp = computed(() => (page.props.name as string) || 'KondoManager');
const ricercaAperta = ref(false);

/** Una voce della barra: un collegamento, o una tendina con le sue voci. */
interface VoceBarra {
    chiave: string;
    nome: string;
    href?: string;
    attiva: boolean;
    voci?: VoceMenu[];
    /** «Condomini»: sotto le sue voci, gli ultimi condomini aperti. */
    condomini?: boolean;
    /** «Altro»: la tendina con le voci che non stanno nella barra. */
    altro?: boolean;
}

const GRUPPI: { chiave: string; nome?: () => string; voci: string[] }[] = [
    { chiave: 'dashboard', voci: ['dashboard'] },
    { chiave: 'anagrafiche', nome: () => trans('appHeader.anagrafiche'), voci: ['persone', 'fornitori'] },
    { chiave: 'agenda', voci: ['agenda'] },
    { chiave: 'comunicazioni', nome: () => trans('appHeader.comunicazioni'), voci: ['bacheca', 'segnalazioni'] },
    { chiave: 'archivio', nome: () => trans('appHeader.documenti'), voci: ['documenti', 'allegati'] },
    { chiave: 'importa', voci: ['importa'] },
    // L'ultima, sempre: vedi in testa al file.
    { chiave: 'condomini', voci: ['condomini'] },
];

const voci = computed<VoceBarra[]>(() => GRUPPI.flatMap((gruppo): VoceBarra[] => {
    const sue = gruppo.voci.map((chiave) => studio.value.find((v) => v.chiave === chiave)).filter((v): v is VoceMenu => !! v);
    if (gruppo.chiave === 'condomini') {
        // Chi ha il pannello entra nei condomìni: la tendina c'è se può vederne l'elenco o se ne ha aperti di recente.
        if (! sue.length && ! (nelPannello.value && recenti.value.length)) {
            return [];
        }

        return [{ chiave: 'condomini', nome: sue[0]?.nome ?? trans('appHeader.condomini'), attiva: !! props.gestionale || sue.some((v) => v.attiva), voci: sue, condomini: true }];
    }
    if (! sue.length) {
        return [];
    }
    // Una tendina con una voce sola è un collegamento semplice, con il nome della voce.
    if (sue.length === 1) {
        return [{ chiave: sue[0].chiave, nome: sue[0].nome, href: sue[0].href, attiva: sue[0].attiva && ! props.gestionale }];
    }

    return [{ chiave: gruppo.chiave, nome: gruppo.nome?.() ?? sue[0].nome, attiva: sue.some((v) => v.attiva) && ! props.gestionale, voci: sue }];
}));

// ─── Quante voci stanno nella barra: le altre vanno in «Altro ▾». «Condomini» resta sempre, ultima ───────────────────────
const condomini = computed(() => voci.value.find((v) => v.condomini) ?? null);
const leAltre = computed(() => voci.value.filter((v) => ! v.condomini));

const fila = ref<HTMLElement | null>(null);
const misura = ref<HTMLElement | null>(null);
const quante = ref(Number.POSITIVE_INFINITY);

const calcola = () => {
    const larga = (selettore: string) => [...(misura.value?.querySelectorAll<HTMLElement>(selettore) ?? [])].map((e) => e.offsetWidth);
    const larghezze = larga('[data-misura="voce"]');
    const altro = larga('[data-misura="altro"]')[0] ?? 0;
    // Il posto di «Condomini» è riservato: non finisce mai in «Altro».
    const spazio = (fila.value?.clientWidth ?? 0) - (larga('[data-misura="condomini"]')[0] ?? 0);
    // Senza misure (la barra non è a video, o è nel cassetto del telefono) stanno tutte.
    if (! fila.value?.clientWidth || larghezze.reduce((a, b) => a + b, 0) <= spazio) {
        quante.value = Number.POSITIVE_INFINITY;

        return;
    }
    let [somma, n] = [0, 0];
    while (n < larghezze.length && somma + larghezze[n] + altro <= spazio) {
        somma += larghezze[n];
        n += 1;
    }
    quante.value = n;
};
useResizeObserver(fila, calcola);
onMounted(calcola);
watch(voci, () => void nextTick(calcola));

const inAltro = computed(() => leAltre.value.slice(quante.value));
/** La fila a video: le voci che ci stanno, «Altro ▾» se ne restano fuori, e in fondo — sempre — «Condomini ▾». */
const inBarra = computed<VoceBarra[]>(() => [
    ...leAltre.value.slice(0, quante.value),
    ...(inAltro.value.length ? [{ chiave: 'altro', nome: 'Altro', attiva: inAltro.value.some((v) => v.attiva), altro: true }] : []),
    ...(condomini.value ? [condomini.value] : []),
]);

// ─── A destra ────────────────────────────────────────────────────────────────────────────────────────────────────────────
const casella = computed(() => (getRolePrefix() === 'admin' && hasPermission([Permission.VIEW_EVENTS])
    ? { href: route('admin.inbox'), numero: Number(page.props.inbox_count ?? 0), aperta: page.url.split('?')[0] === '/admin/inbox' }
    : null));

const aggiornamento = computed(() => {
    const stato = page.props.system_update as { available?: boolean; new_version?: string | null } | null | undefined;

    return stato?.available && hasPermission([Permission.MANAGE_GENERAL_SETTINGS]) ? { versione: stato.new_version ?? null } : null;
});
</script>

<template>
    <header class="km-barra-studio no-print sticky top-0 z-40 flex h-[var(--km-barra-studio)] w-full shrink-0 items-stretch print:hidden" data-barra-studio>
        <!-- Telefono: il tasto che apre il cassetto con le sezioni -->
        <button type="button" class="km-barra-voce px-3 md:hidden" data-menu-tasto aria-label="Apri il menu" @click="toggleSidebar">
            <Menu class="size-5" />
        </button>

        <Link :href="generatePath('dashboard')" class="km-barra-voce shrink-0 gap-2 px-3 md:pl-4" :title="nomeApp" data-barra-marchio>
            <AppLogoIcon class="size-5 shrink-0" />
            <span class="hidden max-w-[11rem] truncate text-[15px] font-medium sm:block">{{ nomeApp }}</span>
        </Link>

        <nav ref="fila" class="hidden min-w-0 flex-1 items-stretch overflow-hidden md:flex" aria-label="Sezioni dello studio" data-barra-voci>
            <template v-for="voce in inBarra" :key="voce.chiave">
                <Link v-if="voce.href" :href="voce.href" class="km-barra-voce shrink-0 px-3" :data-barra-voce="voce.chiave" :data-attiva="voce.attiva ? 'true' : 'false'" :aria-current="voce.attiva ? 'page' : undefined">{{ voce.nome }}</Link>

                <DropdownMenu v-else>
                    <DropdownMenuTrigger as-child>
                        <button type="button" class="km-barra-voce shrink-0 gap-1 px-3" :data-barra-voce="voce.chiave" :data-attiva="voce.attiva ? 'true' : 'false'">
                            {{ voce.nome }}<ChevronDown class="size-3.5 opacity-70" />
                        </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="start" :side-offset="0" class="min-w-56" :data-barra-tendina="voce.chiave">
                        <!-- «Altro»: le voci che non entrano, con il nome del loro gruppo -->
                        <template v-if="voce.altro">
                            <template v-for="(nascosta, i) in inAltro" :key="nascosta.chiave">
                                <DropdownMenuSeparator v-if="i > 0 && (nascosta.voci || inAltro[i - 1].voci)" />
                                <DropdownMenuItem v-if="nascosta.href" as-child>
                                    <Link :href="nascosta.href" class="flex w-full cursor-pointer items-center" :data-barra-voce="nascosta.chiave">{{ nascosta.nome }}</Link>
                                </DropdownMenuItem>
                                <template v-else>
                                    <DropdownMenuLabel class="text-xs font-medium uppercase tracking-wide text-muted-foreground">{{ nascosta.nome }}</DropdownMenuLabel>
                                    <DropdownMenuItem v-for="v in nascosta.voci" :key="v.chiave" as-child>
                                        <Link :href="v.href" class="flex w-full cursor-pointer items-center gap-2" :data-barra-voce="v.chiave"><component :is="v.icona" class="size-4 opacity-70" />{{ v.nome }}</Link>
                                    </DropdownMenuItem>
                                </template>
                            </template>
                        </template>
                        <template v-else-if="voce.condomini">
                            <DropdownMenuItem v-for="v in voce.voci" :key="v.chiave" as-child>
                                <Link :href="v.href" class="flex w-full cursor-pointer items-center gap-2"><List class="size-4 opacity-70" />Tutti i condomini</Link>
                            </DropdownMenuItem>
                            <template v-if="recenti.length">
                                <DropdownMenuSeparator v-if="voce.voci?.length" />
                                <DropdownMenuLabel class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Ultimi aperti</DropdownMenuLabel>
                                <DropdownMenuItem v-for="c in recenti" :key="c.id" class="cursor-pointer gap-2" :data-barra-condominio="c.id" @select="vaiAlCondominio(c.id)">
                                    <Building2 class="size-4 opacity-70" /><span class="truncate">{{ c.nome }}</span>
                                </DropdownMenuItem>
                            </template>
                        </template>
                        <template v-else>
                            <DropdownMenuItem v-for="v in voce.voci" :key="v.chiave" as-child :class="{ 'font-medium': v.attiva }">
                                <Link :href="v.href" class="flex w-full cursor-pointer items-center gap-2" :data-barra-voce="v.chiave">
                                    <component :is="v.icona" class="size-4 opacity-70" />{{ v.nome }}
                                    <span v-if="v.contatore" class="ml-auto rounded-full bg-amber-500/15 px-1.5 text-[11px] font-medium text-amber-700 tabular-nums">{{ v.contatore }}</span>
                                </Link>
                            </DropdownMenuItem>
                        </template>
                    </DropdownMenuContent>
                </DropdownMenu>
            </template>

        </nav>

        <!-- A destra: la ricerca, le notifiche, le impostazioni, la guida, l'utente -->
        <div class="ml-auto flex shrink-0 items-stretch" data-barra-strumenti>
            <button type="button" class="km-barra-voce gap-2 px-3" data-ricerca-campo :aria-label="`Ricerca rapida (${scorciatoia('K')})`" :title="`Ricerca rapida (${scorciatoia('K')})`" @click="ricercaAperta = true">
                <Search class="size-[1.05rem]" />
                <span class="hidden text-[13px] opacity-80 xl:block">Cerca</span>
                <kbd class="hidden rounded border border-white/25 px-1 font-sans text-[10px] leading-4 opacity-80 xl:block">{{ suMac ? '⌘K' : 'Ctrl K' }}</kbd>
            </button>

            <Link v-if="aggiornamento" href="/system/upgrade" class="km-barra-voce px-2.5" data-barra-aggiornamento :title="aggiornamento.versione ? `Aggiornamento disponibile: versione ${aggiornamento.versione}` : 'Aggiornamento disponibile'" aria-label="Aggiornamento disponibile">
                <CircleArrowUp class="size-[1.1rem]" />
            </Link>

            <Link v-if="casella" :href="casella.href" class="km-barra-voce relative px-2.5" data-barra-casella :data-attiva="casella.aperta ? 'true' : 'false'" :title="casella.numero ? `Casella delle attività: ${casella.numero} da fare` : 'Casella delle attività'" aria-label="Casella delle attività">
                <Bell class="size-[1.1rem]" />
                <span v-if="casella.numero" class="absolute right-1 top-1.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-bold leading-none text-white tabular-nums">{{ casella.numero > 99 ? '99+' : casella.numero }}</span>
            </Link>

            <!-- Le impostazioni e la guida, che nel menu laterale stanno in fondo -->
            <Tooltip v-for="voce in fondo" :key="voce.chiave" :delay-duration="300">
                <TooltipTrigger as-child>
                    <component :is="voce.esterna ? 'a' : Link" :href="voce.href" v-bind="voce.esterna ? { target: '_blank', rel: 'noopener' } : {}" class="km-barra-voce relative hidden px-2.5 sm:flex" :data-barra-voce="voce.chiave" :data-attiva="voce.attiva ? 'true' : 'false'" :aria-label="voce.titolo">
                        <component :is="voce.icona" class="size-[1.1rem]" />
                        <span v-if="voce.avviso" class="absolute right-1.5 top-2 size-2 rounded-full bg-orange-400" />
                    </component>
                </TooltipTrigger>
                <TooltipContent side="bottom">{{ voce.nome }}</TooltipContent>
            </Tooltip>

            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <button type="button" class="km-barra-voce gap-2 px-3" data-menu-utente :aria-label="utente.name">
                        <Avatar class="size-6 shrink-0 !rounded-full bg-white/20 text-[10px] font-bold text-white">
                            <AvatarImage v-if="utente.avatar" :src="utente.avatar" :alt="utente.name" />
                            <AvatarFallback>{{ getInitials(utente.name) }}</AvatarFallback>
                        </Avatar>
                        <span class="hidden max-w-[10rem] truncate text-[13px] lg:block">{{ utente.name }}</span>
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" :side-offset="0" class="w-60">
                    <UserMenuContent :user="utente" />
                </DropdownMenuContent>
            </DropdownMenu>
        </div>

        <!-- La fila di misura: le stesse voci, fuori dalla vista, per sapere quanto sono larghe -->
        <div ref="misura" class="km-barra-misura" aria-hidden="true">
            <span v-for="voce in leAltre" :key="voce.chiave" class="km-barra-voce shrink-0 gap-1 px-3" data-misura="voce">{{ voce.nome }}<ChevronDown v-if="! voce.href" class="size-3.5" /></span>
            <span class="km-barra-voce shrink-0 gap-1 px-3" data-misura="altro">Altro<ChevronDown class="size-3.5" /></span>
            <span v-if="condomini" class="km-barra-voce shrink-0 gap-1 px-3" data-misura="condomini">{{ condomini.nome }}<ChevronDown class="size-3.5" /></span>
        </div>

        <RicercaRapida v-model:open="ricercaAperta" :gestionale="gestionale" />
    </header>
</template>
