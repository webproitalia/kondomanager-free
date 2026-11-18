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
    /**
     * `voce` e `voce2` nel menu (le voci dello studio, e un po' più piccole le sezioni del condominio, che stanno un livello
     * sotto: la misura segue l'altezza delle righe, vedi `--km-tessera` in `menu-laterale.css`); `sm` nella ricerca; `md` dove
     * serve una misura fissa; `lg` in testa alla pagina (più piccola sul telefono).
     */
    misura?: 'voce' | 'voce2' | 'sm' | 'md' | 'lg';
}>(), { misura: 'md' });

const riquadro = { voce: 'km-icona-voce', voce2: 'km-icona-voce2', sm: 'size-6 rounded-[6px]', md: 'size-7 rounded-[7px]', lg: 'size-9 rounded-[10px] sm:size-11 sm:rounded-xl' };
const disegno = { voce: 'size-[60%]', voce2: 'size-[60%]', sm: 'size-3.5', md: 'size-4', lg: 'size-5 sm:size-6' };
</script>

<template>
    <span class="km-icona inline-flex shrink-0 items-center justify-center" :class="riquadro[misura]" :style="{ '--c': colore }" aria-hidden="true">
        <component :is="icona" :class="disegno[misura]" :stroke-width="misura === 'lg' ? 1.9 : 2.1" />
    </span>
</template>
