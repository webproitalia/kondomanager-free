<script setup lang="ts">
/**
 * La testata della scheda laterale: la sezione a cui appartiene la pagina, «Apri a pagina intera» e «Chiudi».
 * Il titolo e i comandi della pagina restano i suoi, subito sotto.
 */
import { Maximize2, X } from 'lucide-vue-next';
import IconaSezione from '@/components/menu/IconaSezione.vue';
import { useVociMenu } from '@/components/menu/vociMenu';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';

const props = defineProps<{ gestionale?: boolean }>();
defineEmits<{ chiudi: []; 'pagina-intera': [] }>();

const { corrente, gruppoCondominio } = useVociMenu({ gestionale: props.gestionale });

const classeTasto = 'inline-flex h-8 items-center justify-center gap-1.5 rounded-md px-2 text-xs font-medium text-muted-foreground outline-none transition-colors hover:bg-accent hover:text-accent-foreground focus-visible:ring-2 focus-visible:ring-ring/50';
</script>

<template>
    <div class="km-scheda-testata" data-scheda-testata>
        <div class="flex min-w-0 items-center gap-2.5">
            <IconaSezione v-if="corrente" :icona="corrente.icona" :colore="corrente.colore" misura="sm" />
            <span class="truncate text-sm font-semibold text-foreground">{{ corrente?.nome ?? 'Scheda' }}</span>
            <span v-if="gruppoCondominio" class="hidden truncate text-xs text-muted-foreground sm:inline">{{ gruppoCondominio.nome }}</span>
        </div>

        <div class="ml-auto flex shrink-0 items-center gap-1">
            <Tooltip :delay-duration="300">
                <TooltipTrigger as-child>
                    <button type="button" :class="classeTasto" data-scheda-pagina-intera @click="$emit('pagina-intera')">
                        <Maximize2 class="size-3.5" />
                        <span class="hidden sm:inline">Apri a pagina intera</span>
                    </button>
                </TooltipTrigger>
                <TooltipContent side="bottom">La stessa pagina, senza la scheda</TooltipContent>
            </Tooltip>
            <Tooltip :delay-duration="300">
                <TooltipTrigger as-child>
                    <button type="button" :class="[classeTasto, 'w-8 px-0']" aria-label="Chiudi la scheda" data-scheda-chiudi @click="$emit('chiudi')">
                        <X class="size-4" />
                    </button>
                </TooltipTrigger>
                <TooltipContent side="bottom">Chiudi <span class="ml-1 opacity-60">Esc</span></TooltipContent>
            </Tooltip>
        </div>
    </div>
</template>
