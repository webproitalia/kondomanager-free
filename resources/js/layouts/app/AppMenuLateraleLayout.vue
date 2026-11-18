<script setup lang="ts">
/**
 * L'impalcatura a menu laterale per le pagine dello studio (Web Pro Italia, 18/11/2026).
 * È la gemella di `AppHeaderLayout.vue`, che resta l'impalcatura con il menu in alto: la scelta fra le due sta in `AppLayout.vue`.
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
    <ImpalcaturaLaterale :breadcrumbs="breadcrumbs">
        <slot />
    </ImpalcaturaLaterale>
</template>
