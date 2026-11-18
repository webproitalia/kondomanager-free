<script setup lang="ts">
/**
 * Il contenuto del menu laterale: il marchio in testa, i gruppi delle voci, in fondo le impostazioni, la guida e l'utente.
 * Lo disegnano la colonna a sinistra (aperta, o chiusa a sole icone) e, sugli schermi stretti, il cassetto.
 *
 * Dentro un condominio il gruppo dello studio resta in cima, compatto: una griglia di icone con il nome al passaggio del mouse
 * (con il menu chiuso, una sola icona che apre l'elenco), così da un condominio si va ovunque con un clic e le voci del
 * condominio stanno tutte nello schermo.
 */
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { Building2, ChevronsUpDown, LayoutGrid } from 'lucide-vue-next';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import UserMenuContent from '@/components/UserMenuContent.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { getInitials } from '@/composables/useInitials';
import { usePermission } from '@/composables/permissions';
import IconaSezione from './IconaSezione.vue';
import VoceConFiglie from './VoceConFiglie.vue';
import VoceMenuRiga from './VoceMenu.vue';
import { useVociMenu } from './vociMenu';
import type { Auth } from '@/types';

const props = defineProps<{
    /** Dentro un condominio (`GestionaleLayout`): c'è il gruppo con il nome del condominio. */
    gestionale?: boolean;
    /** Il menu è chiuso: solo le icone. */
    chiuso?: boolean;
}>();

const page = usePage();
const { generatePath } = usePermission();
const { studio, gruppoCondominio, fondo, nomeStudio } = useVociMenu({ gestionale: props.gestionale });

const utente = computed(() => (page.props.auth as Auth).user);
const nomeApp = computed(() => (page.props.name as string) || 'KondoManager');
const studioAcceso = computed(() => studio.value.some((v) => v.attiva));
</script>

<template>
    <div class="flex h-full min-h-0 flex-col" :data-menu-chiuso="chiuso ? 'true' : 'false'">
        <!-- Il marchio -->
        <div class="flex h-14 shrink-0 items-center" :class="chiuso ? 'justify-center' : 'px-3.5'">
            <Link :href="generatePath('dashboard')" class="flex min-w-0 items-center gap-2.5 rounded-lg outline-none focus-visible:ring-2 focus-visible:ring-white/40" :title="nomeApp">
                <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-[var(--km-menu-attiva)] text-white shadow-[inset_0_1px_0_hsl(0_0%_100%/0.18),0_1px_2px_hsl(0_0%_0%/0.3)]">
                    <AppLogoIcon class="size-5" />
                </span>
                <span v-if="! chiuso" class="truncate text-[15px] font-semibold tracking-tight text-white">{{ nomeApp }}</span>
            </Link>
        </div>

        <nav class="km-menu-scorre min-h-0 flex-1 overflow-y-auto overflow-x-hidden pb-3" :class="chiuso ? 'px-2.5' : 'px-2.5'" aria-label="Menu principale">
            <!-- ─── Lo studio ─── -->
            <template v-if="studio.length">
                <!-- Dentro un condominio, menu chiuso: una sola icona, che apre l'elenco delle voci dello studio (l'elenco dentro il suggerimento: vedi `VoceConFiglie.vue`) -->
                <Tooltip v-if="gruppoCondominio && chiuso" :delay-duration="0">
                    <TooltipTrigger as-child>
                        <span class="mt-1 flex">
                            <DropdownMenu>
                                <DropdownMenuTrigger as-child>
                                    <button type="button" class="km-voce flex size-10 items-center justify-center rounded-lg" data-menu-voce="studio" :data-attiva="studioAcceso ? 'true' : 'false'" :aria-label="nomeStudio">
                                        <span class="flex size-7 items-center justify-center rounded-[7px] bg-white/10 text-white ring-1 ring-inset ring-white/15"><LayoutGrid class="size-4" /></span>
                                    </button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent side="right" align="start" :side-offset="10" class="w-56">
                                    <DropdownMenuLabel class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">{{ nomeStudio }}</DropdownMenuLabel>
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem v-for="voce in studio" :key="voce.chiave" as-child>
                                        <Link :href="voce.href" class="flex w-full cursor-pointer items-center gap-2.5">
                                            <IconaSezione :icona="voce.icona" :colore="voce.colore" misura="sm" />
                                            <span class="truncate">{{ voce.nome }}</span>
                                            <span v-if="voce.contatore" class="ml-auto rounded-full bg-amber-500/15 px-1.5 text-[11px] font-semibold text-amber-700 tabular-nums dark:text-amber-300">{{ voce.contatore }}</span>
                                        </Link>
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </span>
                    </TooltipTrigger>
                    <TooltipContent side="right" :side-offset="10">{{ nomeStudio }}</TooltipContent>
                </Tooltip>

                <template v-else>
                    <div v-if="! chiuso" class="km-menu-etichetta flex h-8 items-end px-2 pb-1.5 text-[11px] font-semibold uppercase tracking-[0.08em]">{{ nomeStudio }}</div>
                    <div v-else class="h-1" />

                    <!-- Dentro un condominio, menu aperto: lo studio compatto, una griglia di icone -->
                    <div v-if="gruppoCondominio" class="grid grid-cols-5 gap-1 pb-1" data-menu-studio-compatto>
                        <Tooltip v-for="voce in studio" :key="voce.chiave" :delay-duration="0">
                            <TooltipTrigger as-child>
                                <Link :href="voce.href" class="km-voce relative flex h-10 items-center justify-center rounded-lg" :data-menu-voce="voce.chiave" :data-attiva="voce.attiva ? 'true' : 'false'" :aria-label="voce.titolo">
                                    <IconaSezione :icona="voce.icona" :colore="voce.colore" />
                                    <span v-if="voce.contatore" class="absolute -right-0.5 -top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-amber-400 px-1 text-[10px] font-bold leading-none text-amber-950 tabular-nums">{{ voce.contatore }}</span>
                                </Link>
                            </TooltipTrigger>
                            <TooltipContent side="bottom" :side-offset="6">
                                {{ voce.nome }}<span v-if="voce.titolo !== voce.nome" class="ml-1.5 opacity-60">{{ voce.titolo }}</span>
                            </TooltipContent>
                        </Tooltip>
                    </div>

                    <!-- Nello studio: l'elenco intero -->
                    <div v-else class="flex flex-col gap-0.5">
                        <VoceMenuRiga v-for="voce in studio" :key="voce.chiave" :voce="voce" :solo-icona="chiuso" />
                    </div>
                </template>
            </template>

            <!-- ─── Il condominio aperto ─── -->
            <template v-if="gruppoCondominio">
                <div v-if="! chiuso" class="mb-1.5 mt-3 flex items-center gap-2.5 rounded-lg bg-white/[0.06] px-2 py-2 ring-1 ring-inset ring-white/[0.07]" data-menu-condominio :title="gruppoCondominio.nome">
                    <span class="flex size-7 shrink-0 items-center justify-center rounded-[7px] bg-white/10 text-white"><Building2 class="size-4" /></span>
                    <span class="min-w-0 flex-1 leading-tight">
                        <span class="block truncate text-[13px] font-semibold text-white">{{ gruppoCondominio.nome }}</span>
                        <span v-if="gruppoCondominio.esercizio" class="km-menu-etichetta block truncate text-[11px]">{{ gruppoCondominio.esercizio }}</span>
                    </span>
                </div>
                <div v-else class="mx-1.5 my-2 h-px bg-[var(--km-menu-riga)]" />

                <div class="flex flex-col gap-0.5">
                    <template v-for="voce in gruppoCondominio.voci" :key="voce.chiave">
                        <VoceConFiglie v-if="voce.figlie?.length" :voce="voce" :solo-icona="chiuso" />
                        <VoceMenuRiga v-else :voce="voce" :solo-icona="chiuso" />
                    </template>
                </div>
            </template>
        </nav>

        <!-- ─── In fondo: impostazioni, guida, utente ─── -->
        <div class="shrink-0 border-t border-[var(--km-menu-riga)] px-2.5 pb-2.5 pt-2">
            <div class="flex flex-col gap-0.5">
                <VoceMenuRiga v-for="voce in fondo" :key="voce.chiave" :voce="voce" :solo-icona="chiuso" />
            </div>

            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <button type="button" class="km-voce mt-1.5 flex w-full items-center rounded-lg text-left" :class="chiuso ? 'size-10 justify-center' : 'h-11 gap-2.5 px-1.5'" data-menu-utente :aria-label="utente.name">
                        <Avatar class="size-7 shrink-0 rounded-[7px] bg-white/15 text-[11px] font-bold text-white ring-1 ring-inset ring-white/15">
                            <AvatarImage v-if="utente.avatar" :src="utente.avatar" :alt="utente.name" />
                            <AvatarFallback>{{ getInitials(utente.name) }}</AvatarFallback>
                        </Avatar>
                        <template v-if="! chiuso">
                            <span class="min-w-0 flex-1 leading-tight">
                                <span class="block truncate text-[13px] font-medium text-white">{{ utente.name }}</span>
                                <span class="km-menu-etichetta block truncate text-[11px]">{{ utente.email }}</span>
                            </span>
                            <ChevronsUpDown class="mr-0.5 size-4 shrink-0 opacity-50" />
                        </template>
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent :side="chiuso ? 'right' : 'top'" align="start" :side-offset="8" class="w-60">
                    <UserMenuContent :user="utente" />
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
    </div>
</template>
