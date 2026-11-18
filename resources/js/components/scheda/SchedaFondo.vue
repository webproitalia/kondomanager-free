<script setup lang="ts">
/**
 * Sotto la scheda laterale: la pagina da cui si è partiti, ferma.
 *
 * È una copia dei suoi nodi presa un attimo prima di lasciarla (`schedaLaterale.ts`): si vede com'era, allo stesso punto, e
 * non si tocca. La pagina vera torna, com'era, quando la scheda si chiude.
 */
import { onMounted, ref } from 'vue';
import { fondoDellaScheda } from './schedaLaterale';

const contenitore = ref<HTMLElement | null>(null);

onMounted(() => {
    const { nodi, scorrimento, interni } = fondoDellaScheda();
    if (! contenitore.value || ! nodi) {
        return;
    }
    // Passando da una pagina all'altra dentro la scheda la copia è sempre la stessa: si sposta nel contenitore nuovo.
    contenitore.value.appendChild(nodi);
    contenitore.value.scrollTop = scorrimento;
    const tutti = nodi.querySelectorAll<HTMLElement>('*');
    interni.forEach(([i, alto, lato]) => {
        if (tutti[i]) {
            tutti[i].scrollTop = alto;
            tutti[i].scrollLeft = lato;
        }
    });
});
</script>

<template>
    <div ref="contenitore" class="km-scheda-fondo no-print" data-scheda-fondo aria-hidden="true" inert />
</template>
