<script setup lang="ts">
/**
 * Una voce che si apre («Movimenti», con dentro le voci del menu dei movimenti).
 *
 * Con il menu aperto è un cassettino: un clic lo apre e lo chiude, e resta aperto da solo quando si è in una delle sue pagine.
 * Con il menu chiuso è un'icona che apre l'elenco accanto. Senza un condominio in uso è una voce da scegliere, come le altre.
 */
import { ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import { ChevronRight } from 'lucide-vue-next';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import IconaSezione from './IconaSezione.vue';
import VoceMenuRiga from './VoceMenu.vue';
import type { VoceMenu } from './vociMenu';

const props = withDefaults(defineProps<{
    voce: VoceMenu;
    soloIcona?: boolean;
    livello?: 1 | 2;
}>(), { livello: 1 });

defineEmits<{ scegli: [voce: VoceMenu] }>();

const CHIAVE = `km-menu-aperta-${props.voce.chiave}`;
const ricordata = () => {
    try {
        return localStorage.getItem(CHIAVE);
    } catch {
        return null;
    }
};

// Aperta se si è in una delle sue pagine; altrimenti come la si è lasciata.
const aperta = ref(props.voce.attiva || ricordata() === 'true');

watch(() => props.voce.attiva, (attiva) => {
    if (attiva) {
        aperta.value = true;
    }
});

const ricorda = (valore: boolean) => {
    aperta.value = valore;
    try {
        localStorage.setItem(CHIAVE, String(valore));
    } catch {
        // Senza memoria del browser la voce si apre e si chiude lo stesso: non si ricorda e basta.
    }
};
</script>

<template>
    <!-- Senza un condominio in uso: una voce da scegliere, al suo posto -->
    <VoceMenuRiga v-if="voce.daScegliere" :voce="voce" :solo-icona="soloIcona" :livello="livello" @scegli="$emit('scegli', $event)" />

    <!--
        Il menu chiuso: l'icona apre l'elenco accanto, e al passaggio del mouse dice il nome.
        L'elenco sta DENTRO il suggerimento, non il contrario: tutti e due si agganciano al pulsante, e con l'elenco fuori il
        pulsante si agganciava al solo suggerimento (il più vicino), lasciando l'elenco senza un posto dove comparire.
    -->
    <Tooltip v-else-if="soloIcona" :delay-duration="0">
        <TooltipTrigger as-child>
            <span class="flex">
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <button type="button" class="km-voce km-riga relative flex w-10 items-center justify-center rounded-lg" :data-menu-voce="voce.chiave" :data-attiva="voce.attiva ? 'true' : 'false'" :aria-label="voce.titolo">
                            <span v-if="voce.attiva" class="km-tacca" />
                            <IconaSezione :icona="voce.icona" :colore="voce.colore" misura="voce" />
                        </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent side="right" align="start" :side-offset="10" class="w-56">
                        <DropdownMenuLabel class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">{{ voce.titolo }}</DropdownMenuLabel>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem v-for="figlia in voce.figlie" :key="figlia.chiave" as-child :disabled="figlia.spenta" :class="{ 'bg-accent text-accent-foreground font-medium': figlia.attiva }">
                            <Link :href="figlia.href" class="flex w-full cursor-pointer items-center gap-2">
                                <component :is="figlia.icona" class="size-4 opacity-70" />
                                <span class="truncate">{{ figlia.titolo }}</span>
                            </Link>
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </span>
        </TooltipTrigger>
        <TooltipContent side="right" :side-offset="10">{{ voce.nome }}</TooltipContent>
    </Tooltip>

    <!-- Il menu aperto: un cassettino -->
    <Collapsible v-else :open="aperta" @update:open="ricorda">
        <CollapsibleTrigger as-child>
            <button
                type="button"
                class="km-voce km-riga flex w-full items-center gap-2.5 rounded-lg px-1.5 text-left font-medium"
                :class="livello === 2 ? 'text-[13px]' : 'text-[13.5px]'"
                :data-menu-voce="voce.chiave"
                :data-attiva="voce.attiva ? 'dentro' : 'false'"
            >
                <IconaSezione :icona="voce.icona" :colore="voce.colore" :misura="livello === 2 ? 'voce2' : 'voce'" />
                <span class="truncate">{{ voce.nome }}</span>
                <ChevronRight class="ml-auto mr-0.5 size-4 opacity-60 transition-transform duration-200" :class="{ 'rotate-90': aperta }" />
            </button>
        </CollapsibleTrigger>
        <CollapsibleContent>
            <!-- Il terzo livello: un rientro in più, con la sua linea di guida -->
            <div class="km-rientro mt-px flex flex-col gap-px py-px" data-menu-figlie>
                <VoceMenuRiga v-for="figlia in voce.figlie" :key="figlia.chiave" :voce="figlia" figlia />
            </div>
        </CollapsibleContent>
    </Collapsible>
</template>
