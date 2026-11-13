<script setup lang="ts">
/**
 * Il fascicolo di bilancio di un esercizio (Web Pro Italia, 13/11/2026).
 *
 * Qui lo studio scrive la nota sintetica esplicativa, sceglie che cosa stampare e scarica il PDF unico da mandare ai
 * condòmini. I numeri non si scrivono in questa pagina: il fascicolo li rilegge dai registri a ogni stampa, quindi sono
 * gli stessi dello stato patrimoniale, della prima nota e dei piani rate.
 */
import { computed } from 'vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import MovimentiLayout from '@/layouts/gestionale/MovimentiLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import Alert from '@/components/Alert.vue';
import { usePermission } from '@/composables/permissions';
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { BookMarked, FileDown, FileText, ListChecks, PenLine } from 'lucide-vue-next';
import type { Building } from '@/types/buildings';
import type { Esercizio } from '@/types/gestionale/esercizi';

type Tipo = 'consuntivo' | 'preventivo';

interface Fascicolo { tipo: Tipo | null; titolo: string | null; sottotitolo: string | null; nota: string | null; sezioni: string[] | null; aggiornato_il: string | null }
interface Anteprima {
    tipo: Tipo;
    sezioni: { chiave: string; titolo: string }[];
    copertina: { titoli: string[]; sottotitolo: string | null; studio: string | null; ha_foto: boolean };
    numeri: { voce: string; importo: number }[];
    esercizio_preventivo: { nome: string; dal: string; al: string; anno: string } | null;
}
interface Opzioni { tipi: Tipo[]; sezioni: Record<string, string>; di_serie: Record<Tipo, string[]> }

const props = defineProps<{
    condominio: Building; condomini: Building[]; esercizio: Esercizio; esercizi: Esercizio[];
    fascicolo: Fascicolo; anteprima: Anteprima; opzioni: Opzioni;
}>();

const page = usePage();
const flashMessage = computed(() => (page.props as any).flash?.message);
const { generatePath } = usePermission();
const { euro } = useCurrencyFormatter();

const base = { condominio: props.condominio.id, esercizio: (props.esercizio as any).id };
const percorso = generatePath('gestionale/:condominio/esercizi/:esercizio/fascicolo-bilancio', base);

const form = useForm({
    tipo: (props.fascicolo.tipo ?? '') as Tipo | '',
    titolo: props.fascicolo.titolo ?? '',
    sottotitolo: props.fascicolo.sottotitolo ?? '',
    nota: props.fascicolo.nota ?? '',
    // Nessuna scelta salvata = le sezioni di serie del tipo: le caselle partono spuntate così.
    sezioni: [...(props.fascicolo.sezioni ?? props.opzioni.di_serie[props.anteprima.tipo])],
});

const tipoEffettivo = computed<Tipo>(() => (form.tipo || props.anteprima.tipo) as Tipo);
const stampate = computed(() => new Set(props.anteprima.sezioni.map(s => s.chiave)));

const cambiaTipo = () => { form.sezioni = [...props.opzioni.di_serie[tipoEffettivo.value]]; };
const spunta = (chiave: string) => {
    form.sezioni = form.sezioni.includes(chiave) ? form.sezioni.filter(s => s !== chiave) : [...form.sezioni, chiave];
};

const salva = () => {
    form.transform(d => ({ ...d, tipo: d.tipo || null })).put(percorso, { preserveScroll: true });
};

const stampa = (bozza: boolean) => { window.open(`${percorso}/print${bozza ? '?bozza=1' : ''}`, '_blank'); };

const headerBreadcrumbs = computed(() => [
    { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
    { title: 'Movimenti' },
    { title: 'Fascicolo di bilancio' },
]);

const pageGuides = [
    { title: 'Un documento solo', description: 'Copertina, nota esplicativa, rendiconto delle spese, situazione dei condòmini, stato patrimoniale, fatture da pagare, registro e preventivo: tutto in un PDF, da mandare con la convocazione.', icon: BookMarked, colorVariant: 'blue' as const },
    { title: 'I numeri non si scrivono', description: 'Ogni cifra viene dai registri del gestionale, la stessa delle stampe singole. Qui si scrive solo la nota e si sceglie che cosa includere.', icon: ListChecks, colorVariant: 'emerald' as const },
    { title: 'La nota è tua', description: 'È la lettera ai condòmini chiesta dall\'art. 1130-bis c.c.: che cosa è successo nell\'anno, i rapporti in corso, le questioni aperte.', icon: PenLine, colorVariant: 'amber' as const },
];
</script>

<template>
    <Head title="Fascicolo di bilancio" />
    <GestionaleLayout>
        <div class="px-6 py-8 space-y-3">
            <PageHeaderGuide
                page-title="Fascicolo di bilancio"
                page-subtitle="Il documento unico per l'assemblea: la nota la scrivi tu, i numeri li mette il gestionale."
                :guides="pageGuides"
                :breadcrumbs="(headerBreadcrumbs as any)"
                :condominio="(props.condominio as any)"
                :condomini="(props.condomini as any)"
                :esercizio="(props.esercizio as any)"
                :esercizi="(props.esercizi as any)"
            >
                <template #actions>
                    <Button variant="outline" class="h-8 px-3 gap-2 bg-white" @click="stampa(true)">
                        <FileText class="w-4 h-4" /> <span class="hidden sm:inline">Bozza</span>
                    </Button>
                    <Button class="h-8 px-3 gap-2" @click="stampa(false)">
                        <FileDown class="w-4 h-4" /> <span class="hidden sm:inline">Scarica il fascicolo</span>
                    </Button>
                </template>
            </PageHeaderGuide>

            <div v-if="flashMessage" class="py-1">
                <Alert :message="flashMessage.message" :type="flashMessage.type" />
            </div>

            <div class="w-full">
                <section class="w-full space-y-4">
                    <MovimentiLayout>
                        <div class="grid gap-6 lg:grid-cols-3">
                            <!-- Che cosa uscirebbe adesso -->
                            <div class="space-y-4 lg:col-span-1">
                                <div class="rounded-lg border border-slate-200 bg-white p-4">
                                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">In copertina</p>
                                    <p class="mt-2 text-base font-bold text-slate-900">{{ props.condominio.nome }}</p>
                                    <p v-for="t in props.anteprima.copertina.titoli" :key="t" class="text-sm font-semibold text-slate-700">{{ t }}</p>
                                    <p class="mt-1 text-xs text-slate-500">{{ props.anteprima.copertina.sottotitolo }}</p>
                                    <p class="mt-3 text-xs text-slate-500">
                                        <template v-if="props.anteprima.copertina.studio">Studio: <span class="font-medium text-slate-700">{{ props.anteprima.copertina.studio }}</span></template>
                                        <template v-else>Il nome dello studio non è ancora impostato.</template>
                                        <template v-if="!props.anteprima.copertina.ha_foto"> Nessuna foto di copertina.</template>
                                        <br>Nome dello studio e foto si scelgono in Impostazioni → Stampe, il colore in Impostazioni → Aspetto.
                                    </p>
                                </div>

                                <div v-if="props.anteprima.numeri.length" class="rounded-lg border border-slate-200 bg-white p-4">
                                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">I numeri dell'esercizio</p>
                                    <dl class="mt-2 space-y-1.5 text-sm">
                                        <div v-for="n in props.anteprima.numeri" :key="n.voce" class="flex justify-between gap-3">
                                            <dt class="text-slate-600">{{ n.voce }}</dt>
                                            <dd class="font-medium tabular-nums text-slate-900">{{ euro(n.importo) }}</dd>
                                        </div>
                                    </dl>
                                    <p class="mt-2 text-xs text-slate-500">Sono le cifre che il fascicolo stampa sotto la nota. Vengono dai registri: per cambiarle si corregge il dato, non il fascicolo.</p>
                                </div>

                                <div class="rounded-lg border border-slate-200 bg-white p-4">
                                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Che cosa contiene</p>
                                    <div class="mt-2 space-y-2">
                                        <label v-for="(titolo, chiave) in props.opzioni.sezioni" :key="chiave" class="flex items-start gap-2 text-sm">
                                            <input type="checkbox" class="mt-0.5 h-4 w-4 rounded border-slate-300" :checked="form.sezioni.includes(chiave as string)" @change="spunta(chiave as string)" />
                                            <span>
                                                <span class="text-slate-800">{{ titolo }}</span>
                                                <span v-if="form.sezioni.includes(chiave as string) && !stampate.has(chiave as string)" class="block text-xs text-amber-700">
                                                    Oggi non ha dati: non verrà stampata.
                                                </span>
                                            </span>
                                        </label>
                                    </div>
                                    <p v-if="tipoEffettivo === 'consuntivo'" class="mt-3 text-xs text-slate-500">
                                        <template v-if="props.anteprima.esercizio_preventivo">Preventivo e rate sono quelli dell'esercizio successivo ({{ props.anteprima.esercizio_preventivo.nome }}).</template>
                                        <template v-else>Preventivo e rate dell'anno dopo compaiono quando esiste l'esercizio successivo con il suo preventivo.</template>
                                    </p>
                                </div>
                            </div>

                            <!-- La parte scritta -->
                            <form class="space-y-4 lg:col-span-2" @submit.prevent="salva">
                                <div class="rounded-lg border border-slate-200 bg-white p-4 space-y-4">
                                    <div class="grid gap-4 md:grid-cols-3">
                                        <div>
                                            <label class="block text-sm font-medium text-slate-700 mb-1">Tipo di fascicolo</label>
                                            <select v-model="form.tipo" class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm" @change="cambiaTipo">
                                                <option value="">Lo decide il gestionale ({{ props.anteprima.tipo }})</option>
                                                <option value="consuntivo">Consuntivo (con il preventivo dell'anno dopo)</option>
                                                <option value="preventivo">Solo preventivo e rate</option>
                                            </select>
                                        </div>
                                        <div class="md:col-span-2">
                                            <label class="block text-sm font-medium text-slate-700 mb-1">Titolo in copertina</label>
                                            <input v-model="form.titolo" type="text" maxlength="150" :placeholder="props.anteprima.copertina.titoli.join(' – ')"
                                                class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm" />
                                            <p v-if="form.errors.titolo" class="text-sm text-red-500 mt-1">{{ form.errors.titolo }}</p>
                                        </div>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-slate-700 mb-1">Riga sotto il titolo</label>
                                        <input v-model="form.sottotitolo" type="text" maxlength="250" :placeholder="props.anteprima.copertina.sottotitolo ?? ''"
                                            class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm" />
                                        <p class="text-xs text-slate-500 mt-1">Titolo e riga vuoti = quelli proposti dal gestionale.</p>
                                    </div>
                                </div>

                                <div class="rounded-lg border border-slate-200 bg-white p-4">
                                    <label class="block text-sm font-medium text-slate-700 mb-1">Nota sintetica esplicativa</label>
                                    <Textarea v-model="form.nota" rows="18" class="font-mono text-[13px] leading-relaxed"
                                        placeholder="Gentili Condòmini,&#10;&#10;la presente nota accompagna il rendiconto dell'esercizio…&#10;&#10;# I. Il conto corrente&#10;Tutte le somme del condominio sono transitate sul conto…&#10;&#10;# II. Fatti principali dell'esercizio&#10;1. …&#10;2. …" />
                                    <p class="text-xs text-slate-500 mt-2">
                                        Una riga che comincia con <code class="rounded bg-slate-100 px-1">#</code> è un titolo di paragrafo; righe che cominciano con
                                        <code class="rounded bg-slate-100 px-1">-</code> o <code class="rounded bg-slate-100 px-1">1.</code> sono un elenco;
                                        <code class="rounded bg-slate-100 px-1">**testo**</code> va in grassetto; una riga vuota separa i paragrafi.
                                        Se la lasci vuota il fascicolo stampa due righe di presentazione e i numeri dell'esercizio.
                                    </p>
                                    <p v-if="form.errors.nota" class="text-sm text-red-500 mt-1">{{ form.errors.nota }}</p>
                                </div>

                                <div class="flex items-center justify-between gap-4">
                                    <p class="text-xs text-slate-500">
                                        <template v-if="props.fascicolo.aggiornato_il">Ultimo salvataggio: {{ props.fascicolo.aggiornato_il }}.</template>
                                        <template v-else>Non ancora salvato: il fascicolo si può già scaricare con le scelte di serie.</template>
                                    </p>
                                    <Button :disabled="form.processing">
                                        <span v-if="form.processing" class="animate-spin inline-block h-4 w-4 border-2 border-current rounded-full border-t-transparent mr-2" />
                                        Salva
                                    </Button>
                                </div>
                            </form>
                        </div>
                    </MovimentiLayout>
                </section>
            </div>
        </div>
    </GestionaleLayout>
</template>
