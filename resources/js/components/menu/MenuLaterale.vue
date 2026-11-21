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
 * (con «Movimenti» aperto il gruppo del condominio scorre) ci si ritroverebbe in cima. Il punto si ricorda per la durata
 * della visita.
 *
 * Che cosa scorre: il solo gruppo del condominio (lo studio sta fermo sopra); sugli schermi molto bassi, tutto l'elenco
 * (`menu-laterale.css`).
 */
const colonna = ref<HTMLElement | null>(null);
const zona = () => colonna.value?.querySelector<HTMLElement>('[data-menu-zona="condominio"]') ?? null;
const elenco = () => {
    const z = zona();

    return z && window.getComputedStyle(z).overflowY !== 'visible' ? z : (colonna.value?.querySelector<HTMLElement>('nav') ?? null);
};
const ricorda = () => {
    scorrimento = elenco()?.scrollTop ?? 0;
    segnaAltroSotto();
};

/** Sotto c'è dell'altro: la zona del condominio sfuma in basso, al posto della barra di scorrimento. */
const segnaAltroSotto = () => {
    const z = zona();
    if (z) {
        z.dataset.altroSotto = z.scrollHeight - z.clientHeight - z.scrollTop > 4 ? 'true' : 'false';
    }
};

/** La voce accesa deve vedersi: se è fuori dallo schermo (una voce in fondo ai Movimenti), l'elenco scorre fino a lei. */
const mostraLaVoceAccesa = (contenitore: HTMLElement) => {
    const testa = contenitore.querySelector<HTMLElement>('[data-menu-testa-condominio]');
    const accesa = [...contenitore.querySelectorAll<HTMLElement>('[data-attiva="true"]')].find((voce) => ! testa?.contains(voce) && voce !== testa);
    if (! accesa) {
        return;
    }
    // La riga di testa del condominio resta in cima mentre il gruppo scorre: una voce lì sotto non si vede.
    const coperto = testa && window.getComputedStyle(testa).position === 'sticky' ? testa.offsetHeight : 0;
    const [voce, visibile] = [accesa.getBoundingClientRect(), contenitore.getBoundingClientRect()];
    // Un po' di margine: si vede anche la voce dopo (o quella prima). Il meno possibile, però: ogni voce che resta dov'era
    // è una voce che si ritrova.
    // In basso il margine conta anche quando la voce «ci sta» di un soffio: l'ultimo tratto dell'elenco sfuma
    // (`data-altro-sotto`), e una voce accesa lì dentro si vede a metà.
    const margine = Math.max(voce.height, 32);
    if (voce.top < visibile.top + coperto) {
        contenitore.scrollTop += voce.top - visibile.top - coperto - margine;
    } else if (voce.bottom > visibile.bottom - margine) {
        contenitore.scrollTop += voce.bottom - visibile.bottom + margine;
    }
};

let osservatore: ResizeObserver | null = null;
let ascoltati: HTMLElement[] = [];

onMounted(() => {
    void nextTick(() => {
        const contenitore = elenco();
        if (! contenitore) {
            return;
        }
        contenitore.scrollTop = scorrimento;
        mostraLaVoceAccesa(contenitore);
        // Può scorrere la zona del condominio o, sugli schermi bassi, tutto l'elenco: si ascoltano tutti e due.
        ascoltati = [zona(), colonna.value?.querySelector<HTMLElement>('nav') ?? null].filter((nodo): nodo is HTMLElement => !! nodo);
        ascoltati.forEach((nodo) => nodo.addEventListener('scroll', ricorda, { passive: true }));
        window.addEventListener('resize', segnaAltroSotto, { passive: true });
        // «Movimenti» che si apre, il gruppo che si ripiega: cambia quanto c'è sotto.
        // Appena disegnata la pagina «Movimenti» si sta ancora aprendo: la voce accesa finisce più in basso di dov'era
        // quando l'abbiamo cercata, e resterebbe mezza nascosta. Finché l'elenco cresce (un secondo al più) la si ricerca;
        // se nel frattempo è la persona a scorrere, si lascia stare.
        const z = zona();
        const nato = Date.now();
        let toccato = false;
        const fermati = () => {
            toccato = true;
        };
        ['wheel', 'touchstart', 'keydown'].forEach((gesto) => contenitore.addEventListener(gesto, fermati, { passive: true, once: true }));
        if (z && typeof ResizeObserver !== 'undefined') {
            osservatore = new ResizeObserver(() => {
                if (! toccato && Date.now() - nato < 1000) {
                    mostraLaVoceAccesa(elenco() ?? contenitore);
                }
                segnaAltroSotto();
            });
            [z, ...z.children].forEach((nodo) => osservatore!.observe(nodo));
        }
        segnaAltroSotto();
    });
});
onBeforeUnmount(() => {
    ascoltati.forEach((nodo) => nodo.removeEventListener('scroll', ricorda));
    window.removeEventListener('resize', segnaAltroSotto);
    osservatore?.disconnect();
});
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
