<script setup lang="ts">
/**
 * La barra in alto dell'impalcatura a menu laterale: fissa, chiara, sottile.
 *
 * A sinistra il tasto che apre e chiude il menu e il percorso «Studio › condominio › esercizio», sempre nello stesso posto.
 * A destra la ricerca rapida, la casella delle attività con il numero di quelle in sospeso e, quando c'è, l'aggiornamento
 * da installare. Le altre voci che la testata di sempre teneva a destra stanno nel menu: le registrazioni fra le voci del
 * condominio, le impostazioni, la guida e l'utente in fondo; il codice sorgente nella ricerca e nel piè di pagina.
 */
import { computed, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { Bell, CircleArrowUp, PanelLeft, Search } from 'lucide-vue-next';
import { useSidebar } from '@/components/ui/sidebar';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { usePermission } from '@/composables/permissions';
import { Permission } from '@/enums/Permission';
import PercorsoStudio from './PercorsoStudio.vue';
import RicercaRapida from './RicercaRapida.vue';
import { scorciatoia, suMac } from './tasti';
import type { BreadcrumbItemType } from '@/types';

withDefaults(defineProps<{
    breadcrumbs?: BreadcrumbItemType[];
    gestionale?: boolean;
}>(), { breadcrumbs: () => [] });

const page = usePage();
const { hasPermission, getRolePrefix } = usePermission();
const { toggleSidebar, state, isMobile } = useSidebar();

const ricercaAperta = ref(false);

// La casella delle attività si apre con «Visualizza scadenze in agenda», come sulla dashboard (Coda 402), ed è del pannello.
const casella = computed(() => (getRolePrefix() === 'admin' && hasPermission([Permission.VIEW_EVENTS])
    ? { href: route('admin.inbox'), numero: Number(page.props.inbox_count ?? 0), aperta: page.url.split('?')[0] === '/admin/inbox' }
    : null));

// L'aggiornamento: nella testata di sempre è il puntino arancione sulle impostazioni, e lo vede chi le può aprire.
const aggiornamento = computed(() => {
    const stato = page.props.system_update as { available?: boolean; new_version?: string | null } | null | undefined;

    return stato?.available && hasPermission([Permission.MANAGE_GENERAL_SETTINGS]) ? { versione: stato.new_version ?? null } : null;
});

const tastoMenu = computed(() => (isMobile.value ? 'Apri il menu' : state.value === 'collapsed' ? 'Apri il menu' : 'Chiudi il menu'));
const classeTasto = 'relative inline-flex size-9 shrink-0 items-center justify-center rounded-md text-muted-foreground outline-none transition-colors hover:bg-accent hover:text-accent-foreground focus-visible:ring-2 focus-visible:ring-ring/50';
</script>

<template>
    <header class="km-barra no-print sticky top-0 z-40 flex h-14 shrink-0 items-center gap-1.5 border-b border-border/80 bg-background/90 px-2.5 backdrop-blur-md md:px-4 print:hidden" data-barra-superiore>
        <Tooltip :delay-duration="400">
            <TooltipTrigger as-child>
                <button type="button" :class="classeTasto" data-menu-tasto :aria-label="tastoMenu" @click="toggleSidebar">
                    <PanelLeft class="size-[1.15rem]" />
                </button>
            </TooltipTrigger>
            <TooltipContent side="bottom">{{ tastoMenu }} <span class="ml-1 opacity-60">{{ scorciatoia('B') }}</span></TooltipContent>
        </Tooltip>

        <span class="mx-1 hidden h-5 w-px shrink-0 bg-border sm:block" />

        <PercorsoStudio :breadcrumbs="breadcrumbs" :gestionale="gestionale" class="flex-1">
            <template v-if="$slots['breadcrumb-condominio']" #breadcrumb-condominio><slot name="breadcrumb-condominio" /></template>
            <template v-if="$slots['breadcrumb-esercizio']" #breadcrumb-esercizio><slot name="breadcrumb-esercizio" /></template>
        </PercorsoStudio>

        <div class="flex shrink-0 items-center gap-1">
            <!-- La ricerca rapida: un campo da `xl` in su, un tasto sotto -->
            <button
                type="button"
                class="hidden h-9 w-60 items-center gap-2 rounded-lg border border-input bg-muted/50 px-2.5 text-sm text-muted-foreground outline-none transition-colors hover:bg-accent hover:text-accent-foreground focus-visible:ring-2 focus-visible:ring-ring/50 xl:inline-flex 2xl:w-72"
                data-ricerca-campo
                @click="ricercaAperta = true"
            >
                <Search class="size-4 shrink-0" />
                <span class="flex-1 truncate text-left">Cerca o vai a…</span>
                <kbd class="rounded border bg-background px-1.5 py-0.5 font-sans text-[10px] font-medium leading-none text-muted-foreground">{{ suMac ? '⌘K' : 'Ctrl K' }}</kbd>
            </button>
            <button type="button" :class="[classeTasto, 'xl:hidden']" aria-label="Cerca o vai a…" data-ricerca-tasto @click="ricercaAperta = true">
                <Search class="size-[1.15rem]" />
            </button>

            <Tooltip v-if="aggiornamento" :delay-duration="200">
                <TooltipTrigger as-child>
                    <Link href="/impostazioni" :class="[classeTasto, 'text-orange-600 hover:text-orange-700 dark:text-orange-400']" aria-label="Aggiornamento disponibile" data-barra-aggiornamento>
                        <CircleArrowUp class="size-[1.15rem]" />
                        <span class="absolute right-1.5 top-1.5 size-2 rounded-full bg-orange-500 ring-2 ring-background" />
                    </Link>
                </TooltipTrigger>
                <TooltipContent side="bottom">Aggiornamento disponibile<template v-if="aggiornamento.versione">: {{ aggiornamento.versione }}</template></TooltipContent>
            </Tooltip>

            <Tooltip v-if="casella" :delay-duration="200">
                <TooltipTrigger as-child>
                    <Link :href="casella.href" :class="[classeTasto, { 'bg-accent text-accent-foreground': casella.aperta }]" :aria-label="`Casella delle attività: ${casella.numero} in sospeso`" data-barra-casella>
                        <Bell class="size-[1.15rem]" />
                        <span v-if="casella.numero > 0" class="absolute right-0.5 top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-bold leading-none text-white ring-2 ring-background tabular-nums">{{ casella.numero > 99 ? '99+' : casella.numero }}</span>
                    </Link>
                </TooltipTrigger>
                <TooltipContent side="bottom">Casella delle attività<template v-if="casella.numero > 0">: {{ casella.numero }} in sospeso</template></TooltipContent>
            </Tooltip>
        </div>

        <RicercaRapida v-model:open="ricercaAperta" :gestionale="gestionale" />
    </header>
</template>
