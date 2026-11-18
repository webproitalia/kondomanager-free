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
 */
import { ref } from 'vue';
import Footer from '@/components/Footer.vue';
import { SidebarProvider } from '@/components/ui/sidebar';
import BarraSuperiore from './BarraSuperiore.vue';
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
</script>

<template>
    <SidebarProvider :open="aperto" class="km-impalcatura" @update:open="cambia">
        <MenuLaterale :gestionale="gestionale" />

        <div class="km-lavoro flex min-h-svh min-w-0 flex-1 flex-col">
            <BarraSuperiore :breadcrumbs="breadcrumbs" :gestionale="gestionale">
                <template v-if="$slots['breadcrumb-condominio']" #breadcrumb-condominio><slot name="breadcrumb-condominio" /></template>
                <template v-if="$slots['breadcrumb-esercizio']" #breadcrumb-esercizio><slot name="breadcrumb-esercizio" /></template>
            </BarraSuperiore>

            <main class="mx-auto flex w-full max-w-[96rem] flex-1 flex-col">
                <slot />
            </main>

            <Footer />
        </div>
    </SidebarProvider>
</template>
