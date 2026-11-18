<script setup lang="ts">
/**
 * L'impalcatura a menu laterale per le pagine dentro un condominio (Web Pro Italia, 18/11/2026).
 * È la gemella di `GestionaleHeaderLayout.vue`, che resta l'impalcatura con il menu in alto: la scelta fra le due sta in
 * `GestionaleLayout.vue`. I due slot dei selettori di condominio ed esercizio sono gli stessi.
 */
import ImpalcaturaLaterale from '@/components/menu/ImpalcaturaLaterale.vue';
import type { BreadcrumbItemType } from '@/types';

interface Props {
    breadcrumbs?: BreadcrumbItemType[];
}

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});
</script>

<template>
    <ImpalcaturaLaterale :breadcrumbs="breadcrumbs" gestionale>
        <template v-if="$slots['breadcrumb-condominio']" #breadcrumb-condominio>
            <slot name="breadcrumb-condominio" />
        </template>
        <template v-if="$slots['breadcrumb-esercizio']" #breadcrumb-esercizio>
            <slot name="breadcrumb-esercizio" />
        </template>

        <slot />
    </ImpalcaturaLaterale>
</template>
