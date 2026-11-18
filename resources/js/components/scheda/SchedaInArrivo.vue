<script setup lang="ts">
/**
 * Il pannello della scheda laterale mentre la pagina arriva, o mentre se ne va da sola.
 *
 * «In arrivo»: appena si clicca, il pannello entra da destra con una sagoma del contenuto, senza aspettare il server; quando
 * la pagina arriva prende il suo posto, nello stesso punto. «In uscita»: un salvataggio ha riportato all'elenco, e il pannello
 * vuoto scivola via invece di sparire di colpo.
 */
defineProps<{ uscita?: boolean }>();
</script>

<template>
    <div class="no-print" data-scheda-in-arrivo aria-hidden="true">
        <div class="km-scheda-velo" :data-fase="uscita ? 'chiusura' : 'apertura'" />
        <div class="km-lavoro km-scheda" :data-fase="uscita ? 'chiusura' : 'apertura'">
            <div class="km-scheda-testata" />
            <div v-if="! uscita" class="animate-pulse space-y-5 px-6 py-8">
                <div class="h-24 rounded-xl bg-white/70 dark:bg-white/5" />
                <div class="h-10 w-2/3 rounded-lg bg-white/70 dark:bg-white/5" />
                <div class="h-64 rounded-xl bg-white/70 dark:bg-white/5" />
            </div>
        </div>
    </div>
</template>
