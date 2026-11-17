<script setup lang="ts">
/**
 * L'avviso di convocazione dell'assemblea (Web Pro Italia, 17/11/2026): si genera dal modello dello studio con i dati
 * dell'assemblea (le due convocazioni, il luogo, l'ordine del giorno, la modalità), poi il testo è libero. In fondo al
 * testo, dopo una riga di trattini, c'è il modulo di delega: i segnaposto del destinatario restano scritti e si
 * riempiono alla stampa (in bianco nell'avviso generico, con il nome e le unità in quello di ogni persona).
 *
 * Da qui si stampa (l'avviso generico, uno per persona, lo .zip, il foglio delle firme) e si manda: per email a chi ha
 * un indirizzo, o registrando la spedizione fatta fuori dal programma. Quando la convocazione parte, la data
 * dell'avviso si riempie e l'assemblea risulta convocata.
 */
import { computed, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import Alert from '@/components/Alert.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import AssembleeGuide from '@/components/gestionale/assemblee/AssembleeGuide.vue';
import InvioAssemblea from '@/components/gestionale/assemblee/InvioAssemblea.vue';
import { usePermission } from '@/composables/permissions';
import { ClipboardList, FileArchive, FileDown, FileSignature, Mail, Save, ScrollText, Users, Wand2 } from 'lucide-vue-next';
import { NOMI_STATO, giorno, giornoEOra, type AnteprimaInvio, type AvvisoPagina, type DatiAssemblea } from '@/lib/gestionale/assemblee';
import type { Flash } from '@/types/flash';

const props = defineProps<{
    condominio: any; condomini: any; esercizio: any;
    assemblea: DatiAssemblea;
    punti: { numero: number; titolo: string; descrizione: string | null }[];
    convocazione: { testo: string | null; modello_id: number | null; modello: string | null; collegamento_videoconferenza: string | null; modificabile: boolean };
    modelli: { id: number; nome: string; descrizione: string | null; predefinito: boolean }[];
    proposta: string;
    avvisi: AvvisoPagina[];
    invio: AnteprimaInvio;
    segnaposti: { semplici: Record<string, string>; punti: Record<string, string>; destinatario: Record<string, string> };
    puo: { gestire: boolean; approvare: boolean };
}>();

const { generatePath } = usePermission();
const page = usePage<{ flash?: { message?: Flash }; errors?: Record<string, string> }>();
const flashMessage = computed(() => page.props.flash?.message);
const errori = computed(() => page.props.errors ?? {});

const a = computed(() => props.assemblea);
const c = computed(() => props.convocazione);
const base = computed(() => generatePath('gestionale/:condominio/assemblee/:assemblea', { condominio: props.condominio.id, assemblea: a.value.id }));
const scrivibile = computed(() => props.puo.gestire && c.value.modificabile);
const opzioni = { preserveScroll: true, preserveState: true };
const mostraGuida = ref(false);

// --- Il testo ------------------------------------------------------------------------------------------------
const testo = ref(c.value.testo ?? '');
const collegamento = ref(c.value.collegamento_videoconferenza ?? '');
watch(() => props.convocazione, (n) => { testo.value = n.testo ?? ''; collegamento.value = n.collegamento_videoconferenza ?? ''; });
const modello = ref<number | null>(c.value.modello_id ?? props.modelli.find((m) => m.predefinito)?.id ?? props.modelli[0]?.id ?? null);
const mostraProposta = ref(false);
const mostraSegnaposti = ref(false);
const cambiato = computed(() => testo.value !== (c.value.testo ?? '') || collegamento.value !== (c.value.collegamento_videoconferenza ?? ''));

function salva() {
    router.put(`${base.value}/convocazione`, { testo: testo.value, collegamento_videoconferenza: collegamento.value || null }, opzioni);
}
const confermaGenera = ref(false);
function genera(sovrascrivi = false) {
    if (!sovrascrivi && (c.value.testo ?? '').trim() !== '') {
        confermaGenera.value = true;
        return;
    }
    confermaGenera.value = false;
    router.post(`${base.value}/convocazione/genera`, { modello_id: modello.value, sovrascrivi }, opzioni);
}

// --- Le stampe -----------------------------------------------------------------------------------------------
const apri = (percorso: string) => window.open(`${base.value}${percorso}`, '_blank');
// Dopo un invio la pagina rilegge l'assemblea (lo stato, la data dell'avviso) e gli avvisi.
const rileggi = () => router.reload({ only: ['assemblea', 'avvisi', 'invio'] });

const quando = computed(() => [
    a.value.data_prima_convocazione ? `prima convocazione ${giornoEOra(a.value.data_prima_convocazione)}` : 'prima convocazione da fissare',
    a.value.data_seconda_convocazione ? `seconda ${giornoEOra(a.value.data_seconda_convocazione)}` : null,
].filter(Boolean).join(' · '));

const pageGuides = [
    { title: 'Dal modello', description: 'Scegli il modello dello studio e genera: il programma scrive le due convocazioni, il luogo, l\'ordine del giorno e il modulo di delega.', icon: Wand2, colorVariant: 'blue' as const },
    { title: 'Le stampe', description: 'L\'avviso generico, uno per persona con il nome e l\'indirizzo in alto, lo .zip con un PDF per persona, il foglio delle firme da portare in sala.', icon: FileDown, colorVariant: 'amber' as const },
    { title: 'L\'invio', description: 'Per email a chi ha un indirizzo; per gli altri stampa e registra la spedizione. Per ogni persona resta scritto quando e come.', icon: Mail, colorVariant: 'emerald' as const },
];
const breadcrumbs = computed(() => [
    { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
    { title: 'Assemblee', href: generatePath('gestionale/:condominio/assemblee', { condominio: props.condominio.id }) },
    { title: a.value.titolo, href: base.value },
    { title: 'Convocazione' },
]);
const coloreAvviso = (tipo: string) => tipo === 'error' ? 'bg-rose-50 text-rose-800 dark:bg-rose-950/40 dark:text-rose-200'
    : tipo === 'warning' ? 'bg-amber-50 text-amber-900 dark:bg-amber-950/40 dark:text-amber-200' : 'bg-slate-50 text-slate-700 dark:bg-slate-800 dark:text-slate-300';
</script>

<template>
    <Head :title="`Convocazione — ${a.titolo}`" />
    <GestionaleLayout>
        <div class="px-6 py-6 space-y-4">
            <PageHeaderGuide
                page-title="Convocazione dell'assemblea"
                :page-subtitle="`${a.titolo} · ${quando}`"
                :guides="pageGuides"
                :breadcrumbs="(breadcrumbs as any)"
                :condominio="(props.condominio as any)"
                :condomini="(props.condomini as any)"
                :back-url="base"
                back-text="L'assemblea"
                has-text-guide
                text-guide-title="Guida"
                @open-text-guide="mostraGuida = true"
            >
                <template #actions>
                    <Link v-if="puo.gestire" :href="`${generatePath('gestionale/:condominio/assemblee/modelli', { condominio: props.condominio.id })}?tipo=convocazione`">
                        <Button variant="outline" class="h-8 px-3 gap-2 bg-white dark:bg-slate-900"><FileSignature class="w-4 h-4" /> Modelli</Button>
                    </Link>
                    <Link v-if="scrivibile" :href="`${base}/edit`"><Button variant="outline" class="h-8 px-3 gap-2 bg-white dark:bg-slate-900"><ClipboardList class="w-4 h-4" /> Date e ordine del giorno</Button></Link>
                </template>
            </PageHeaderGuide>

            <div v-if="flashMessage"><Alert :message="flashMessage.message" :type="flashMessage.type" /></div>
            <div v-if="Object.keys(errori).length" class="rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800 dark:bg-rose-950/40 dark:border-rose-900 dark:text-rose-200">
                <p v-for="(m, k) in errori" :key="k">{{ m }}</p>
            </div>

            <!-- Lo stato e le stampe -->
            <div class="rounded-xl border bg-white dark:bg-slate-900 p-4 flex flex-wrap items-center gap-3">
                <span class="text-sm rounded-full px-3 py-1 font-semibold bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300" data-testid="stato-assemblea">{{ NOMI_STATO[a.stato] }}</span>
                <span class="text-sm text-slate-600 dark:text-slate-300">{{ a.data_avviso ? `avviso del ${giorno(a.data_avviso)}` : 'avviso non ancora partito' }}</span>
                <span v-if="c.modello" class="text-sm text-slate-500">· generata da «{{ c.modello }}»</span>
                <div class="ml-auto flex flex-wrap gap-2">
                    <Button variant="outline" class="gap-2" data-testid="stampa-avviso" @click="apri('/convocazione/stampa')"><FileDown class="w-4 h-4" /> L'avviso</Button>
                    <Button variant="outline" class="gap-2" data-testid="stampa-per-persona" @click="apri('/convocazione/per-persona/stampa')"><Users class="w-4 h-4" /> Uno per persona</Button>
                    <Button variant="outline" class="gap-2" @click="apri('/convocazione/zip')"><FileArchive class="w-4 h-4" /> .zip per persona</Button>
                    <Button variant="outline" class="gap-2" data-testid="stampa-foglio-firme" @click="apri('/foglio-firme/stampa')"><ScrollText class="w-4 h-4" /> Foglio firme</Button>
                </div>
            </div>

            <ul v-if="avvisi.length" class="space-y-1" data-testid="avvisi-convocazione">
                <li v-for="(av, i) in avvisi" :key="i" class="text-sm rounded-md px-3 py-1.5" :class="coloreAvviso(av.tipo)">{{ av.testo }}</li>
            </ul>

            <!-- Il testo -->
            <div class="rounded-xl border bg-white dark:bg-slate-900 p-4 space-y-3">
                <div class="flex flex-wrap items-end gap-2">
                    <h3 class="font-semibold mr-auto">Il testo della convocazione</h3>
                    <label class="text-sm flex items-center gap-2">Modello
                        <select v-model="modello" :disabled="!scrivibile" class="h-9 rounded-md border px-2 bg-transparent dark:bg-slate-900 min-w-[260px]">
                            <option v-for="m in modelli" :key="m.id" :value="m.id">{{ m.nome }}{{ m.predefinito ? ' (predefinito)' : '' }}</option>
                        </select>
                    </label>
                    <Button :disabled="!scrivibile" variant="outline" class="gap-2" data-testid="genera-convocazione" @click="genera(false)"><Wand2 class="w-4 h-4" /> {{ c.testo ? 'Rigenera dal modello' : 'Genera dal modello' }}</Button>
                    <Button :disabled="!scrivibile || !cambiato" class="gap-2" data-testid="salva-convocazione" @click="salva"><Save class="w-4 h-4" /> Salva</Button>
                </div>
                <p class="text-xs text-slate-500">
                    Si scrive come il verbale: «# » all'inizio della riga per un titolo, <code>**testo**</code> per il grassetto, «1. » o «- » per un elenco, una riga vuota fra i paragrafi.
                    Una riga con soli tre trattini (<code>---</code>) stacca quello che segue con una linea tratteggiata: è il modulo di delega.
                    <code v-pre>{{destinatario.nome}}</code> e <code v-pre>{{destinatario.unita}}</code> restano scritti: nell'avviso generico diventano una riga da compilare, in quello di ogni persona il suo nome e le sue unità.
                </p>
                <Textarea v-model="testo" :disabled="!scrivibile" rows="26" class="font-mono text-[13px] leading-relaxed" data-testid="testo-convocazione" />
                <label class="text-sm space-y-1 block max-w-3xl">
                    <span class="block font-medium">Collegamento della videoconferenza (se c'è)</span>
                    <Input v-model="collegamento" :disabled="!scrivibile" class="h-9" placeholder="https://…" data-testid="collegamento" />
                    <span class="block text-xs text-slate-500">Entra nell'avviso quando lo generi dal modello (<code v-pre>{{assemblea.collegamento}}</code>). Si salva con «Salva».</span>
                </label>
                <div class="flex flex-wrap gap-4">
                    <button type="button" class="text-sm underline text-slate-600" @click="mostraProposta = !mostraProposta">{{ mostraProposta ? 'Nascondi' : 'Mostra' }} quello che il modello darebbe adesso, con i dati di oggi</button>
                    <button type="button" class="text-sm underline text-slate-600" @click="mostraSegnaposti = !mostraSegnaposti">{{ mostraSegnaposti ? 'Nascondi' : 'Mostra' }} i segnaposto</button>
                </div>
                <pre v-if="mostraProposta" class="whitespace-pre-wrap text-[13px] rounded-md border bg-slate-50 dark:bg-slate-800 p-3 max-h-[480px] overflow-auto">{{ proposta }}</pre>
                <dl v-if="mostraSegnaposti" class="grid gap-x-6 gap-y-1.5 md:grid-cols-2 text-xs rounded-md border bg-slate-50 dark:bg-slate-800 p-3">
                    <div v-for="(descr, chiave) in { ...segnaposti.semplici, ...segnaposti.destinatario }" :key="chiave">
                        <dt class="font-mono text-blue-700 dark:text-blue-300" v-text="`{{${chiave}}}`"></dt>
                        <dd class="text-slate-600 dark:text-slate-400">{{ descr }}</dd>
                    </div>
                    <div class="md:col-span-2 pt-1">Dentro <code v-pre>{{#punti}} … {{/punti}}</code>, che si ripete per ogni punto:
                        <span v-for="(descr, chiave) in segnaposti.punti" :key="chiave" class="font-mono text-blue-700 dark:text-blue-300 ml-2" v-text="`{{${chiave}}}`"></span>
                    </div>
                </dl>
            </div>

            <!-- L'invio -->
            <InvioAssemblea cosa="convocazione" :base="base" :invio="invio" :puo-gestire="puo.gestire" @aggiornato="rileggi" />
        </div>

        <ConfirmDialog
            :model-value="confermaGenera"
            title="Sostituire il testo della convocazione?"
            description="La convocazione ha già un testo: generarla di nuovo dal modello lo sostituisce per intero, con le correzioni fatte a mano."
            confirm-text="Sostituisci"
            cancel-text="Lascia com'è"
            variant="warning"
            @update:model-value="(x: boolean) => { if (!x) confermaGenera = false; }"
            @confirm="genera(true)"
        />
        <AssembleeGuide v-model:open="mostraGuida" />
    </GestionaleLayout>
</template>
