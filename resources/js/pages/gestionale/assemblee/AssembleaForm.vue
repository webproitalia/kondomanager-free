<script setup lang="ts">
/**
 * La nuova assemblea e la sua modifica (Web Pro Italia, 17/11/2026): tipo, titolo, date delle due convocazioni, avviso,
 * luogo e modalità, tabella dei millesimi per i conteggi, esercizio, e l'ordine del giorno con la maggioranza di ogni
 * punto (si aggiungono, si tolgono, si spostano su e giù).
 */
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { usePermission } from '@/composables/permissions';
import { ArrowDown, ArrowUp, CalendarDays, ListOrdered, Plus, Save, Table2, Trash2 } from 'lucide-vue-next';
import type { DatiAssemblea, Maggioranza } from '@/lib/gestionale/assemblee';

interface PuntoModulo { id: number | null; titolo: string; descrizione: string; maggioranza: string; voti: number; conDescrizione: boolean }

const props = defineProps<{
    condominio: any; condomini: any; esercizio: any;
    assemblea: DatiAssemblea | null;
    punti: { id: number; titolo: string; descrizione: string | null; maggioranza: string; voti: number }[];
    tabelle: { id: number; nome: string; quota: string }[];
    esercizi: { id: number; nome: string; stato: string }[];
    maggioranze: Maggioranza[];
    proposta: { tabella_id: number | null; esercizio_id: number | null };
}>();

const { generatePath } = usePermission();
const base = generatePath('gestionale/:condominio/assemblee', { condominio: props.condominio.id });
const nuova = props.assemblea === null;
const perIlCampo = (v: string | null | undefined) => (v ? v.replace(' ', 'T') : '');

const form = useForm({
    tipo: props.assemblea?.tipo ?? 'ordinaria',
    titolo: props.assemblea?.titolo ?? `Assemblea ordinaria ${new Date().getFullYear()}`,
    data_prima_convocazione: perIlCampo(props.assemblea?.data_prima_convocazione),
    data_seconda_convocazione: perIlCampo(props.assemblea?.data_seconda_convocazione),
    data_avviso: props.assemblea?.data_avviso ?? '',
    luogo: props.assemblea?.luogo ?? '',
    modalita: props.assemblea?.modalita ?? 'presenza',
    tabella_id: (props.assemblea ? props.assemblea.tabella_id : props.proposta.tabella_id) as number | null,
    esercizio_id: (props.assemblea ? props.assemblea.esercizio_id : props.proposta.esercizio_id) as number | null,
    note: props.assemblea?.note ?? '',
    punti: (props.punti.length
        ? props.punti.map((p) => ({ id: p.id, titolo: p.titolo, descrizione: p.descrizione ?? '', maggioranza: p.maggioranza, voti: p.voti, conDescrizione: !!p.descrizione }))
        : [
            { id: null, titolo: 'Approvazione del rendiconto consuntivo e del riparto', descrizione: '', maggioranza: 'ordinaria', voti: 0, conDescrizione: false },
            { id: null, titolo: 'Approvazione del bilancio preventivo e del piano rate', descrizione: '', maggioranza: 'ordinaria', voti: 0, conDescrizione: false },
            { id: null, titolo: 'Varie ed eventuali', descrizione: '', maggioranza: 'informativa', voti: 0, conDescrizione: false },
        ]) as PuntoModulo[],
});

function aggiungi() {
    form.punti.push({ id: null, titolo: '', descrizione: '', maggioranza: 'ordinaria', voti: 0, conDescrizione: false });
}
function sposta(i: number, verso: -1 | 1) {
    const j = i + verso;
    if (j < 0 || j >= form.punti.length) return;
    const copia = [...form.punti];
    [copia[i], copia[j]] = [copia[j], copia[i]];
    form.punti = copia;
}
function togli(i: number) {
    form.punti = form.punti.filter((_, k) => k !== i);
}

function salva() {
    form.transform((d) => ({
        ...d,
        data_prima_convocazione: d.data_prima_convocazione ? d.data_prima_convocazione.replace('T', ' ') : null,
        data_seconda_convocazione: d.data_seconda_convocazione ? d.data_seconda_convocazione.replace('T', ' ') : null,
        data_avviso: d.data_avviso || null,
        punti: d.punti.map((p) => ({ id: p.id, titolo: p.titolo, descrizione: p.conDescrizione ? p.descrizione : null, maggioranza: p.maggioranza })),
    }));
    if (nuova) form.post(base);
    else form.put(`${base}/${props.assemblea!.id}`, { preserveScroll: true });
}

const regola = (valore: string) => props.maggioranze.find((m) => m.valore === valore);
const indietro = computed(() => (nuova ? base : `${base}/${props.assemblea!.id}`));

const pageGuides = [
    { title: 'Le date', description: 'Prima e seconda convocazione, e il giorno dell\'avviso: il verbale li riporta. Quale convocazione si è tenuta si sceglie in sala.', icon: CalendarDays, colorVariant: 'blue' as const },
    { title: 'La tabella', description: 'Su questa tabella si contano costituzione e voti: di norma la generale di proprietà. Il programma propone quella che si chiama «generale» o «proprietà».', icon: Table2, colorVariant: 'amber' as const },
    { title: 'L\'ordine del giorno', description: 'Un punto per riga, con la maggioranza che chiede. I punti informativi (varie ed eventuali) non si votano.', icon: ListOrdered, colorVariant: 'emerald' as const },
];
const breadcrumbs = computed(() => [
    { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
    { title: 'Assemblee', href: base },
    { title: nuova ? 'Nuova assemblea' : 'Modifica' },
]);
const campo = 'h-9 rounded-md border px-2 bg-transparent text-sm dark:bg-slate-900';
</script>

<template>
    <Head :title="nuova ? 'Nuova assemblea' : 'Modifica assemblea'" />
    <GestionaleLayout>
        <div class="px-6 py-8 space-y-4 max-w-5xl">
            <PageHeaderGuide
                :page-title="nuova ? 'Nuova assemblea' : 'Modifica assemblea'"
                page-subtitle="Date, luogo, tabella dei millesimi e ordine del giorno."
                :guides="pageGuides"
                :breadcrumbs="(breadcrumbs as any)"
                :condominio="(props.condominio as any)"
                :condomini="(props.condomini as any)"
                :back-url="indietro"
                back-text="Indietro"
            />

            <div v-if="Object.keys(form.errors).length" class="rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800 dark:bg-rose-950/40 dark:border-rose-900 dark:text-rose-200">
                <p v-for="(m, k) in form.errors" :key="k">{{ m }}</p>
            </div>

            <div class="rounded-xl border bg-white dark:bg-slate-900 p-5 grid gap-4 md:grid-cols-2">
                <label class="text-sm space-y-1 md:col-span-2"><span class="block font-medium">Titolo</span><Input v-model="form.titolo" class="h-9" data-testid="titolo" /></label>
                <label class="text-sm space-y-1">
                    <span class="block font-medium">Tipo</span>
                    <select v-model="form.tipo" :class="campo" class="w-full"><option value="ordinaria">Ordinaria</option><option value="straordinaria">Straordinaria</option></select>
                </label>
                <label class="text-sm space-y-1">
                    <span class="block font-medium">Modalità</span>
                    <select v-model="form.modalita" :class="campo" class="w-full">
                        <option value="presenza">In presenza</option>
                        <option value="videoconferenza">In videoconferenza</option>
                        <option value="mista">In presenza e in videoconferenza</option>
                    </select>
                </label>
                <label class="text-sm space-y-1"><span class="block font-medium">Prima convocazione</span><Input v-model="form.data_prima_convocazione" type="datetime-local" class="h-9" /></label>
                <label class="text-sm space-y-1"><span class="block font-medium">Seconda convocazione</span><Input v-model="form.data_seconda_convocazione" type="datetime-local" class="h-9" /></label>
                <label class="text-sm space-y-1"><span class="block font-medium">Luogo (o il collegamento, in videoconferenza)</span><Input v-model="form.luogo" placeholder="per esempio la sala condominiale, Via Roma 1" class="h-9" /></label>
                <label class="text-sm space-y-1"><span class="block font-medium">Giorno dell'avviso di convocazione (facoltativo)</span><Input v-model="form.data_avviso" type="date" class="h-9" /></label>
                <label class="text-sm space-y-1">
                    <span class="block font-medium">Tabella dei millesimi per i conteggi</span>
                    <select v-model="form.tabella_id" :class="campo" class="w-full" data-testid="tabella">
                        <option :value="null">— scegli —</option>
                        <option v-for="t in tabelle" :key="t.id" :value="t.id">{{ t.nome }}{{ t.quota !== 'millesimi' ? ` (${t.quota})` : '' }}</option>
                    </select>
                </label>
                <label class="text-sm space-y-1">
                    <span class="block font-medium">Esercizio</span>
                    <select v-model="form.esercizio_id" :class="campo" class="w-full">
                        <option :value="null">— nessuno —</option>
                        <option v-for="e in esercizi" :key="e.id" :value="e.id">{{ e.nome }}{{ e.stato === 'aperto' ? ' (aperto)' : '' }}</option>
                    </select>
                </label>
                <label class="text-sm space-y-1 md:col-span-2"><span class="block font-medium">Note dello studio</span><Textarea v-model="form.note" rows="2" /></label>
            </div>

            <div class="rounded-xl border bg-white dark:bg-slate-900 p-5 space-y-3">
                <div class="flex items-center justify-between">
                    <h3 class="font-semibold">Ordine del giorno</h3>
                    <Button variant="outline" size="sm" class="gap-1" @click="aggiungi"><Plus class="w-4 h-4" /> Aggiungi un punto</Button>
                </div>
                <div v-for="(p, i) in form.punti" :key="i" class="rounded-lg border p-3 space-y-2" data-testid="punto-modulo">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="w-8 text-center font-bold text-slate-500">{{ i + 1 }}.</span>
                        <Input v-model="p.titolo" placeholder="Titolo del punto" class="h-9 flex-1 min-w-[280px]" />
                        <select v-model="p.maggioranza" :class="campo" class="w-[330px]">
                            <option v-for="m in maggioranze" :key="m.valore" :value="m.valore">{{ m.etichetta }}</option>
                        </select>
                        <Button variant="ghost" size="icon" class="h-9 w-9" :disabled="i === 0" title="Sposta su" @click="sposta(i, -1)"><ArrowUp class="w-4 h-4" /></Button>
                        <Button variant="ghost" size="icon" class="h-9 w-9" :disabled="i === form.punti.length - 1" title="Sposta giù" @click="sposta(i, 1)"><ArrowDown class="w-4 h-4" /></Button>
                        <Button variant="ghost" size="icon" class="h-9 w-9 text-rose-600" :disabled="p.voti > 0" :title="p.voti > 0 ? 'Ha già dei voti: non si toglie' : 'Togli il punto'" @click="togli(i)"><Trash2 class="w-4 h-4" /></Button>
                    </div>
                    <p class="pl-10 text-xs text-slate-500">{{ regola(p.maggioranza)?.a_che_serve }}</p>
                    <div class="pl-10">
                        <button v-if="!p.conDescrizione" type="button" class="text-xs underline text-slate-600" @click="p.conDescrizione = true">Aggiungi una descrizione</button>
                        <Textarea v-else v-model="p.descrizione" rows="2" placeholder="Che cosa si decide, per chi legge l'avviso" class="text-sm" />
                    </div>
                </div>
                <p v-if="!form.punti.length" class="text-sm text-slate-500">Nessun punto: aggiungine uno.</p>
            </div>

            <div class="flex gap-2">
                <Button :disabled="form.processing" class="gap-2" data-testid="salva-assemblea" @click="salva"><Save class="w-4 h-4" /> {{ nuova ? 'Crea l\'assemblea' : 'Salva' }}</Button>
                <Link :href="indietro"><Button variant="outline">Annulla</Button></Link>
            </div>
        </div>
    </GestionaleLayout>
</template>
