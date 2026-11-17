<script setup lang="ts">
/**
 * Le assemblee del condominio (Web Pro Italia, 17/11/2026): l'elenco con lo stato e il verbale di ognuna, la nuova
 * assemblea e i modelli di verbale dello studio.
 */
import { computed, ref } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import Alert from '@/components/Alert.vue';
import { Button } from '@/components/ui/button';
import AssembleeGuide from '@/components/gestionale/assemblee/AssembleeGuide.vue';
import { usePermission } from '@/composables/permissions';
import { CalendarDays, FileSignature, Gavel, Plus, ScrollText, Users } from 'lucide-vue-next';
import { NOMI_STATO, NOMI_STATO_VERBALE, giornoEOra } from '@/lib/gestionale/assemblee';
import type { Flash } from '@/types/flash';

interface Riga {
    id: number; titolo: string; tipo: string; stato: keyof typeof NOMI_STATO;
    data_prima_convocazione: string | null; data_seconda_convocazione: string | null; convocazione_tenuta: string | null;
    luogo: string | null; punti: number; presenze: number; verbale: { stato: keyof typeof NOMI_STATO_VERBALE; versione: number } | null;
}

const props = defineProps<{ condominio: any; condomini: any; esercizio: any; assemblee: Riga[]; puo: { gestire: boolean; approvare: boolean } }>();

const { generatePath } = usePermission();
const page = usePage<{ flash?: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash?.message);
const base = computed(() => generatePath('gestionale/:condominio/assemblee', { condominio: props.condominio.id }));
const mostraGuida = ref(false);

const quando = (a: Riga) => {
    const giorno = a.convocazione_tenuta === 'seconda' ? a.data_seconda_convocazione : (a.data_prima_convocazione ?? a.data_seconda_convocazione);
    return giorno ? giornoEOra(giorno) : 'Data da fissare';
};
const coloreStato: Record<string, string> = {
    bozza: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
    convocata: 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-300',
    in_corso: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300',
    chiusa: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300',
};

const pageGuides = [
    { title: 'Prima', description: 'Date delle due convocazioni, luogo, tabella dei millesimi e ordine del giorno, con la maggioranza che serve a ogni punto.', icon: CalendarDays, colorVariant: 'blue' as const },
    { title: 'Durante', description: 'Presenze e deleghe, entrate e uscite, voti punto per punto: il programma conta teste e millesimi e propone l\'esito.', icon: Users, colorVariant: 'amber' as const },
    { title: 'Dopo', description: 'Il verbale si genera dal modello dello studio e poi si corregge liberamente. Approvato, il PDF va nell\'archivio del condominio.', icon: ScrollText, colorVariant: 'emerald' as const },
];

const breadcrumbs = computed(() => [
    { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
    { title: 'Assemblee' },
]);
</script>

<template>
    <Head title="Assemblee" />
    <GestionaleLayout>
        <div class="px-6 py-8 space-y-4">
            <PageHeaderGuide
                page-title="Assemblee"
                page-subtitle="Le assemblee del condominio: convocazione, presenze e voti in sala, verbale."
                :guides="pageGuides"
                :breadcrumbs="(breadcrumbs as any)"
                :condominio="(props.condominio as any)"
                :condomini="(props.condomini as any)"
                has-text-guide
                text-guide-title="Guida"
                @open-text-guide="mostraGuida = true"
            >
                <template #actions>
                    <Link v-if="puo.gestire" :href="`${base}/modelli`">
                        <Button variant="outline" class="h-8 px-3 gap-2 bg-white dark:bg-slate-900"><FileSignature class="w-4 h-4" /> Modelli</Button>
                    </Link>
                    <Link v-if="puo.gestire" :href="`${base}/create`">
                        <Button class="h-8 px-3 gap-2" data-testid="nuova-assemblea"><Plus class="w-4 h-4" /> Nuova assemblea</Button>
                    </Link>
                </template>
            </PageHeaderGuide>

            <div v-if="flashMessage"><Alert :message="flashMessage.message" :type="flashMessage.type" /></div>

            <div class="rounded-xl border bg-white dark:bg-slate-900 divide-y dark:divide-slate-800">
                <Link
                    v-for="a in assemblee"
                    :key="a.id"
                    :href="`${base}/${a.id}`"
                    class="flex flex-wrap items-center gap-x-6 gap-y-1 p-4 hover:bg-slate-50 dark:hover:bg-slate-800/50"
                    data-testid="riga-assemblea"
                >
                    <Gavel class="w-5 h-5 text-slate-400 shrink-0" />
                    <div class="min-w-[260px] flex-1">
                        <p class="font-semibold">{{ a.titolo }}</p>
                        <p class="text-xs text-slate-500">
                            {{ a.tipo === 'straordinaria' ? 'Straordinaria' : 'Ordinaria' }} · {{ quando(a) }}<template v-if="a.luogo"> · {{ a.luogo }}</template>
                        </p>
                    </div>
                    <span class="text-xs text-slate-500">{{ a.punti }} punti · {{ a.presenze }} aventi diritto</span>
                    <span class="text-xs rounded-full px-2 py-0.5" :class="coloreStato[a.stato]">{{ NOMI_STATO[a.stato] }}</span>
                    <span v-if="a.verbale" class="text-xs text-slate-600 dark:text-slate-300">
                        Verbale: {{ NOMI_STATO_VERBALE[a.verbale.stato] }}<template v-if="a.verbale.versione > 1"> (versione {{ a.verbale.versione }})</template>
                    </span>
                </Link>
                <p v-if="!assemblee.length" class="p-6 text-sm text-slate-500">
                    Nessuna assemblea ancora. <template v-if="puo.gestire">Comincia da «Nuova assemblea»: date, luogo e ordine del giorno.</template>
                </p>
            </div>
        </div>
        <AssembleeGuide v-model:open="mostraGuida" />
    </GestionaleLayout>
</template>
