<script setup lang="ts">
/**
 * La ricerca rapida (Ctrl+K o ⌘K): un elenco per andare a una sezione e per aprire un condominio per nome.
 *
 * Le sezioni sono le voci del menu, con gli stessi permessi (comprese quelle dentro «Movimenti», con il nome intero della
 * pagina). I condomini sono quelli che la pagina porta già; altrove l'elenco si chiede una volta, alla prima apertura.
 */
import { computed, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { useEventListener } from '@vueuse/core';
import { ArrowUpRight, Bell, Building2, CornerDownLeft } from 'lucide-vue-next';
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { usePermission } from '@/composables/permissions';
import { Permission } from '@/enums/Permission';
import { useCondominioInUso } from './condominioInUso';
import IconaSezione from './IconaSezione.vue';
import { useElencoCondomini } from './elencoCondomini';
import { useVociMenu, type VoceMenu } from './vociMenu';

const props = defineProps<{ gestionale?: boolean }>();
const aperta = defineModel<boolean>('open', { default: false });

const page = usePage();
const { generatePath, hasPermission } = usePermission();
const { studio, gruppoCondominio, fondo, sorgente, nomeStudio, nelPannello } = useVociMenu({ gestionale: props.gestionale });
const { condomini, carica } = useElencoCondomini();
const { riapri } = useCondominioInUso();

// Ctrl+K o ⌘K, da qualunque punto della pagina: apre e chiude.
useEventListener('keydown', (evento: KeyboardEvent) => {
    if ((evento.ctrlKey || evento.metaKey) && evento.key.toLowerCase() === 'k') {
        evento.preventDefault();
        aperta.value = ! aperta.value;
    }
});

// I condomini si chiedono quando la ricerca si apre (anche se nasce già aperta), non prima.
watch(aperta, (ora) => {
    if (ora) {
        void carica();
    }
}, { immediate: true });

/**
 * Le sezioni si cercano dove si è: dentro un condominio prima le sue, poi lo studio; nelle pagine dello studio prima lo
 * studio, poi quelle del condominio in uso (l'ultimo aperto), come nel menu.
 */
const ordine = computed<('condominio' | 'studio')[]>(() => (gruppoCondominio.value?.dellaPagina ? ['condominio', 'studio'] : ['studio', 'condominio']));

/**
 * Le voci del condominio, con quelle dentro «Movimenti» al posto di «Movimenti» (che da sola non porta da nessuna parte).
 * Senza un condominio in uso non ci sono: lo si apre da «Apri un condominio».
 */
const vociCondominio = computed<VoceMenu[]>(() => (gruppoCondominio.value?.voci ?? []).flatMap((voce) => (voce.figlie?.length ? voce.figlie : [voce])).filter((voce) => ! voce.spenta && ! voce.daScegliere));

const casella = computed(() => (nelPannello.value && hasPermission([Permission.VIEW_EVENTS])
    ? { href: route('admin.inbox'), numero: Number(page.props.inbox_count ?? 0) }
    : null));

const vai = (voce: Pick<VoceMenu, 'href' | 'esterna'>) => {
    aperta.value = false;
    if (voce.esterna) {
        window.open(voce.href, '_blank', 'noopener');

        return;
    }
    router.visit(voce.href);
};

/** Aprire un condominio è una scelta esplicita: se il suo gruppo nel menu era ripiegato, si riapre. */
const apriCondominio = (id: number) => {
    riapri();
    vai({ href: generatePath('gestionale/:condominio', { condominio: id }) });
};

/** Il nome intero accanto a quello breve, dove sono diversi: «Unità» si trova anche scrivendo «immobili». */
const altroNome = (voce: VoceMenu) => (voce.titolo.toLowerCase() !== voce.nome.toLowerCase() ? voce.titolo : null);
</script>

<template>
    <Dialog v-model:open="aperta">
        <DialogContent :show-close-button="false" class="top-[12vh] translate-y-0 gap-0 overflow-hidden p-0 sm:max-w-xl" data-ricerca-rapida>
            <DialogTitle class="sr-only">Ricerca rapida</DialogTitle>
            <DialogDescription class="sr-only">Cerca una sezione o un condominio e premi Invio per aprirlo.</DialogDescription>

            <Command>
                <CommandInput placeholder="Cerca una sezione o un condominio…" class="h-12 text-[15px]" />
                <CommandList class="max-h-[min(62vh,30rem)] scroll-py-2 p-1.5">
                    <CommandEmpty class="py-10 text-muted-foreground">Nessuna sezione e nessun condominio con questo nome.</CommandEmpty>

                    <template v-for="gruppo in ordine" :key="gruppo">
                        <CommandGroup v-if="gruppo === 'condominio' && gruppoCondominio?.nome" :heading="gruppoCondominio.nome">
                            <CommandItem v-for="voce in vociCondominio" :key="voce.chiave" :value="`c-${voce.chiave}`" class="cursor-pointer gap-2.5 py-1.5" @select="vai(voce)">
                                <IconaSezione :icona="voce.icona" :colore="voce.colore" misura="sm" class="[&_svg]:size-3.5" />
                                <span>{{ voce.nome }}</span>
                                <span v-if="altroNome(voce)" class="truncate text-xs text-muted-foreground">{{ altroNome(voce) }}</span>
                            </CommandItem>
                        </CommandGroup>

                        <CommandGroup v-else-if="gruppo === 'studio'" :heading="nomeStudio">
                            <CommandItem v-for="voce in studio" :key="voce.chiave" :value="`s-${voce.chiave}`" class="cursor-pointer gap-2.5 py-1.5" @select="vai(voce)">
                                <IconaSezione :icona="voce.icona" :colore="voce.colore" misura="sm" class="[&_svg]:size-3.5" />
                                <span>{{ voce.nome }}</span>
                                <span v-if="altroNome(voce)" class="truncate text-xs text-muted-foreground">{{ altroNome(voce) }}</span>
                                <span v-if="voce.contatore" class="ml-auto rounded-full bg-amber-500/15 px-1.5 text-[11px] font-semibold text-amber-700 tabular-nums dark:text-amber-300">{{ voce.contatore }}</span>
                            </CommandItem>
                            <CommandItem v-if="casella" value="s-casella" class="cursor-pointer gap-2.5 py-1.5" @select="vai(casella)">
                                <IconaSezione :icona="Bell" colore="#e2574c" misura="sm" class="[&_svg]:size-3.5" />
                                <span>Casella delle attività</span>
                                <span class="truncate text-xs text-muted-foreground">Action inbox</span>
                                <span v-if="casella.numero" class="ml-auto rounded-full bg-red-500/15 px-1.5 text-[11px] font-semibold text-red-700 tabular-nums dark:text-red-300">{{ casella.numero }}</span>
                            </CommandItem>
                        </CommandGroup>
                    </template>

                    <CommandGroup v-if="condomini.length" heading="Apri un condominio">
                        <CommandItem v-for="c in condomini" :key="c.id" :value="`k-${c.id}`" class="cursor-pointer gap-2.5 py-1.5" @select="apriCondominio(c.id)">
                            <span class="flex size-6 shrink-0 items-center justify-center rounded-[6px] bg-muted text-muted-foreground"><Building2 class="size-3.5" /></span>
                            <span class="truncate">{{ c.nome }}</span>
                            <span v-if="gruppoCondominio?.id === c.id" class="ml-auto text-xs text-muted-foreground">{{ gruppoCondominio.dellaPagina ? 'aperto' : 'in uso' }}</span>
                        </CommandItem>
                    </CommandGroup>

                    <CommandGroup heading="Altro">
                        <CommandItem v-for="voce in [...fondo, sorgente]" :key="voce.chiave" :value="`a-${voce.chiave}`" class="cursor-pointer gap-2.5 py-1.5" @select="vai(voce)">
                            <IconaSezione :icona="voce.icona" :colore="voce.colore" misura="sm" class="[&_svg]:size-3.5" />
                            <span>{{ voce.nome }}</span>
                            <span v-if="altroNome(voce)" class="truncate text-xs text-muted-foreground">{{ altroNome(voce) }}</span>
                            <ArrowUpRight v-if="voce.esterna" class="ml-auto text-muted-foreground" />
                        </CommandItem>
                    </CommandGroup>
                </CommandList>

                <div class="flex items-center gap-4 border-t bg-muted/40 px-3.5 py-2 text-[11px] text-muted-foreground">
                    <span class="inline-flex items-center gap-1.5"><kbd class="rounded border bg-background px-1 font-sans text-[10px] font-medium">↑</kbd><kbd class="rounded border bg-background px-1 font-sans text-[10px] font-medium">↓</kbd> per scegliere</span>
                    <span class="inline-flex items-center gap-1.5"><kbd class="inline-flex h-[1.1rem] items-center rounded border bg-background px-1"><CornerDownLeft class="size-2.5" /></kbd> per aprire</span>
                    <span class="ml-auto inline-flex items-center gap-1.5"><kbd class="rounded border bg-background px-1 font-sans text-[10px] font-medium">Esc</kbd> per chiudere</span>
                </div>
            </Command>
        </DialogContent>
    </Dialog>
</template>
