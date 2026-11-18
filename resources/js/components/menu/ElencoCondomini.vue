<script setup lang="ts">
/**
 * L'elenco in cui si sceglie un condominio, cercandolo per nome: lo aprono il selettore del percorso nella barra in alto e la
 * riga di testa del gruppo del condominio nel menu. In fondo, «Tutti i condomini» porta al loro elenco.
 */
import { router } from '@inertiajs/vue3';
import { Building2, Check, List } from 'lucide-vue-next';
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList, CommandSeparator } from '@/components/ui/command';
import { usePermission } from '@/composables/permissions';
import { Permission } from '@/enums/Permission';
import type { CondominioInElenco } from './elencoCondomini';

defineProps<{
    condomini: CondominioInElenco[];
    /** Il condominio in cui si è, o quello in uso: ha la spunta. */
    attuale?: number | null;
}>();
defineEmits<{ scelto: [condominio: CondominioInElenco] }>();

const { hasPermission } = usePermission();
</script>

<template>
    <Command>
        <CommandInput placeholder="Cerca un condominio…" />
        <CommandEmpty>Nessun condominio con questo nome.</CommandEmpty>
        <CommandList class="max-h-[min(60vh,22rem)]">
            <CommandGroup heading="Condomini">
                <CommandItem v-for="c in condomini" :key="c.id" :value="`${c.nome} ${c.id}`" class="cursor-pointer" @select="$emit('scelto', c)">
                    <Building2 class="text-muted-foreground" />
                    <span class="truncate" :class="{ 'font-semibold': c.id === attuale }">{{ c.nome }}</span>
                    <Check v-if="c.id === attuale" class="ml-auto text-primary" />
                </CommandItem>
            </CommandGroup>
            <template v-if="hasPermission([Permission.VIEW_CONDOMINI])">
                <CommandSeparator />
                <CommandGroup>
                    <CommandItem value="tutti-i-condomini elenco" class="cursor-pointer" @select="router.visit('/condomini')">
                        <List class="text-muted-foreground" />
                        Tutti i condomini
                    </CommandItem>
                </CommandGroup>
            </template>
        </CommandList>
    </Command>
</template>
