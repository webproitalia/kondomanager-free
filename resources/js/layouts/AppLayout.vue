<script setup lang="ts">
import AppHeaderLayout from '@/layouts/app/AppHeaderLayout.vue';
import AppMenuLateraleLayout from '@/layouts/app/AppMenuLateraleLayout.vue';
import { useImpalcatura } from '@/composables/useImpalcatura';
import type { BreadcrumbItemType } from '@/types';
import { Toaster } from '@/components/ui/toast'

// Web Pro Italia, 18/11/2026: il menu laterale o, da Impostazioni → Aspetto, quello in alto di sempre.
const AppLayout = useImpalcatura().menuLaterale ? AppMenuLateraleLayout : AppHeaderLayout;

interface Props {
    breadcrumbs?: BreadcrumbItemType[];
}

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <slot />
        <Toaster />
    </AppLayout>
</template>
