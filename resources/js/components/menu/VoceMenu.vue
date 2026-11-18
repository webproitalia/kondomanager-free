<script setup lang="ts">
/**
 * Una voce del menu laterale: l'icona nel suo quadratino colorato e il nome breve; con il menu chiuso solo l'icona, e il nome
 * intero al passaggio del mouse. Una voce `figlia` (quelle dentro «Movimenti») è più bassa e ha l'icona piccola, senza riquadro.
 */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { ArrowUpRight } from 'lucide-vue-next';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import IconaSezione from './IconaSezione.vue';
import type { VoceMenu } from './vociMenu';

const props = defineProps<{
    voce: VoceMenu;
    /** Il menu è chiuso: solo l'icona, con il nome al passaggio del mouse. */
    soloIcona?: boolean;
    figlia?: boolean;
}>();

const attributi = computed(() => ({
    href: props.voce.href,
    ...(props.voce.esterna ? { target: '_blank', rel: 'noopener' } : {}),
    'data-menu-voce': props.voce.chiave,
    'data-attiva': props.voce.attiva ? 'true' : 'false',
    'aria-current': props.voce.attiva ? ('page' as const) : undefined,
    'aria-disabled': props.voce.spenta ? ('true' as const) : undefined,
    tabindex: props.voce.spenta ? -1 : undefined,
}));

/** Con il menu aperto il nome intero si legge al passaggio del mouse solo dove è diverso da quello breve. */
const titoloAlPassaggio = computed(() => {
    const intero = props.voce.nota ? `${props.voce.titolo} — ${props.voce.nota}` : props.voce.titolo;

    return intero !== props.voce.nome ? intero : undefined;
});
</script>

<template>
    <!-- Il menu chiuso: l'icona, e il nome intero accanto al passaggio del mouse -->
    <Tooltip v-if="soloIcona" :delay-duration="0">
        <TooltipTrigger as-child>
            <component :is="voce.esterna ? 'a' : Link" v-bind="attributi" class="km-voce relative flex size-10 items-center justify-center rounded-lg" :aria-label="voce.titolo">
                <IconaSezione :icona="voce.icona" :colore="voce.colore" />
                <span v-if="voce.contatore" class="absolute -right-0.5 -top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-amber-400 px-1 text-[10px] font-bold leading-none text-amber-950 tabular-nums">{{ voce.contatore }}</span>
                <span v-else-if="voce.avviso" class="absolute right-1 top-1 size-2 rounded-full bg-orange-400 ring-2 ring-[var(--km-menu-fondo)]" />
            </component>
        </TooltipTrigger>
        <TooltipContent side="right" :side-offset="10">
            {{ voce.nome }}<span v-if="titoloAlPassaggio" class="ml-1.5 opacity-60">{{ titoloAlPassaggio }}</span>
        </TooltipContent>
    </Tooltip>

    <!-- Una voce dentro un'altra -->
    <component :is="voce.esterna ? 'a' : Link" v-else-if="figlia" v-bind="attributi" :title="titoloAlPassaggio" class="km-voce flex h-8 items-center gap-2.5 rounded-md px-2 text-[13px]">
        <component :is="voce.icona" class="size-3.5 shrink-0 opacity-70" />
        <span class="truncate">{{ voce.nome }}</span>
    </component>

    <!-- Il menu aperto -->
    <component :is="voce.esterna ? 'a' : Link" v-else v-bind="attributi" :title="titoloAlPassaggio" class="km-voce flex h-9 items-center gap-2.5 rounded-lg px-1.5 text-[13.5px] font-medium">
        <IconaSezione :icona="voce.icona" :colore="voce.colore" />
        <span class="truncate">{{ voce.nome }}</span>
        <span v-if="voce.contatore" class="ml-auto rounded-full bg-amber-400/20 px-1.5 py-px text-[11px] font-semibold text-amber-200 tabular-nums" :title="`${voce.contatore} in attesa`">{{ voce.contatore }}</span>
        <span v-else-if="voce.avviso" class="ml-auto mr-1 size-2 rounded-full bg-orange-400" title="Aggiornamento disponibile" />
        <ArrowUpRight v-else-if="voce.esterna" class="ml-auto mr-0.5 size-3.5 opacity-50" />
    </component>
</template>
