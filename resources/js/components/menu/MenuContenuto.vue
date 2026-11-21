<script setup lang="ts">
/**
 * Il contenuto del menu laterale: il marchio in testa, i due gruppi delle voci, in fondo l'utente, le impostazioni e la guida.
 * Lo disegnano la colonna a sinistra (aperta, o chiusa a sole icone) e, sugli schermi stretti, il cassetto.
 *
 * ## Il menu è un punto fermo
 *
 * **Non cambia mai** (Web Pro Italia, 18/11/2026: «la sidebar che si trasforma è caotica: quello deve rimanere un punto fermo che
 * hanno sempre in visione»). Su ogni pagina ci sono, nello stesso posto e nello stesso ordine:
 *
 * 1. **lo studio**: Dashboard, Agenda, Condomini, Persone, Fornitori… a righe intere;
 * 2. **il condominio in uso**: una riga di testa con il suo nome e l'esercizio (un clic: se ne sceglie un altro), e sotto,
 *    rientrate lungo una linea di guida, le sue sezioni: Cruscotto, Condominio, Unità, Tabelle, Esercizi, Gestioni, Conti,
 *    Rate, Movimenti (che si apre: un rientro in più), Assemblee, Registrazioni.
 *
 * Dentro un condominio il secondo gruppo è il suo; nelle pagine dello studio è l'ultimo aperto (`condominioInUso.ts`).
 * Senza un condominio in uso la riga di testa dice «Scegli un condominio» e le sezioni restano al loro posto, tenui: un clic
 * su una di loro apre la scelta e poi porta a quella sezione.
 *
 * Il menu chiuso ha le stesse icone, nello stesso ordine, con un separatore fra i due gruppi.
 *
 * Lo studio non scorre mai: se le voci non stanno nello schermo (con «Movimenti» aperto), scorre il solo gruppo del
 * condominio, sotto la sua riga di testa. Solo sugli schermi molto bassi (sotto i 560 px) scorre tutto insieme (`menu-laterale.css`).
 *
 * ## La riga di testa del condominio ha due comandi
 *
 * Il nome (un clic: si sceglie un altro condominio, o in fondo all'elenco «Chiudi il condominio») e, a destra, la freccia
 * che **ripiega** le sezioni: chi lavora su altro non vuole sempre l'elenco spalancato. Ripiegato resta la sola riga di
 * testa, accesa quando la pagina è di quel condominio, così si capisce dove si è. Lo stato si ricorda per utente, e il
 * gruppo si riapre da solo solo quando si apre o si cambia condominio con una scelta esplicita (`condominioInUso.ts`).
 * Nel menu chiuso la freccia sta sul separatore fra i due gruppi: ripiegato si vede la sola icona del condominio.
 */
import { computed, ref, watch } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { Building2, ChevronRight, ChevronsLeft, ChevronsRight, ChevronsUpDown, X } from 'lucide-vue-next';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import UserMenuContent from '@/components/UserMenuContent.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Collapsible, CollapsibleContent } from '@/components/ui/collapsible';
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { useSidebar } from '@/components/ui/sidebar';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { getInitials } from '@/composables/useInitials';
import { usePermission } from '@/composables/permissions';
import { useCambioCondominio } from './cambioCondominio';
import { useCondominioInUso } from './condominioInUso';
import ElencoCondomini from './ElencoCondomini.vue';
import { useElencoCondomini, type CondominioInElenco } from './elencoCondomini';
import { scorciatoia } from './tasti';
import VoceConFiglie from './VoceConFiglie.vue';
import VoceMenuRiga from './VoceMenu.vue';
import { ORDINE_BARRA, useVociMenu, type VoceMenu } from './vociMenu';
import type { Auth } from '@/types';

const props = defineProps<{
    /** Dentro un condominio (`GestionaleLayout`): il gruppo del condominio è il suo. */
    gestionale?: boolean;
    /** Il menu è chiuso: solo le icone. */
    chiuso?: boolean;
    /**
     * La navigazione «Barra in alto»: le sezioni dello studio, l'utente, le impostazioni e la guida stanno nella barra
     * (`BarraStudio.vue`), e la colonna — che c'è solo dentro un condominio — ha le sole sezioni del condominio. Nel cassetto
     * del telefono, dove la barra non ha posto per le voci, ci sono anche quelle dello studio.
     */
    barra?: boolean;
}>();

const page = usePage();
const { generatePath } = usePermission();
// Il comando per richiudere e riaprire sta sul menu stesso (oltre che nella barra in alto e sul suo bordo): lì lo si cerca.
const { toggleSidebar, isMobile } = useSidebar();
const { studio, gruppoCondominio, fondo, nomeStudio } = useVociMenu({ gestionale: props.gestionale });
/** Con la barra in alto le sezioni dello studio hanno l'ordine della barra: «Condomini» è l'ultima. */
const studioInOrdine = computed(() => (props.barra ? [...studio.value].sort((a, b) => ORDINE_BARRA.indexOf(a.chiave) - ORDINE_BARRA.indexOf(b.chiave)) : studio.value));
/** Con la barra in alto la colonna ha le sole sezioni del condominio (nel cassetto del telefono c'è tutto). */
const soloCondominio = computed(() => !! props.barra && ! isMobile.value);
/** Con la barra in alto il gruppo del condominio c'è solo dentro un condominio: fuori ci si torna dalla barra. */
const conIlCondominio = computed(() => !! gruppoCondominio.value && (! props.barra || !! props.gestionale));

const utente = computed(() => (page.props.auth as Auth).user);
const nomeApp = computed(() => (page.props.name as string) || 'KondoManager');

// ─── Scegliere il condominio in uso ──────────────────────────────────────────────────────────────────────────────────────
const { condomini, interi, carica } = useElencoCondomini();
const { controllaCon, ripiegato, ripiega } = useCondominioInUso();
const { vaiAlCondominio, chiudiCondominio } = useCambioCondominio({ gestionale: props.gestionale });

const sceltaAperta = ref(false);
/** La sezione cliccata quando non c'era un condominio in uso: dopo la scelta si va lì. */
const sezioneInAttesa = ref<VoceMenu | null>(null);

let richiestaDiAprire = 0;

const apriScelta = (aperta: boolean, voce: VoceMenu | null = null) => {
    const questa = ++richiestaDiAprire;
    sezioneInAttesa.value = aperta ? voce : null;
    if (! aperta) {
        sceltaAperta.value = false;

        return;
    }
    // L'elenco si apre quando i condomini ci sono (un attimo): aperto prima, nascerebbe con il solo «Tutti i condomini», e la
    // tastiera partirebbe da lì invece che dal primo condominio.
    void carica().finally(() => {
        if (questa === richiestaDiAprire) {
            sceltaAperta.value = true;
        }
    });
};

const scelto = (condominio: CondominioInElenco) => {
    const sezione = sezioneInAttesa.value?.sezione;
    apriScelta(false);
    vaiAlCondominio(condominio.id, sezione);
};

/** «Chiudi il condominio»: nessun condominio in uso (da dentro una sua pagina si torna alla dashboard dello studio). */
const chiudi = () => {
    apriScelta(false);
    chiudiCondominio();
};

// Il condominio in uso non è più nell'elenco intero dei condomini (eliminato): il ricordo si cancella, e il menu chiede di
// sceglierne uno.
watch(interi, (elenco) => elenco && controllaCon(elenco), { immediate: true });

const testa = computed(() => ({
    nome: gruppoCondominio.value?.nome ?? 'Scegli un condominio',
    sotto: gruppoCondominio.value?.nome ? (gruppoCondominio.value.esercizio ?? 'Condominio in uso') : 'Per aprire le sue sezioni',
}));

// ─── Ripiegare il gruppo del condominio ──────────────────────────────────────────────────────────────────────────────────
const comandoRipiega = computed(() => (ripiegato.value ? 'Mostra le sezioni' : 'Nascondi le sezioni'));
/** Ripiegato, la riga di testa dice da sola dove si è: è accesa quando la pagina è di quel condominio. */
const testaAccesa = computed(() => ripiegato.value && !! gruppoCondominio.value?.dellaPagina);
</script>

<template>
    <div class="flex h-full min-h-0 flex-col" :data-menu-chiuso="chiuso ? 'true' : 'false'">
        <!-- Il marchio -->
        <div v-if="! soloCondominio" class="flex h-[var(--km-testa)] shrink-0 items-center" :class="chiuso ? 'justify-center' : 'pl-3.5 pr-2'">
            <Link :href="generatePath('dashboard')" class="flex min-w-0 flex-1 items-center gap-2.5 rounded-lg outline-none focus-visible:ring-2 focus-visible:ring-white/40" :class="{ 'flex-none': chiuso }" :title="nomeApp">
                <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-[var(--km-menu-attiva)] text-white shadow-[inset_0_1px_0_hsl(0_0%_100%/0.18),0_1px_2px_hsl(0_0%_0%/0.3)]">
                    <AppLogoIcon class="size-5" />
                </span>
                <span v-if="! chiuso" class="truncate text-[15px] font-semibold tracking-tight text-white">{{ nomeApp }}</span>
            </Link>

            <!-- Richiudere: la freccia accanto al nome (nel cassetto del telefono, la × che lo chiude) -->
            <Tooltip v-if="! chiuso" :delay-duration="300">
                <TooltipTrigger as-child>
                    <button type="button" class="km-voce ml-1 flex size-8 shrink-0 items-center justify-center rounded-lg text-white/70 hover:text-white" data-menu-richiudi :aria-label="isMobile ? 'Chiudi il menu' : 'Richiudi il menu'" @click="toggleSidebar">
                        <X v-if="isMobile" class="size-4" />
                        <ChevronsLeft v-else class="size-[1.15rem]" />
                    </button>
                </TooltipTrigger>
                <TooltipContent side="bottom">{{ isMobile ? 'Chiudi il menu' : 'Richiudi il menu' }}<span v-if="! isMobile" class="ml-1.5 opacity-60">{{ scorciatoia('B') }}</span></TooltipContent>
            </Tooltip>
        </div>

        <!-- Riaprire: con il menu chiuso, la freccia subito sotto il marchio -->
        <div v-if="chiuso" class="flex shrink-0 justify-center pb-0.5" :class="{ 'pt-2': soloCondominio }">
            <Tooltip :delay-duration="0">
                <TooltipTrigger as-child>
                    <button type="button" class="km-voce flex h-6 w-10 items-center justify-center rounded-lg text-white/70 hover:text-white" data-menu-riapri aria-label="Apri il menu" @click="toggleSidebar">
                        <ChevronsRight class="size-[1.15rem]" />
                    </button>
                </TooltipTrigger>
                <TooltipContent side="right" :side-offset="10">Apri il menu<span class="ml-1.5 opacity-60">{{ scorciatoia('B') }}</span></TooltipContent>
            </Tooltip>
        </div>

        <!--
            Due zone. Lo studio sta fermo: non scorre mai via, nemmeno con «Movimenti» aperto. Sotto, il condominio in uso: se
            le sue voci non stanno nello schermo scorre lui solo, con la riga di testa che resta in cima (`menu-laterale.css`).
        -->
        <nav class="km-menu-scorre km-menu-zone min-h-0 flex-1 px-2.5 pb-2" aria-label="Menu principale">
            <!-- ─── 1. Lo studio: sempre qui, sempre uguale ─── -->
            <div v-if="! soloCondominio" class="km-menu-zona-studio" data-menu-zona="studio">
                <div v-if="! chiuso" class="km-menu-etichetta flex h-6 items-end px-2 pb-1 text-[11px] font-semibold uppercase tracking-[0.08em]" data-menu-gruppo="studio">{{ nomeStudio }}</div>
                <div class="flex flex-col gap-px" :class="{ 'items-center': chiuso }" data-menu-voci="studio">
                    <VoceMenuRiga v-for="voce in studioInOrdine" :key="voce.chiave" :voce="voce" :solo-icona="chiuso" />
                </div>
            </div>

            <!-- ─── 2. Il condominio in uso: la riga di testa (un clic: se ne sceglie un altro) e le sue sezioni, rientrate ─── -->
            <div v-if="conIlCondominio && gruppoCondominio" class="km-menu-zona-condominio" data-menu-zona="condominio">
                <!-- Menu chiuso: il separatore fra i due gruppi è anche la freccia che ripiega le icone delle sezioni -->
                <Tooltip v-if="chiuso && ! soloCondominio" :delay-duration="0">
                    <TooltipTrigger as-child>
                        <button type="button" class="km-ripiega-icone mx-auto flex h-[18px] w-10 items-center gap-1 rounded" data-menu-separatore data-menu-ripiega :aria-expanded="! ripiegato" :aria-label="comandoRipiega" @click="ripiega(! ripiegato)">
                            <span class="h-px flex-1 bg-white/15" />
                            <ChevronRight class="size-3.5 shrink-0 transition-transform duration-200" :class="{ 'rotate-90': ! ripiegato }" :stroke-width="2.6" />
                            <span class="h-px flex-1 bg-white/15" />
                        </button>
                    </TooltipTrigger>
                    <TooltipContent side="right" :side-offset="10">{{ comandoRipiega }}</TooltipContent>
                </Tooltip>

                <Collapsible :open="soloCondominio || ! ripiegato">
                    <!-- La riga di testa: il nome (si sceglie il condominio) e la freccia (si ripiegano le sezioni) -->
                    <div
                        :class="chiuso ? 'flex justify-center' : 'km-condominio mb-px flex items-stretch rounded-lg ring-1 ring-inset ring-white/[0.08]'"
                        data-menu-testa-condominio
                        :data-attiva="! chiuso && testaAccesa ? 'true' : undefined"
                    >
                        <!-- La scelta sta DENTRO il suggerimento, come l'elenco di «Movimenti» a menu chiuso (`VoceConFiglie.vue`) -->
                        <Tooltip :delay-duration="chiuso ? 0 : 300">
                            <TooltipTrigger as-child>
                                <span class="flex min-w-0" :class="{ 'flex-1': ! chiuso }">
                                    <Popover :open="sceltaAperta" @update:open="(aperta: boolean) => apriScelta(aperta)">
                                        <PopoverTrigger as-child>
                                            <button
                                                v-if="chiuso"
                                                type="button"
                                                class="km-voce km-riga relative flex w-10 items-center justify-center rounded-lg"
                                                data-menu-condominio
                                                :data-attiva="testaAccesa ? 'true' : 'false'"
                                                :aria-label="`${testa.nome}: scegli il condominio`"
                                            >
                                                <span v-if="testaAccesa" class="km-tacca" />
                                                <span class="km-icona-voce flex items-center justify-center bg-white/10 text-white ring-1 ring-inset ring-white/15"><Building2 class="size-[60%]" /></span>
                                            </button>
                                            <button
                                                v-else
                                                type="button"
                                                class="km-voce flex min-w-0 flex-1 items-center gap-2 rounded-l-lg pl-1.5 pr-1 text-left"
                                                data-menu-condominio
                                                :data-senza-condominio="gruppoCondominio.id ? undefined : ''"
                                            >
                                                <span class="km-icona-voce flex shrink-0 items-center justify-center bg-white/10 text-white"><Building2 class="size-[60%]" /></span>
                                                <span class="min-w-0 flex-1 leading-[1.15]">
                                                    <span class="block truncate text-[13px] font-semibold text-white">{{ testa.nome }}</span>
                                                    <span class="km-menu-etichetta block truncate text-[10.5px]">{{ testa.sotto }}</span>
                                                </span>
                                                <!-- «Scegli un condominio» si spiega da solo, e deve leggersi intero: le due freccette solo con un nome -->
                                                <ChevronsUpDown v-if="gruppoCondominio.id" class="size-3 shrink-0 opacity-50" />
                                            </button>
                                        </PopoverTrigger>
                                        <!-- Accanto al menu, non sopra la freccia che ripiega (che sta a destra del nome) -->
                                        <PopoverContent side="right" align="start" :side-offset="chiuso ? 12 : 40" class="w-80 p-0" data-menu-scelta-condominio>
                                            <p v-if="sezioneInAttesa" class="border-b px-3 py-2 text-xs text-muted-foreground">Scegli il condominio: si apre «{{ sezioneInAttesa.nome }}».</p>
                                            <ElencoCondomini :condomini="condomini" :attuale="gruppoCondominio.id" chiudibile @scelto="scelto" @chiudi="chiudi" />
                                        </PopoverContent>
                                    </Popover>
                                </span>
                            </TooltipTrigger>
                            <!-- Chiuso: il nome, che non si vede. Aperto: il nome intero (nella riga può essere tagliato) e che cosa fa il clic. -->
                            <TooltipContent v-if="chiuso" side="right" :side-offset="10">{{ testa.nome }}<span class="ml-1.5 opacity-60">{{ testa.sotto }}</span></TooltipContent>
                            <TooltipContent v-else-if="gruppoCondominio.id" side="right" :side-offset="38">{{ testa.nome }}<span class="ml-1.5 opacity-60">Cambia condominio</span></TooltipContent>
                        </Tooltip>

                        <Tooltip v-if="! chiuso" :delay-duration="300">
                            <TooltipTrigger as-child>
                                <button
                                    type="button"
                                    class="km-voce flex w-7 shrink-0 items-center justify-center rounded-r-lg border-l border-white/[0.08]"
                                    :data-menu-ripiega="soloCondominio ? undefined : ''"
                                    :data-menu-richiudi="soloCondominio ? '' : undefined"
                                    :aria-expanded="soloCondominio ? undefined : ! ripiegato"
                                    :aria-label="soloCondominio ? 'Richiudi il menu' : comandoRipiega"
                                    @click="soloCondominio ? toggleSidebar() : ripiega(! ripiegato)"
                                >
                                    <!-- Con la barra in alto le sezioni sono tutto il menu: la freccia lo richiude a sole icone -->
                                    <ChevronsLeft v-if="soloCondominio" class="size-4 opacity-70" />
                                    <ChevronRight v-else class="size-4 opacity-70 transition-transform duration-200" :class="{ 'rotate-90': ! ripiegato }" />
                                </button>
                            </TooltipTrigger>
                            <TooltipContent side="right" :side-offset="10">{{ soloCondominio ? 'Richiudi il menu' : comandoRipiega }}<span v-if="soloCondominio" class="ml-1.5 opacity-60">{{ scorciatoia('B') }}</span></TooltipContent>
                        </Tooltip>
                    </div>

                    <CollapsibleContent>
                        <div class="flex flex-col gap-px" :class="chiuso ? 'items-center' : 'km-rientro'" data-menu-voci="condominio">
                            <template v-for="voce in gruppoCondominio.voci" :key="voce.chiave">
                                <VoceConFiglie v-if="voce.figlie?.length" :voce="voce" :solo-icona="chiuso" :livello="2" @scegli="(v: VoceMenu) => apriScelta(true, v)" />
                                <VoceMenuRiga v-else :voce="voce" :solo-icona="chiuso" :livello="2" @scegli="(v: VoceMenu) => apriScelta(true, v)" />
                            </template>
                        </div>
                    </CollapsibleContent>
                </Collapsible>
            </div>
        </nav>

        <!-- ─── In fondo, compatti: l'utente con il suo menu, le impostazioni e la guida ─── -->
        <div v-if="! soloCondominio" class="flex shrink-0 gap-1 border-t border-[var(--km-menu-riga)] px-2.5 py-1.5" :class="chiuso ? 'flex-col-reverse items-center' : 'items-center'" data-menu-fondo>
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <button type="button" class="km-voce flex min-w-0 items-center rounded-lg text-left" :class="chiuso ? 'km-riga w-10 justify-center' : 'h-10 flex-1 gap-2.5 px-1.5'" data-menu-utente :aria-label="utente.name">
                        <Avatar class="km-icona-voce shrink-0 !rounded-[6px] bg-white/15 text-[11px] font-bold text-white ring-1 ring-inset ring-white/15">
                            <AvatarImage v-if="utente.avatar" :src="utente.avatar" :alt="utente.name" />
                            <AvatarFallback>{{ getInitials(utente.name) }}</AvatarFallback>
                        </Avatar>
                        <span v-if="! chiuso" class="min-w-0 flex-1 leading-[1.15]">
                            <span class="block truncate text-[13px] font-medium text-white">{{ utente.name }}</span>
                            <span class="km-menu-etichetta block truncate text-[10.5px]">{{ utente.email }}</span>
                        </span>
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent :side="chiuso ? 'right' : 'top'" align="start" :side-offset="8" class="w-60">
                    <UserMenuContent :user="utente" />
                </DropdownMenuContent>
            </DropdownMenu>

            <div class="flex shrink-0 gap-px" :class="{ 'flex-col items-center': chiuso }">
                <VoceMenuRiga v-for="voce in fondo" :key="voce.chiave" :voce="voce" solo-icona />
            </div>
        </div>
    </div>
</template>
