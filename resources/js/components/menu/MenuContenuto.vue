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
 */
import { computed, ref, watch } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { Building2, ChevronsLeft, ChevronsRight, ChevronsUpDown, X } from 'lucide-vue-next';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import UserMenuContent from '@/components/UserMenuContent.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
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
import { useVociMenu, type VoceMenu } from './vociMenu';
import type { Auth } from '@/types';

const props = defineProps<{
    /** Dentro un condominio (`GestionaleLayout`): il gruppo del condominio è il suo. */
    gestionale?: boolean;
    /** Il menu è chiuso: solo le icone. */
    chiuso?: boolean;
}>();

const page = usePage();
const { generatePath } = usePermission();
// Il comando per richiudere e riaprire sta sul menu stesso (oltre che nella barra in alto e sul suo bordo): lì lo si cerca.
const { toggleSidebar, isMobile } = useSidebar();
const { studio, gruppoCondominio, fondo, nomeStudio } = useVociMenu({ gestionale: props.gestionale });

const utente = computed(() => (page.props.auth as Auth).user);
const nomeApp = computed(() => (page.props.name as string) || 'KondoManager');

// ─── Scegliere il condominio in uso ──────────────────────────────────────────────────────────────────────────────────────
const { condomini, interi, carica } = useElencoCondomini();
const { controllaCon } = useCondominioInUso();
const { vaiAlCondominio } = useCambioCondominio({ gestionale: props.gestionale });

const sceltaAperta = ref(false);
/** La sezione cliccata quando non c'era un condominio in uso: dopo la scelta si va lì. */
const sezioneInAttesa = ref<VoceMenu | null>(null);

const apriScelta = (aperta: boolean, voce: VoceMenu | null = null) => {
    sezioneInAttesa.value = aperta ? voce : null;
    sceltaAperta.value = aperta;
    if (aperta) {
        void carica();
    }
};

const scelto = (condominio: CondominioInElenco) => {
    const sezione = sezioneInAttesa.value?.sezione;
    apriScelta(false);
    vaiAlCondominio(condominio.id, sezione);
};

// Il condominio in uso non è più nell'elenco intero dei condomini (eliminato): il ricordo si cancella, e il menu chiede di
// sceglierne uno.
watch(interi, (elenco) => elenco && controllaCon(elenco), { immediate: true });

const testa = computed(() => ({
    nome: gruppoCondominio.value?.nome ?? 'Scegli un condominio',
    sotto: gruppoCondominio.value?.nome ? (gruppoCondominio.value.esercizio ?? 'Condominio in uso') : 'Per aprire le sue sezioni',
}));
</script>

<template>
    <div class="flex h-full min-h-0 flex-col" :data-menu-chiuso="chiuso ? 'true' : 'false'">
        <!-- Il marchio -->
        <div class="flex h-[var(--km-testa)] shrink-0 items-center" :class="chiuso ? 'justify-center' : 'pl-3.5 pr-2'">
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
        <div v-if="chiuso" class="flex shrink-0 justify-center pb-0.5">
            <Tooltip :delay-duration="0">
                <TooltipTrigger as-child>
                    <button type="button" class="km-voce flex h-6 w-10 items-center justify-center rounded-lg text-white/70 hover:text-white" data-menu-riapri aria-label="Apri il menu" @click="toggleSidebar">
                        <ChevronsRight class="size-[1.15rem]" />
                    </button>
                </TooltipTrigger>
                <TooltipContent side="right" :side-offset="10">Apri il menu<span class="ml-1.5 opacity-60">{{ scorciatoia('B') }}</span></TooltipContent>
            </Tooltip>
        </div>

        <nav class="km-menu-scorre min-h-0 flex-1 overflow-y-auto overflow-x-hidden px-2.5 pb-2" aria-label="Menu principale">
            <!-- ─── 1. Lo studio: sempre qui, sempre uguale ─── -->
            <div v-if="! chiuso" class="km-menu-etichetta flex h-6 items-end px-2 pb-1 text-[11px] font-semibold uppercase tracking-[0.08em]" data-menu-gruppo="studio">{{ nomeStudio }}</div>
            <div class="flex flex-col gap-px" :class="{ 'items-center': chiuso }" data-menu-voci="studio">
                <VoceMenuRiga v-for="voce in studio" :key="voce.chiave" :voce="voce" :solo-icona="chiuso" />
            </div>

            <!-- ─── 2. Il condominio in uso: la riga di testa (un clic: se ne sceglie un altro) e le sue sezioni, rientrate ─── -->
            <template v-if="gruppoCondominio">
                <div v-if="chiuso" class="mx-1.5 my-1.5 h-px bg-[var(--km-menu-riga)]" data-menu-separatore />

                <Popover :open="sceltaAperta" @update:open="(aperta: boolean) => apriScelta(aperta)">
                    <PopoverTrigger as-child>
                        <button
                            v-if="chiuso"
                            type="button"
                            class="km-voce km-riga mx-auto flex w-10 items-center justify-center rounded-lg"
                            data-menu-condominio
                            :aria-label="`${testa.nome}: scegli il condominio`"
                            :title="`${testa.nome} — ${testa.sotto}. Clicca per scegliere il condominio.`"
                        >
                            <span class="km-icona-voce flex items-center justify-center bg-white/10 text-white ring-1 ring-inset ring-white/15"><Building2 class="size-[60%]" /></span>
                        </button>
                        <button
                            v-else
                            type="button"
                            class="km-voce km-condominio mb-px mt-2 flex w-full items-center gap-2.5 rounded-lg px-1.5 text-left ring-1 ring-inset ring-white/[0.08]"
                            data-menu-condominio
                            :data-senza-condominio="gruppoCondominio.id ? undefined : ''"
                            :title="`${testa.nome} — clicca per scegliere il condominio`"
                        >
                            <span class="km-icona-voce flex shrink-0 items-center justify-center bg-white/10 text-white"><Building2 class="size-[60%]" /></span>
                            <span class="min-w-0 flex-1 leading-[1.15]">
                                <span class="block truncate text-[13px] font-semibold text-white">{{ testa.nome }}</span>
                                <span class="km-menu-etichetta block truncate text-[10.5px]">{{ testa.sotto }}</span>
                            </span>
                            <ChevronsUpDown class="mr-0.5 size-3.5 shrink-0 opacity-50" />
                        </button>
                    </PopoverTrigger>
                    <PopoverContent side="right" align="start" :side-offset="12" class="w-80 p-0" data-menu-scelta-condominio>
                        <p v-if="sezioneInAttesa" class="border-b px-3 py-2 text-xs text-muted-foreground">Scegli il condominio: si apre «{{ sezioneInAttesa.nome }}».</p>
                        <ElencoCondomini :condomini="condomini" :attuale="gruppoCondominio.id" @scelto="scelto" />
                    </PopoverContent>
                </Popover>

                <div class="flex flex-col gap-px" :class="chiuso ? 'items-center' : 'km-rientro'" data-menu-voci="condominio">
                    <template v-for="voce in gruppoCondominio.voci" :key="voce.chiave">
                        <VoceConFiglie v-if="voce.figlie?.length" :voce="voce" :solo-icona="chiuso" :livello="2" @scegli="(v: VoceMenu) => apriScelta(true, v)" />
                        <VoceMenuRiga v-else :voce="voce" :solo-icona="chiuso" :livello="2" @scegli="(v: VoceMenu) => apriScelta(true, v)" />
                    </template>
                </div>
            </template>
        </nav>

        <!-- ─── In fondo, compatti: l'utente con il suo menu, le impostazioni e la guida ─── -->
        <div class="flex shrink-0 gap-1 border-t border-[var(--km-menu-riga)] px-2.5 py-1.5" :class="chiuso ? 'flex-col-reverse items-center' : 'items-center'" data-menu-fondo>
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
