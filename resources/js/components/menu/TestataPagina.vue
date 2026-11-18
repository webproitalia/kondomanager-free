<script setup lang="ts">
/**
 * La testata di una pagina nell'impalcatura a menu laterale (Web Pro Italia, 18/11/2026).
 *
 * La disegna `PageHeaderGuide.vue` al posto della sua riga di sempre, con gli stessi dati: una scheda bianca con l'icona
 * colorata della sezione (la stessa del menu: la pagina si riconosce al volo), il percorso della pagina, il titolo e, a destra,
 * i comandi della pagina, sempre nello stesso posto. L'accento è del colore dello studio.
 *
 * Il condominio, l'esercizio e il ritorno allo studio qui non ci sono: con il menu laterale stanno nella barra in alto, sempre
 * in vista. La testata le passa gli elenchi che la pagina le dà (`percorsoPagina.ts`), così la barra offre le stesse scelte.
 */
import { computed, watchEffect } from 'vue';
import { Link } from '@inertiajs/vue3';
import { ArrowLeft, BookOpen, ChevronDown, ChevronRight, PlayCircle } from 'lucide-vue-next';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuPortal, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import IconaSezione from './IconaSezione.vue';
import { usePercorsoPagina } from './percorsoPagina';
import { useVociMenu } from './vociMenu';
import type { Building } from '@/types/buildings';
import type { Esercizio } from '@/types/gestionale/esercizi';

const props = defineProps<{
    pageTitle: string;
    pageSubtitle?: string;
    videoUrl?: string | null;
    hasTextGuide?: boolean;
    textGuideTitle?: string;
    textGuides?: { id: string; title: string; icon?: string }[];
    breadcrumbs?: { title: string; href?: string }[];
    condominio?: Building;
    condomini?: unknown[];
    esercizio?: Esercizio | null;
    esercizi?: Esercizio[];
    backUrl?: string | null;
    backText?: string;
    /** I riquadri di spiegazione: li disegna `PageHeaderGuide`, sotto la testata. Qui arrivano solo perché la testata riceve i suoi stessi dati. */
    guides?: unknown[];
}>();

defineEmits(['open-text-guide']);

// Che cosa la pagina fa scegliere, detto alla barra in alto.
const percorso = usePercorsoPagina();

// La sezione in cui ci si trova, con la sua icona e il suo colore.
const { corrente } = useVociMenu({ gestionale: percorso?.gestionale ?? !! props.condominio });

watchEffect(() => {
    if (! percorso) {
        return;
    }
    percorso.cambiaCondominioSulPosto = (props.condomini?.length ?? 0) > 1;
    percorso.esercizi = props.esercizi ?? [];
    percorso.esercizio = props.esercizio ?? null;
});

/**
 * L'ultima briciola è la pagina stessa, che ha già il suo titolo: si mostrano quelle che la precedono. Dentro un condominio
 * la prima, «Gestionale», è il condominio, che sta nella barra in alto.
 */
const briciole = computed(() => (props.breadcrumbs ?? []).slice(0, -1).filter((b, i) => ! (percorso?.gestionale && i === 0 && b.title === 'Gestionale')));

const classeComando = 'inline-flex h-8 items-center justify-center gap-2 rounded-lg border px-3 text-xs font-medium shadow-xs transition-colors';
</script>

<template>
    <header class="relative overflow-hidden rounded-xl border border-border bg-card shadow-xs" data-testata-pagina>
        <!-- L'accento del colore dello studio -->
        <div class="pointer-events-none absolute inset-y-0 left-0 w-1 bg-primary" />
        <div class="pointer-events-none absolute inset-0 bg-gradient-to-r from-primary/[0.07] via-primary/[0.02] to-transparent" />

        <div class="relative flex flex-col gap-x-6 gap-y-3 py-4 pl-6 pr-4 md:flex-row md:flex-wrap md:items-center md:justify-between">
            <!-- Sul telefono l'icona sta accanto al titolo e il sottotitolo prende tutta la riga; da `sm` in su l'icona sta accanto a tutti e due. -->
            <div class="grid min-w-0 flex-1 grid-cols-[auto_minmax(0,1fr)] items-center gap-x-3.5 gap-y-2 sm:gap-x-4 sm:gap-y-0 md:min-w-[18rem]">
                <IconaSezione v-if="corrente" :icona="corrente.icona" :colore="corrente.colore" misura="lg" :class="pageSubtitle ? 'sm:row-span-2' : ''" />

                <div class="min-w-0" :class="{ 'col-span-2': ! corrente }">
                    <slot name="breadcrumb">
                        <nav v-if="briciole.length" class="mb-1 flex flex-wrap items-center gap-x-1.5 gap-y-0.5 text-[10px] font-bold uppercase tracking-[0.1em] text-slate-400 dark:text-slate-500" aria-label="Percorso della pagina">
                            <template v-for="(briciola, i) in briciole" :key="i">
                                <ChevronRight v-if="i > 0" class="size-3 shrink-0 opacity-60" />
                                <Link v-if="briciola.href && briciola.href !== '#'" :href="briciola.href" class="whitespace-nowrap transition-colors hover:text-primary">{{ briciola.title }}</Link>
                                <span v-else class="whitespace-nowrap">{{ briciola.title }}</span>
                            </template>
                        </nav>
                    </slot>
                    <h1 class="break-words text-xl font-extrabold leading-tight tracking-tight text-slate-900 dark:text-white sm:text-[1.375rem]">{{ pageTitle }}</h1>
                </div>

                <p v-if="pageSubtitle" class="col-span-2 max-w-3xl text-[13px] leading-relaxed text-slate-500 dark:text-slate-400 sm:mt-0.5" :class="{ 'sm:col-span-1 sm:col-start-2': corrente }">{{ pageSubtitle }}</p>
            </div>

            <!-- I comandi della pagina: sempre qui, a destra del titolo -->
            <div v-if="backUrl || videoUrl || textGuides?.length || hasTextGuide || $slots.actions" class="flex shrink-0 flex-wrap items-center gap-2 md:max-w-full md:justify-end">
                <Link v-if="backUrl" :href="backUrl" :class="[classeComando, 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-300 dark:hover:bg-slate-800']">
                    <ArrowLeft class="size-3.5 text-slate-500" />
                    <span>{{ backText || 'Indietro' }}</span>
                </Link>

                <a v-if="videoUrl" :href="videoUrl" target="_blank" rel="noopener" :class="[classeComando, 'border-red-100 bg-red-50 font-bold text-red-600 hover:bg-red-100 dark:border-red-900/50 dark:bg-red-950/30 dark:text-red-400']">
                    <PlayCircle class="size-3.5" />
                    Video guida
                </a>

                <DropdownMenu v-if="textGuides && textGuides.length > 0">
                    <DropdownMenuTrigger :class="[classeComando, 'border-indigo-100 bg-indigo-50 font-bold text-indigo-600 outline-none hover:bg-indigo-100 dark:border-indigo-900/50 dark:bg-indigo-950/30 dark:text-indigo-400']">
                        <BookOpen class="size-3.5" />
                        Guide
                        <ChevronDown class="size-3.5 opacity-70" />
                    </DropdownMenuTrigger>
                    <DropdownMenuPortal>
                        <DropdownMenuContent align="end" class="min-w-[200px]">
                            <DropdownMenuLabel class="text-xs font-bold uppercase tracking-wider text-slate-500">Guide disponibili</DropdownMenuLabel>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem v-for="guida in textGuides" :key="guida.id" class="cursor-pointer text-sm font-medium" @click="$emit('open-text-guide', guida.id)">
                                {{ guida.title }}
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenuPortal>
                </DropdownMenu>
                <button v-else-if="hasTextGuide" type="button" :class="[classeComando, 'border-indigo-100 bg-indigo-50 font-bold text-indigo-600 hover:bg-indigo-100 dark:border-indigo-900/50 dark:bg-indigo-950/30 dark:text-indigo-400']" @click="$emit('open-text-guide')">
                    <BookOpen class="size-3.5" />
                    {{ textGuideTitle || 'Guida' }}
                </button>

                <slot name="actions" />
            </div>
        </div>
    </header>
</template>
