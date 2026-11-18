<script setup lang="ts">
/**
 * Una voce del menu laterale: l'icona nel suo quadratino colorato e il nome breve; con il menu chiuso solo l'icona, e il nome
 * intero al passaggio del mouse.
 *
 * I livelli: le voci dello studio (1), le sezioni del condominio (2: rientrate, con l'icona un po' più piccola), le voci
 * dentro «Movimenti» (`figlia`: un rientro in più, l'icona senza riquadro).
 *
 * Una voce `daScegliere` (non c'è un condominio in uso) resta al suo posto, più tenue: è un pulsante che chiede di scegliere
 * il condominio (`scegli`), invece di un collegamento.
 */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { ArrowUpRight } from 'lucide-vue-next';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import IconaSezione from './IconaSezione.vue';
import type { VoceMenu } from './vociMenu';

const props = withDefaults(defineProps<{
    voce: VoceMenu;
    /** Il menu è chiuso: solo l'icona, con il nome al passaggio del mouse. */
    soloIcona?: boolean;
    livello?: 1 | 2;
    figlia?: boolean;
}>(), { livello: 1 });

const emit = defineEmits<{ scegli: [voce: VoceMenu] }>();

/** Un collegamento della pagina, uno verso l'esterno, o — senza un condominio in uso — un pulsante che lo fa scegliere. */
const elemento = computed(() => (props.voce.daScegliere ? 'button' : props.voce.esterna ? 'a' : Link));

const attributi = computed(() => ({
    ...(props.voce.daScegliere ? { type: 'button' as const, 'data-da-scegliere': '' } : { href: props.voce.href }),
    ...(props.voce.esterna ? { target: '_blank', rel: 'noopener' } : {}),
    'data-menu-voce': props.voce.chiave,
    'data-attiva': props.voce.attiva ? 'true' : 'false',
    'aria-current': props.voce.attiva ? ('page' as const) : undefined,
    'aria-disabled': props.voce.spenta ? ('true' as const) : undefined,
    tabindex: props.voce.spenta ? -1 : undefined,
}));

const scegli = () => {
    if (props.voce.daScegliere) {
        emit('scegli', props.voce);
    }
};

/** Con il menu aperto il nome intero si legge al passaggio del mouse solo dove è diverso da quello breve. */
const titoloAlPassaggio = computed(() => {
    if (props.voce.daScegliere) {
        return `${props.voce.titolo}: scegli prima un condominio`;
    }
    const intero = props.voce.nota ? `${props.voce.titolo} — ${props.voce.nota}` : props.voce.titolo;

    return intero !== props.voce.nome ? intero : undefined;
});
</script>

<template>
    <!-- Il menu chiuso: l'icona, e il nome intero accanto al passaggio del mouse -->
    <Tooltip v-if="soloIcona" :delay-duration="0">
        <TooltipTrigger as-child>
            <component :is="elemento" v-bind="attributi" class="km-voce km-riga relative flex w-10 items-center justify-center rounded-lg" :aria-label="voce.titolo" @click="scegli">
                <!-- La voce accesa: oltre al fondo, una tacca chiara sul bordo del menu -->
                <span v-if="voce.attiva" class="km-tacca" />
                <IconaSezione :icona="voce.icona" :colore="voce.colore" misura="voce" />
                <span v-if="voce.contatore" class="absolute -right-0.5 -top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-amber-400 px-1 text-[10px] font-bold leading-none text-amber-950 tabular-nums">{{ voce.contatore }}</span>
                <span v-else-if="voce.avviso" class="absolute right-1 top-1 size-2 rounded-full bg-orange-400 ring-2 ring-[var(--km-menu-fondo)]" />
            </component>
        </TooltipTrigger>
        <TooltipContent side="right" :side-offset="10">
            {{ voce.nome }}<span v-if="titoloAlPassaggio" class="ml-1.5 opacity-60">{{ titoloAlPassaggio }}</span>
        </TooltipContent>
    </Tooltip>

    <!-- Una voce dentro un'altra (terzo livello) -->
    <component :is="elemento" v-else-if="figlia" v-bind="attributi" :title="titoloAlPassaggio" class="km-voce km-riga-figlia flex w-full items-center gap-2.5 rounded-md px-2 text-left text-[12.5px]" @click="scegli">
        <component :is="voce.icona" class="size-3.5 shrink-0 opacity-70" />
        <span class="truncate">{{ voce.nome }}</span>
    </component>

    <!-- Il menu aperto -->
    <component
        :is="elemento"
        v-else
        v-bind="attributi"
        :title="titoloAlPassaggio"
        class="km-voce km-riga flex w-full items-center rounded-lg px-1.5 text-left font-medium"
        :class="livello === 2 ? 'gap-2.5 text-[13px]' : 'gap-2.5 text-[13.5px]'"
        @click="scegli"
    >
        <IconaSezione :icona="voce.icona" :colore="voce.colore" :misura="livello === 2 ? 'voce2' : 'voce'" />
        <span class="truncate">{{ voce.nome }}</span>
        <span v-if="voce.contatore" class="ml-auto rounded-full bg-amber-400/20 px-1.5 py-px text-[11px] font-semibold text-amber-200 tabular-nums" :title="`${voce.contatore} in attesa`">{{ voce.contatore }}</span>
        <span v-else-if="voce.avviso" class="ml-auto mr-1 size-2 rounded-full bg-orange-400" title="Aggiornamento disponibile" />
        <ArrowUpRight v-else-if="voce.esterna" class="ml-auto mr-0.5 size-3.5 opacity-50" />
    </component>
</template>
