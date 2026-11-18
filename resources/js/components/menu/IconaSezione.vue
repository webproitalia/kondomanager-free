<script setup lang="ts">
/**
 * L'icona di una sezione: un quadratino arrotondato del colore della sezione, con l'icona bianca.
 * Il colore è sempre lo stesso per la stessa sezione (`COLORI_SEZIONI` in `vociMenu.ts`): è da quello che la si riconosce
 * al volo, nel menu, nella ricerca rapida e in testa alla pagina.
 */
import type { Component } from 'vue';

withDefaults(defineProps<{
    icona: Component;
    colore: string;
    /** `sm` nella ricerca e nelle voci compatte, `md` nel menu, `lg` in testa alla pagina (più piccola sul telefono). */
    misura?: 'sm' | 'md' | 'lg';
}>(), { misura: 'md' });

const riquadro = { sm: 'size-6 rounded-[6px]', md: 'size-7 rounded-[7px]', lg: 'size-9 rounded-[10px] sm:size-11 sm:rounded-xl' };
const disegno = { sm: 'size-3.5', md: 'size-4', lg: 'size-5 sm:size-6' };
</script>

<template>
    <span class="km-icona inline-flex shrink-0 items-center justify-center" :class="riquadro[misura]" :style="{ '--c': colore }" aria-hidden="true">
        <component :is="icona" :class="disegno[misura]" :stroke-width="misura === 'lg' ? 1.9 : 2.1" />
    </span>
</template>
