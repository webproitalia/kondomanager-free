<script setup lang="ts">
/**
 * Il percorso sempre in vista nella barra in alto: «Studio › condominio › esercizio».
 *
 * Nello studio dice la sezione («Studio › Agenda»). Dentro un condominio porta i due selettori: il condominio (un elenco in
 * cui si cerca per nome) e l'esercizio. Cambiando condominio si resta nella stessa pagina dove la pagina lo sa fare (la sua
 * testata ha l'elenco dei condomini, o riempie lo slot `breadcrumb-condominio`), altrimenti si apre il cruscotto dell'altro
 * condominio: così non si finisce su un indirizzo che non esiste (l'assemblea 4 di un altro condominio).
 *
 * Gli slot `breadcrumb-condominio` e `breadcrumb-esercizio` delle pagine continuano a funzionare: se una pagina li riempie, al
 * posto del selettore della barra c'è il suo.
 */
import { computed, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { Building2, CalendarDays, Check, ChevronRight, ChevronsUpDown, House, List } from 'lucide-vue-next';
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList, CommandSeparator } from '@/components/ui/command';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { usePermission } from '@/composables/permissions';
import { Permission } from '@/enums/Permission';
import { useElencoCondomini } from './elencoCondomini';
import { usePercorsoPagina } from './percorsoPagina';
import { useVociMenu } from './vociMenu';
import type { BreadcrumbItemType } from '@/types';
import type { Building } from '@/types/buildings';
import type { Esercizio } from '@/types/gestionale/esercizi';

const props = withDefaults(defineProps<{
    breadcrumbs?: BreadcrumbItemType[];
    gestionale?: boolean;
}>(), { breadcrumbs: () => [] });

const page = usePage();
const { generatePath, hasPermission } = usePermission();
const { corrente, nomeStudio } = useVociMenu({ gestionale: props.gestionale });
const { condomini, carica } = useElencoCondomini();
const dellaPagina = usePercorsoPagina();

const condominio = computed(() => (props.gestionale ? (page.props.condominio as Building | undefined) : undefined));
const esercizio = computed<Esercizio | null>(() => (props.gestionale ? (dellaPagina?.esercizio ?? (page.props.esercizio as Esercizio | null | undefined) ?? null) : null));
const esercizi = computed<Esercizio[]>(() => {
    if (dellaPagina?.esercizi.length) {
        return dellaPagina.esercizi;
    }
    const elenco = page.props.esercizi as unknown;

    return Array.isArray(elenco) && elenco.every((e) => e && typeof e.id === 'number' && typeof e.nome === 'string') ? (elenco as Esercizio[]) : [];
});

// ─── Il condominio ───────────────────────────────────────────────────────────────────────────────────────────────────────
const elencoAperto = ref(false);
const apriElenco = (aperto: boolean) => {
    elencoAperto.value = aperto;
    if (aperto) {
        void carica();
    }
};

const scegliCondominio = (id: number) => {
    elencoAperto.value = false;
    if (! condominio.value || id === condominio.value.id) {
        return;
    }
    if (! dellaPagina?.cambiaCondominioSulPosto) {
        router.visit(generatePath('gestionale/:condominio', { condominio: id }));

        return;
    }
    // Come la testata della pagina: stesso indirizzo, con l'altro condominio e il suo esercizio aperto.
    const segmenti = page.url.split('/');
    const posto = segmenti.findIndex((s) => s === String(condominio.value!.id));
    if (posto !== -1) {
        segmenti[posto] = String(id);
    }
    const aperto = condomini.value.find((c) => c.id === id)?.esercizio_aperto?.id;
    const postoEsercizio = segmenti.findIndex((_, i) => segmenti[i - 1] === 'esercizi');
    if (postoEsercizio !== -1 && aperto) {
        segmenti[postoEsercizio] = String(aperto);
    }
    router.visit(segmenti.join('/'), { preserveState: false, preserveScroll: true });
};

// ─── L'esercizio ─────────────────────────────────────────────────────────────────────────────────────────────────────────
/** L'esercizio si cambia dove sta nell'indirizzo della pagina: altrove è solo un'indicazione. */
const esercizioSiCambia = computed(() => esercizi.value.length > 1 && /\/esercizi\/\d+/.test(page.url));

const scegliEsercizio = (id: number) => {
    if (id === esercizio.value?.id) {
        return;
    }
    router.visit(page.url.replace(/\/esercizi\/\d+/, `/esercizi/${id}`), { preserveState: false, preserveScroll: true });
};

/** `2026-01-01` o `2026-01-01T00:00:00Z` → `01/01/2026`. */
const giorno = (data?: string | null) => {
    const parti = /^(\d{4})-(\d{2})-(\d{2})/.exec(data ?? '');

    return parti ? `${parti[3]}/${parti[2]}/${parti[1]}` : null;
};
const periodo = (e: Esercizio | null) => {
    const [dal, al] = [giorno(e?.data_inizio), giorno(e?.data_fine)];

    return dal && al ? `${dal} – ${al}` : null;
};

// ─── Le briciole che la pagina passa all'impalcatura ─────────────────────────────────────────────────────────────────────
/** Come nella testata: si mostrano solo se sono più d'una. I due selettori hanno il loro posto, e la radice «Gestionale» è il condominio. */
const briciole = computed(() => {
    if (props.breadcrumbs.length < 2) {
        return [];
    }

    return props.breadcrumbs
        .filter((b) => ! (b as { component?: string }).component)
        .filter((b, i) => ! (props.gestionale && i === 0 && b.title === 'Gestionale'));
});

const classeVoce = 'inline-flex h-8 min-w-0 items-center gap-1.5 rounded-md px-2 text-sm font-medium text-foreground/90 outline-none transition-colors hover:bg-accent hover:text-accent-foreground focus-visible:ring-2 focus-visible:ring-ring/50 data-[state=open]:bg-accent';
</script>

<template>
    <!-- Dove non ci sta tutto (un telefono, una finestra stretta) il percorso scorre di lato, e sfuma a destra invece di troncarsi di netto. -->
    <nav class="flex min-w-0 items-center gap-0.5 overflow-x-auto pr-4 [scrollbar-width:none] [mask-image:linear-gradient(to_right,black_calc(100%-1.25rem),transparent)]" aria-label="Percorso" data-percorso>
        <Link :href="generatePath('dashboard')" :class="[classeVoce, 'shrink-0 text-muted-foreground']" :title="`${nomeStudio}: dashboard`" data-percorso-studio>
            <House class="size-4 shrink-0" />
            <span :class="{ 'hidden sm:inline': condominio }">{{ nomeStudio }}</span>
        </Link>

        <!-- ─── Dentro un condominio ─── -->
        <template v-if="condominio">
            <ChevronRight class="size-3.5 shrink-0 text-muted-foreground/60" />

            <div v-if="$slots['breadcrumb-condominio']" :class="[classeVoce, 'shrink-0 has-[[data-state=open]]:bg-accent']" data-percorso-condominio>
                <Building2 class="size-4 shrink-0 text-primary" />
                <slot name="breadcrumb-condominio" />
            </div>
            <Popover v-else :open="elencoAperto" @update:open="apriElenco">
                <PopoverTrigger as-child>
                    <button type="button" :class="[classeVoce, 'shrink-0']" data-percorso-condominio :title="condominio.nome">
                        <Building2 class="size-4 shrink-0 text-primary" />
                        <span class="max-w-[9.5rem] truncate sm:max-w-[16rem] xl:max-w-[22rem]">{{ condominio.nome }}</span>
                        <ChevronsUpDown class="size-3.5 shrink-0 text-muted-foreground" />
                    </button>
                </PopoverTrigger>
                <PopoverContent align="start" class="w-80 p-0">
                    <Command>
                        <CommandInput placeholder="Cerca un condominio…" />
                        <CommandEmpty>Nessun condominio con questo nome.</CommandEmpty>
                        <CommandList class="max-h-[min(60vh,22rem)]">
                            <CommandGroup heading="Condomini">
                                <CommandItem v-for="c in condomini" :key="c.id" :value="`${c.nome} ${c.id}`" class="cursor-pointer" @select="scegliCondominio(c.id)">
                                    <Building2 class="text-muted-foreground" />
                                    <span class="truncate" :class="{ 'font-semibold': c.id === condominio.id }">{{ c.nome }}</span>
                                    <Check v-if="c.id === condominio.id" class="ml-auto text-primary" />
                                </CommandItem>
                            </CommandGroup>
                            <template v-if="hasPermission([Permission.VIEW_CONDOMINI])">
                                <CommandSeparator />
                                <CommandGroup>
                                    <CommandItem value="tutti-i-condomini elenco" class="cursor-pointer" @select="router.visit('/condomini')">
                                        <List class="text-muted-foreground" />
                                        Tutti i condomini
                                    </CommandItem>
                                </CommandGroup>
                            </template>
                        </CommandList>
                    </Command>
                </PopoverContent>
            </Popover>

            <template v-if="$slots['breadcrumb-esercizio'] || esercizio">
                <ChevronRight class="size-3.5 shrink-0 text-muted-foreground/60" />

                <div v-if="$slots['breadcrumb-esercizio']" :class="[classeVoce, 'shrink-0 has-[[data-state=open]]:bg-accent']" data-percorso-esercizio>
                    <CalendarDays class="size-4 shrink-0 text-primary" />
                    <slot name="breadcrumb-esercizio" />
                </div>
                <DropdownMenu v-else-if="esercizioSiCambia">
                    <DropdownMenuTrigger :class="[classeVoce, 'shrink-0']" data-percorso-esercizio>
                        <CalendarDays class="size-4 shrink-0 text-primary" />
                        <span class="max-w-[11rem] truncate">{{ esercizio!.nome }}</span>
                        <span v-if="periodo(esercizio)" class="hidden text-xs font-normal text-muted-foreground 2xl:inline">{{ periodo(esercizio) }}</span>
                        <ChevronsUpDown class="size-3.5 shrink-0 text-muted-foreground" />
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="start" class="min-w-64">
                        <DropdownMenuLabel class="text-xs font-medium text-muted-foreground">Esercizi</DropdownMenuLabel>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem v-for="e in esercizi" :key="e.id" class="cursor-pointer gap-2" @click="scegliEsercizio(e.id)">
                            <span class="min-w-0 flex-1">
                                <span class="block truncate" :class="{ 'font-semibold': e.id === esercizio!.id }">{{ e.nome }}</span>
                                <span v-if="periodo(e)" class="block text-xs text-muted-foreground">{{ periodo(e) }}</span>
                            </span>
                            <span v-if="e.stato === 'chiuso'" class="rounded bg-muted px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide text-muted-foreground">chiuso</span>
                            <Check v-if="e.id === esercizio!.id" class="size-4 text-primary" />
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
                <span v-else class="inline-flex h-8 shrink-0 items-center gap-1.5 px-2 text-sm font-medium text-foreground/90" data-percorso-esercizio :title="periodo(esercizio) ?? undefined">
                    <CalendarDays class="size-4 shrink-0 text-primary" />
                    <span class="max-w-[11rem] truncate">{{ esercizio!.nome }}</span>
                    <span v-if="periodo(esercizio)" class="hidden text-xs font-normal text-muted-foreground 2xl:inline">{{ periodo(esercizio) }}</span>
                </span>
            </template>
        </template>

        <!-- ─── Nello studio: la sezione in cui ci si trova ─── -->
        <template v-else-if="corrente && briciole.length === 0">
            <ChevronRight class="size-3.5 shrink-0 text-muted-foreground/60" />
            <span class="truncate px-2 text-sm font-medium text-foreground" data-percorso-sezione>{{ corrente.nome }}</span>
        </template>

        <!-- ─── Le briciole della pagina ─── -->
        <template v-for="(briciola, i) in briciole" :key="i">
            <ChevronRight class="size-3.5 shrink-0 text-muted-foreground/60" :class="{ 'hidden lg:block': condominio }" />
            <span v-if="i === briciole.length - 1 || ! briciola.href || briciola.href === '#'" class="shrink-0 truncate px-2 text-sm font-medium text-foreground" :class="{ 'hidden lg:inline': condominio }">{{ briciola.title }}</span>
            <Link v-else :href="briciola.href" :class="[classeVoce, 'shrink-0 text-muted-foreground', { 'hidden lg:inline-flex': condominio }]">{{ briciola.title }}</Link>
        </template>
    </nav>
</template>
