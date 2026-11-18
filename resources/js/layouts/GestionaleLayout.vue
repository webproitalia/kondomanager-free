<script setup lang="ts">

import GestionaleHeaderLayout from '@/layouts/gestionale/GestionaleHeaderLayout.vue';
import GestionaleMenuLateraleLayout from '@/layouts/gestionale/GestionaleMenuLateraleLayout.vue';
import { useImpalcatura } from '@/composables/useImpalcatura';
import type { BreadcrumbItemType } from '@/types';
import { Toaster } from '@/components/ui/toast'

// Web Pro Italia, 18/11/2026: il menu laterale o, da Impostazioni → Aspetto, quello in alto di sempre.
const AppLayout = useImpalcatura().menuLaterale ? GestionaleMenuLateraleLayout : GestionaleHeaderLayout;

interface Props {
    breadcrumbs?: BreadcrumbItemType[];
}

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        
        <!-- Solo se la pagina li riempie: la barra del menu laterale, quando sono vuoti, mette i suoi selettori. -->
        <template v-if="$slots['breadcrumb-condominio']" #breadcrumb-condominio>
            <slot name="breadcrumb-condominio" />
        </template>
        <template v-if="$slots['breadcrumb-esercizio']" #breadcrumb-esercizio>
            <slot name="breadcrumb-esercizio" />
        </template>

        <slot />
        <Toaster />
    </AppLayout>
</template>
