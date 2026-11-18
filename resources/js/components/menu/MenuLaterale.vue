<script setup lang="ts">
/**
 * Il menu laterale richiudibile (Web Pro Italia, 18/11/2026).
 *
 * Aperto mostra icona e nome; chiuso solo le icone, con il nome al passaggio del mouse; sugli schermi stretti è un cassetto
 * che si apre dal tasto della barra. Lo stato (aperto o chiuso) lo tiene `SidebarProvider`, quello del kit di partenza, e lo
 * ricorda l'impalcatura (`ImpalcaturaLaterale.vue`).
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';
import { Sheet, SheetContent, SheetDescription, SheetTitle } from '@/components/ui/sheet';
import { useSidebar } from '@/components/ui/sidebar';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import MenuContenuto from './MenuContenuto.vue';
import { scorciatoia } from './tasti';

defineProps<{ gestionale?: boolean }>();

const { isMobile, state, openMobile, setOpenMobile, toggleSidebar } = useSidebar();
const chiuso = computed(() => state.value === 'collapsed');
const comando = computed(() => (chiuso.value ? 'Apri il menu' : 'Richiudi il menu'));

/*
 * L'impalcatura si ridisegna a ogni pagina, e con lei il menu: senza questo, dopo un clic su una voce in fondo all'elenco
 * (con «Movimenti» aperto il menu scorre) ci si ritroverebbe in cima. Il punto si ricorda per la durata della visita.
 */
const colonna = ref<HTMLElement | null>(null);
const elenco = () => colonna.value?.querySelector<HTMLElement>('nav') ?? null;
const ricorda = () => {
    scorrimento = elenco()?.scrollTop ?? 0;
};

onMounted(() => {
    void nextTick(() => {
        const nav = elenco();
        if (nav) {
            nav.scrollTop = scorrimento;
            // La voce accesa deve vedersi: se è fuori dallo schermo (una voce in fondo ai Movimenti), il menu scorre fino a lei.
            const accesa = nav.querySelector<HTMLElement>('[data-attiva="true"]');
            if (accesa) {
                const [voce, elencoVisibile] = [accesa.getBoundingClientRect(), nav.getBoundingClientRect()];
                if (voce.top < elencoVisibile.top || voce.bottom > elencoVisibile.bottom) {
                    nav.scrollTop += voce.top - elencoVisibile.top - (elencoVisibile.height - voce.height) / 2;
                }
            }
            nav.addEventListener('scroll', ricorda, { passive: true });
        }
    });
});
onBeforeUnmount(() => elenco()?.removeEventListener('scroll', ricorda));
</script>

<script lang="ts">
let scorrimento = 0;
</script>

<template>
    <!-- Schermi stretti: un cassetto -->
    <Sheet v-if="isMobile" :open="openMobile" @update:open="setOpenMobile">
        <SheetContent side="left" class="km-menu w-[17.5rem] max-w-[86vw] gap-0 border-0 p-0 [&>button]:hidden" data-menu-laterale data-menu-cassetto>
            <SheetTitle class="sr-only">Menu</SheetTitle>
            <SheetDescription class="sr-only">Le sezioni del gestionale.</SheetDescription>
            <MenuContenuto :gestionale="gestionale" />
        </SheetContent>
    </Sheet>

    <!--
        La colonna: aperta, o chiusa a sole icone.
        Si richiude e si riapre da quattro punti, perché il comando si trovi dove lo si cerca: la freccia accanto al nome
        (o sotto il marchio, a menu chiuso), la linguetta a metà del bordo, il bordo stesso, e il tasto della barra in alto.
    -->
    <aside
        v-else
        ref="colonna"
        class="km-menu no-print sticky top-0 z-40 hidden h-svh shrink-0 transition-[width] duration-200 ease-out md:block print:hidden"
        :class="chiuso ? 'w-[3.75rem]' : 'w-60'"
        data-menu-laterale
        :data-stato="chiuso ? 'chiuso' : 'aperto'"
    >
        <div class="flex h-full flex-col overflow-hidden">
            <MenuContenuto :gestionale="gestionale" :chiuso="chiuso" />
        </div>

        <!-- Il bordo: tutto cliccabile, con un filo che si accende al passaggio del mouse -->
        <button type="button" class="km-menu-bordo" :class="chiuso ? 'cursor-e-resize' : 'cursor-w-resize'" tabindex="-1" :aria-label="comando" :title="`${comando} (${scorciatoia('B')})`" data-menu-bordo @click="toggleSidebar" />

        <!-- La linguetta a metà altezza: la freccia dice da che parte va il menu -->
        <Tooltip :delay-duration="150">
            <TooltipTrigger as-child>
                <button type="button" class="km-menu-linguetta" :aria-label="comando" data-menu-linguetta @click="toggleSidebar">
                    <ChevronRight v-if="chiuso" class="size-3.5" :stroke-width="2.6" />
                    <ChevronLeft v-else class="size-3.5" :stroke-width="2.6" />
                </button>
            </TooltipTrigger>
            <TooltipContent side="right" :side-offset="8">{{ comando }}<span class="ml-1.5 opacity-60">{{ scorciatoia('B') }}</span></TooltipContent>
        </Tooltip>
    </aside>
</template>
