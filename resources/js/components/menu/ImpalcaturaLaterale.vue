<script setup lang="ts">
/**
 * L'impalcatura a menu laterale (Web Pro Italia, 18/11/2026): il menu a sinistra, la barra in alto con il percorso e la
 * ricerca, e l'area di lavoro leggermente grigia in cui le schede bianche risaltano.
 *
 * È la stessa per lo studio e per il gestionale di un condominio (`gestionale`): la usano `layouts/app/AppMenuLateraleLayout.vue`
 * e `layouts/gestionale/GestionaleMenuLateraleLayout.vue`. L'altra impalcatura, con il menu in alto, resta com'era
 * (`AppHeaderLayout.vue`, `GestionaleHeaderLayout.vue`): si sceglie in Impostazioni → Aspetto.
 *
 * La pagina scorre con la finestra, come con il menu in alto: le pagine che tornano in cima o che misurano l'altezza dello
 * schermo continuano a funzionare. `--km-barra` è l'altezza della barra fissa: chi resta agganciato in cima alla pagina
 * (i conteggi dell'assemblea) si ferma sotto di lei.
 *
 * ## La scheda laterale
 *
 * La stessa impalcatura disegna la pagina **nella scheda laterale** quando ci si è arrivati da un'altra pagina e l'indirizzo è
 * di quelli che si aprono di lato (`components/scheda/schedaLaterale.ts`): niente menu e niente barra, la pagina in un
 * pannello a destra con la sua testata, e sotto, ferma, la pagina da cui si è partiti.
 *
 * ⚠️ La pagina (`<slot />`) sta sempre nello stesso `<main>`, a pagina intera e nella scheda: cambiano le classi intorno, non
 * la struttura. Così «Apri a pagina intera» toglie la scheda senza ridisegnare la pagina, e un modulo mezzo compilato resta
 * com'è.
 */
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import Footer from '@/components/Footer.vue';
import SchedaFondo from '@/components/scheda/SchedaFondo.vue';
import SchedaInArrivo from '@/components/scheda/SchedaInArrivo.vue';
import SchedaTestata from '@/components/scheda/SchedaTestata.vue';
import { avviaSchedaLaterale, useSchedaLaterale } from '@/components/scheda/schedaLaterale';
import { AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent, AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle } from '@/components/ui/alert-dialog';
import { SidebarProvider } from '@/components/ui/sidebar';
import { useImpalcatura } from '@/composables/useImpalcatura';
import BarraStudio from './BarraStudio.vue';
import BarraSuperiore from './BarraSuperiore.vue';
import PercorsoStudio from './PercorsoStudio.vue';
import { ascoltaCondominioInUso, useCondominioInUso } from './condominioInUso';
import MenuLaterale from './MenuLaterale.vue';
import { offriPercorsoPagina } from './percorsoPagina';
import type { BreadcrumbItemType } from '@/types';

const props = withDefaults(defineProps<{
    breadcrumbs?: BreadcrumbItemType[];
    /** Dentro un condominio: il menu ha il gruppo del condominio, la barra i selettori di condominio ed esercizio. */
    gestionale?: boolean;
}>(), { breadcrumbs: () => [] });

// La testata della pagina dice alla barra che cosa la pagina fa scegliere (`percorsoPagina.ts`).
offriPercorsoPagina(props.gestionale);

// Dentro un condominio: è quello in uso, e il menu se lo ricorda anche nelle pagine dello studio (`condominioInUso.ts`).
// Lo si ricorda quando la pagina lo porta, non quando il ricordo cambia: «Chiudi il condominio», da dentro una sua pagina, lo
// toglie, e non deve tornare da solo prima che si arrivi alla dashboard.
const page = usePage();
const { ricorda } = useCondominioInUso();
ascoltaCondominioInUso();
watch(() => [props.gestionale, page.props.condominio, page.props.esercizio] as const, ([gestionale, delCondominio, dellEsercizio]) => {
    const condominio = delCondominio as { id?: number; nome?: string } | undefined;
    if (! gestionale || ! condominio?.id || ! condominio.nome) {
        return;
    }
    const esercizio = dellEsercizio as { id?: number; nome?: string } | null | undefined;
    ricorda({ id: condominio.id, nome: condominio.nome, esercizio: esercizio?.id ? { id: esercizio.id, nome: esercizio.nome ?? null } : null });
}, { immediate: true });

/*
 * La navigazione «Barra in alto» (Impostazioni → Aspetto): in alto, sempre, la barra dello studio (`BarraStudio.vue`); il
 * menu laterale c'è solo dentro un condominio, con le sue sole sezioni, sotto la barra. Il percorso «Studio › condominio ›
 * esercizio», con i suoi due selettori, sta in una riga sottile sopra la pagina, e solo dentro un condominio.
 */
const { barraInAlto } = useImpalcatura();

const CHIAVE = 'km-menu-aperto';

/** Come lo si è lasciato; la prima volta aperto dove c'è spazio e a sole icone sugli schermi piccoli. */
const ricordato = (): boolean => {
    try {
        const valore = localStorage.getItem(CHIAVE);
        if (valore !== null) {
            return valore !== 'false';
        }
    } catch {
        // Senza memoria del browser: come la prima volta.
    }

    return typeof window === 'undefined' || window.innerWidth >= 1280;
};

const aperto = ref(ricordato());

const cambia = (valore: boolean) => {
    aperto.value = valore;
    try {
        localStorage.setItem(CHIAVE, String(valore));
    } catch {
        // Il menu si apre e si chiude lo stesso: non si ricorda e basta.
    }
};

// ─── La scheda laterale ──────────────────────────────────────────────────────────────────────────────────────────────────
avviaSchedaLaterale();
const { stato: scheda, inScheda, inArrivo, chiudi, apriAPaginaIntera, resta, segnaModificato } = useSchedaLaterale();

/**
 * Un campo nella scheda è stato toccato: chiudendo si chiede. Vale per i campi di un modulo — dentro un `<form>`, o in una
 * pagina che è un modulo (`…/create`, `…/edit`: non tutte usano un `<form>`) — e non per la ricerca di un elenco.
 */
const toccato = (evento: Event) => {
    if (! inScheda.value) {
        return;
    }
    if ((evento.target as HTMLElement | null)?.closest?.('form') || /\/(create|edit)$/.test(window.location.pathname)) {
        segnaModificato();
    }
};

/** Esc chiude la scheda, se non c'è niente di aperto sopra (una finestra, una tendina, un elenco a comparsa). */
const tasto = (evento: KeyboardEvent) => {
    if (evento.key !== 'Escape' || ! inScheda.value || evento.defaultPrevented || scheda.daConfermare) {
        return;
    }
    if (document.querySelector('[role="dialog"][data-state="open"], [role="alertdialog"][data-state="open"], [data-reka-popper-content-wrapper], .vs--open, .dp__menu')) {
        return;
    }
    // Dopo che il tasto ha finito il suo giro: se la chiusura apre la domanda «chiudere senza salvare?», la finestra appena
    // nata sentirebbe questo stesso Esc (lo ascolta più in alto) e si richiuderebbe subito.
    window.setTimeout(() => chiudi(), 0);
};

onMounted(() => document.addEventListener('keydown', tasto));
onBeforeUnmount(() => document.removeEventListener('keydown', tasto));
</script>

<template>
    <SidebarProvider :open="aperto" class="km-impalcatura" :class="{ 'km-con-barra': barraInAlto }" :data-scheda="scheda.fase" @update:open="cambia">
        <!-- Nella scheda laterale: sotto, ferma, la pagina da cui si è partiti; un velo, che al clic chiude -->
        <template v-if="inScheda">
            <SchedaFondo />
            <div class="km-scheda-velo no-print" :data-fase="scheda.fase === 'chiusura' ? 'chiusura' : 'aperta'" data-scheda-velo @click="chiudi()" />
        </template>

        <BarraStudio v-if="barraInAlto && ! inScheda" :gestionale="gestionale" />

        <MenuLaterale v-if="! inScheda" :gestionale="gestionale" :barra="barraInAlto" />

        <div
            class="km-lavoro min-w-0"
            :class="inScheda ? 'km-scheda' : 'flex min-h-svh flex-1 flex-col'"
            :data-scheda-laterale="inScheda ? '' : undefined"
            :data-fase="inScheda ? (scheda.fase === 'chiusura' ? 'chiusura' : 'aperta') : undefined"
            :role="inScheda ? 'dialog' : undefined"
            :aria-modal="inScheda ? 'true' : undefined"
            :aria-label="inScheda ? 'Scheda' : undefined"
            @input="toccato"
            @change="toccato"
        >
            <SchedaTestata v-if="inScheda" :gestionale="gestionale" @chiudi="chiudi()" @pagina-intera="apriAPaginaIntera()" />
            <BarraSuperiore v-else-if="! barraInAlto" :breadcrumbs="breadcrumbs" :gestionale="gestionale">
                <template v-if="$slots['breadcrumb-condominio']" #breadcrumb-condominio><slot name="breadcrumb-condominio" /></template>
                <template v-if="$slots['breadcrumb-esercizio']" #breadcrumb-esercizio><slot name="breadcrumb-esercizio" /></template>
            </BarraSuperiore>
            <!-- Con la barra in alto: dentro un condominio, il percorso con i selettori di condominio ed esercizio -->
            <div v-else-if="gestionale" class="km-percorso-riga no-print print:hidden" data-percorso-riga>
                <PercorsoStudio :breadcrumbs="breadcrumbs" :gestionale="gestionale" class="flex-1">
                    <template v-if="$slots['breadcrumb-condominio']" #breadcrumb-condominio><slot name="breadcrumb-condominio" /></template>
                    <template v-if="$slots['breadcrumb-esercizio']" #breadcrumb-esercizio><slot name="breadcrumb-esercizio" /></template>
                </PercorsoStudio>
            </div>

            <main class="mx-auto flex w-full max-w-[96rem] flex-1 flex-col">
                <slot />
            </main>

            <Footer v-if="! inScheda" />
        </div>

        <!-- La scheda che sta arrivando (il pannello entra subito, la pagina lo riempie appena c'è) o che è appena uscita da sola -->
        <SchedaInArrivo v-if="inArrivo" />
        <SchedaInArrivo v-else-if="scheda.uscita && ! inScheda" uscita />

        <!-- Chiudere con un modulo mezzo compilato: prima si chiede -->
        <AlertDialog :open="scheda.daConfermare" @update:open="(aperta: boolean) => ! aperta && resta()">
            <AlertDialogContent>
                <AlertDialogHeader data-scheda-conferma>
                    <AlertDialogTitle>Chiudere senza salvare?</AlertDialogTitle>
                    <AlertDialogDescription>In questa scheda ci sono modifiche che non hai salvato. Se chiudi adesso vanno perse.</AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel @click="resta()">Resta nella scheda</AlertDialogCancel>
                    <AlertDialogAction class="bg-destructive text-white hover:bg-destructive/90" @click="chiudi({ senzaChiedere: true })">Chiudi senza salvare</AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    </SidebarProvider>
</template>
