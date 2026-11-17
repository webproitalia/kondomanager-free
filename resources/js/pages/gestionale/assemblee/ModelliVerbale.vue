<script setup lang="ts">
/**
 * I modelli di verbale dello studio (Web Pro Italia, 17/11/2026): l'elenco, la scrittura del modello con la guida dei
 * segnaposto, la prova su un'assemblea vera e il predefinito. Valgono per tutti i condomini. Il modello di serie si
 * può correggere o sostituire con quello dello studio; i verbali già generati non cambiano.
 *
 * La pagina porta quattro tipi di modello, uno per scheda: il verbale, l'avviso di convocazione (con il modulo di
 * delega), il testo dell'email della convocazione e quello della trasmissione del verbale (email e lettera). Ogni
 * tipo ha il suo predefinito, i suoi segnaposto e il suo modello di serie da cui parte un modello nuovo.
 */
import { computed, ref } from 'vue';
import axios from 'axios';
import { Head, router, usePage } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import Alert from '@/components/Alert.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { usePermission } from '@/composables/permissions';
import { Braces, Copy, FilePlus2, FileSignature, FlaskConical, Save, Star, Trash2 } from 'lucide-vue-next';
import type { Flash } from '@/types/flash';

interface Modello { id: number; tipo: string; nome: string; descrizione: string | null; corpo: string; predefinito: boolean; autore: string | null; cambiato_il: string | null; sconosciuti: string[] }
interface Tipo { tipo: string; nome: string; canonico: string; segnaposti: { semplici: Record<string, string>; punti: Record<string, string>; destinatario: Record<string, string> } }

const props = defineProps<{
    condominio: any; condomini: any; esercizio: any;
    modelli: Modello[];
    assemblee: { id: number; titolo: string }[];
    segnaposti: { semplici: Record<string, string>; punti: Record<string, string> };
    canonico: string;
    /** I tipi di modello: verbale, convocazione, email della convocazione, trasmissione del verbale. */
    tipi: Tipo[];
    puo: { gestire: boolean; approvare: boolean };
}>();

const { generatePath } = usePermission();
const page = usePage<{ flash?: { message?: Flash }; errors?: Record<string, string> }>();
const flashMessage = computed(() => page.props.flash?.message);
const errori = computed(() => page.props.errors ?? {});
const base = computed(() => generatePath('gestionale/:condominio/assemblee/modelli', { condominio: props.condominio.id }));

// Il tipo che si sta guardando: la pagina si apre sul verbale, o sul tipo chiesto con `?tipo=…` (dalla pagina della convocazione).
const tipoIniziale = new URLSearchParams(window.location.search).get('tipo');
const tipo = ref<string>(props.tipi.some((t) => t.tipo === tipoIniziale) ? (tipoIniziale as string) : 'verbale');
const datiDelTipo = computed<Tipo>(() => props.tipi.find((t) => t.tipo === tipo.value) ?? props.tipi[0]);
const modelliDelTipo = computed(() => props.modelli.filter((m) => m.tipo === tipo.value));
const eEmail = computed(() => tipo.value === 'email_convocazione' || tipo.value === 'trasmissione_verbale');

const vuoto = () => ({ id: null as number | null, nome: '', descrizione: '', corpo: datiDelTipo.value.canonico, predefinito: false });
const daModello = (m: Modello) => ({ id: m.id as number | null, nome: m.nome, descrizione: m.descrizione ?? '', corpo: m.corpo, predefinito: m.predefinito });
const scelto = ref(modelliDelTipo.value[0] ? daModello(modelliDelTipo.value[0]) : vuoto());
const apri = (m: Modello) => { scelto.value = daModello(m); prova.value = null; };
const nuovo = () => { scelto.value = vuoto(); prova.value = null; };
const cambiaTipo = (nuovoTipo: string) => {
    tipo.value = nuovoTipo;
    scelto.value = modelliDelTipo.value[0] ? daModello(modelliDelTipo.value[0]) : vuoto();
    prova.value = null;
};
const duplica = () => { scelto.value = { ...scelto.value, id: null, nome: `${scelto.value.nome} (copia)`, predefinito: false }; };
const sconosciuti = computed(() => props.modelli.find((m) => m.id === scelto.value.id)?.sconosciuti ?? []);

const opzioni = { preserveScroll: true, preserveState: true };
function salva() {
    const dati = { nome: scelto.value.nome, descrizione: scelto.value.descrizione || null, corpo: scelto.value.corpo, predefinito: scelto.value.predefinito };
    if (scelto.value.id) router.put(`${base.value}/${scelto.value.id}`, dati, opzioni);
    // Un modello nuovo nasce del tipo della scheda aperta; salvato, resta aperto lui.
    else router.post(base.value, { ...dati, tipo: tipo.value }, { ...opzioni, onSuccess: () => {
        const creato = modelliDelTipo.value.find((m) => m.nome === dati.nome.trim());
        if (creato) apri(creato);
    } });
}
const confermaElimina = ref(false);
function elimina() {
    confermaElimina.value = false;
    // Eliminato, la scheda resta sul suo tipo e apre il primo modello che resta.
    if (scelto.value.id) router.delete(`${base.value}/${scelto.value.id}`, { ...opzioni, onSuccess: () => cambiaTipo(tipo.value) });
}

// La prova su un'assemblea vera: il testo che uscirebbe, senza salvare niente.
const assembleaDiProva = ref<number | null>(props.assemblee[0]?.id ?? null);
const prova = ref<{ testo: string; sconosciuti: string[] } | null>(null);
const provaInCorso = ref(false);
async function provaModello() {
    if (!assembleaDiProva.value) return;
    provaInCorso.value = true;
    try {
        const { data } = await axios.post(`${base.value}/prova`, { corpo: scelto.value.corpo, assemblea_id: assembleaDiProva.value, tipo: tipo.value });
        prova.value = data;
    } finally {
        provaInCorso.value = false;
    }
}
const inserisci = (chiave: string) => { scelto.value.corpo += `{{${chiave}}}`; };

const pageGuides = [
    { title: 'Il modello dello studio', description: 'Il testo del verbale come lo scrive lo studio, con i segnaposto al posto dei dati: il programma li riempie per ogni assemblea.', icon: FileSignature, colorVariant: 'blue' as const },
    { title: 'I segnaposto', description: 'Fra doppie graffe: {{presidente}}, {{costituzione}}… e il blocco {{#punti}} … {{/punti}} che si ripete per ogni punto. L\'elenco è qui sotto.', icon: Braces, colorVariant: 'amber' as const },
    { title: 'Prova prima', description: 'Prova il modello su un\'assemblea vera e leggi quello che uscirebbe. Un segnaposto sbagliato resta visibile com\'è.', icon: FlaskConical, colorVariant: 'emerald' as const },
];
const breadcrumbs = computed(() => [
    { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
    { title: 'Assemblee', href: generatePath('gestionale/:condominio/assemblee', { condominio: props.condominio.id }) },
    { title: 'Modelli' },
]);
</script>

<template>
    <Head title="Modelli dell'assemblea" />
    <GestionaleLayout>
        <div class="px-6 py-6 space-y-4">
            <PageHeaderGuide
                page-title="Modelli dell'assemblea"
                page-subtitle="Il verbale, l'avviso di convocazione e i testi degli invii dello studio, con i segnaposto che il programma riempie. Valgono per tutti i condomini."
                :guides="pageGuides"
                :breadcrumbs="(breadcrumbs as any)"
                :condominio="(props.condominio as any)"
                :condomini="(props.condomini as any)"
                :back-url="generatePath('gestionale/:condominio/assemblee', { condominio: props.condominio.id })"
                back-text="Assemblee"
            />
            <div v-if="flashMessage"><Alert :message="flashMessage.message" :type="flashMessage.type" /></div>
            <div v-if="Object.keys(errori).length" class="rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800 dark:bg-rose-950/40 dark:text-rose-200">
                <p v-for="(m, k) in errori" :key="k">{{ m }}</p>
            </div>

            <!-- I tipi di modello -->
            <div class="flex flex-wrap gap-1 border-b" data-testid="tipi-modello">
                <button v-for="t in tipi" :key="t.tipo" type="button" class="px-4 py-2 text-sm font-semibold border-b-2 -mb-px"
                    :class="tipo === t.tipo ? 'border-slate-900 text-slate-900 dark:border-white dark:text-white' : 'border-transparent text-slate-500'"
                    :data-testid="`tipo-${t.tipo}`" @click="cambiaTipo(t.tipo)">{{ t.nome }} ({{ modelli.filter((m) => m.tipo === t.tipo).length }})</button>
            </div>

            <div class="grid gap-4 xl:grid-cols-[280px_1fr_360px]">
                <!-- L'elenco -->
                <div class="rounded-xl border bg-white dark:bg-slate-900 divide-y dark:divide-slate-800 self-start">
                    <button v-for="m in modelliDelTipo" :key="m.id" type="button" class="w-full text-left p-3 text-sm hover:bg-slate-50 dark:hover:bg-slate-800/50"
                        :class="m.id === scelto.id ? 'bg-slate-100 dark:bg-slate-800' : ''" @click="apri(m)">
                        <span class="font-semibold flex items-center gap-1"><Star v-if="m.predefinito" class="w-3.5 h-3.5 text-amber-500" /> {{ m.nome }}</span>
                        <span class="block text-xs text-slate-500">{{ m.cambiato_il }}<template v-if="m.autore"> · {{ m.autore }}</template></span>
                    </button>
                    <div class="p-3"><Button v-if="puo.gestire" variant="outline" size="sm" class="w-full gap-2" @click="nuovo"><FilePlus2 class="w-4 h-4" /> Nuovo modello</Button></div>
                </div>

                <!-- Il modello -->
                <div class="rounded-xl border bg-white dark:bg-slate-900 p-4 space-y-3">
                    <div class="grid gap-3 md:grid-cols-2">
                        <label class="text-sm space-y-1"><span class="block font-medium">Nome</span><Input v-model="scelto.nome" :disabled="!puo.gestire" class="h-9" data-testid="nome-modello" /></label>
                        <label class="text-sm space-y-1"><span class="block font-medium">Descrizione</span><Input v-model="scelto.descrizione" :disabled="!puo.gestire" class="h-9" /></label>
                    </div>
                    <label class="flex items-center gap-2 text-sm"><input v-model="scelto.predefinito" type="checkbox" :disabled="!puo.gestire" class="h-4 w-4" /> Predefinito: è quello che il programma propone per «{{ datiDelTipo.nome }}»</label>
                    <p v-if="eEmail" class="text-xs text-slate-600 rounded-md bg-slate-50 dark:bg-slate-800 px-3 py-2">La prima riga «Oggetto: …» è l'oggetto dell'email. Il saluto «Gentile …,» e la chiusura con il nome dello studio li mette il programma: qui si scrive solo il testo in mezzo.</p>
                    <p v-if="tipo === 'convocazione'" class="text-xs text-slate-600 rounded-md bg-slate-50 dark:bg-slate-800 px-3 py-2">Una riga con soli tre trattini (<code>---</code>) stacca quello che segue con una linea tratteggiata: è il modulo di delega. I segnaposto del destinatario restano nel testo generato e si riempiono alla stampa.</p>
                    <Textarea v-model="scelto.corpo" :disabled="!puo.gestire" rows="30" class="font-mono text-[13px] leading-relaxed" data-testid="corpo-modello" />
                    <p v-if="sconosciuti.length" class="text-sm text-amber-800">Segnaposto che il programma non conosce (restano scritti così nel testo): {{ sconosciuti.join(', ') }}</p>
                    <div class="flex flex-wrap gap-2">
                        <Button v-if="puo.gestire" class="gap-2" data-testid="salva-modello" @click="salva"><Save class="w-4 h-4" /> Salva</Button>
                        <Button v-if="puo.gestire && scelto.id" variant="outline" class="gap-2" @click="duplica"><Copy class="w-4 h-4" /> Duplica</Button>
                        <Button v-if="puo.gestire && scelto.id" variant="ghost" class="gap-2 text-rose-600 ml-auto" @click="confermaElimina = true"><Trash2 class="w-4 h-4" /> Elimina</Button>
                    </div>
                    <div class="border-t pt-3 space-y-2">
                        <div class="flex flex-wrap items-center gap-2 text-sm">
                            <span class="font-medium">Prova su</span>
                            <select v-model="assembleaDiProva" class="h-9 rounded-md border px-2 bg-transparent dark:bg-slate-900 min-w-[260px]">
                                <option v-for="x in assemblee" :key="x.id" :value="x.id">{{ x.titolo }}</option>
                            </select>
                            <Button variant="outline" :disabled="!assembleaDiProva || provaInCorso" class="gap-2" @click="provaModello"><FlaskConical class="w-4 h-4" /> Prova</Button>
                            <span v-if="!assemblee.length" class="text-slate-500">(nessuna assemblea in questo condominio)</span>
                        </div>
                        <template v-if="prova">
                            <p v-if="prova.sconosciuti.length" class="text-sm text-amber-800">Non riconosciuti: {{ prova.sconosciuti.join(', ') }}</p>
                            <pre class="whitespace-pre-wrap text-[13px] rounded-md border bg-slate-50 dark:bg-slate-800 p-3 max-h-[520px] overflow-auto">{{ prova.testo }}</pre>
                        </template>
                    </div>
                </div>

                <!-- La guida dei segnaposto -->
                <div class="rounded-xl border bg-white dark:bg-slate-900 p-4 space-y-3 text-sm self-start" data-testid="guida-segnaposto">
                    <h3 class="font-semibold">I segnaposto</h3>
                    <p class="text-xs text-slate-600">Si scrivono fra doppie graffe, per esempio <code v-pre>{{condominio.nome}}</code>. Un dato che manca e che il testo deve avere esce come «______»; uno facoltativo esce vuoto.
                        <code v-pre>{{#chiave}} … {{/chiave}}</code> mostra quello che c'è in mezzo solo se il dato non è vuoto. Un segnaposto sconosciuto resta scritto com'è. Clicca un nome per aggiungerlo in fondo al modello.</p>
                    <dl class="space-y-1.5">
                        <div v-for="(descr, chiave) in datiDelTipo.segnaposti.semplici" :key="chiave">
                            <dt><button type="button" class="font-mono text-xs text-blue-700 dark:text-blue-300 hover:underline" :disabled="!puo.gestire" @click="inserisci(chiave)" v-text="`{{${chiave}}}`"></button></dt>
                            <dd class="text-xs text-slate-600 dark:text-slate-400">{{ descr }}</dd>
                        </div>
                    </dl>
                    <h4 class="font-semibold pt-2">Il blocco dei punti</h4>
                    <p class="text-xs text-slate-600"><code v-pre>{{#punti}} … {{/punti}}</code> si ripete per ogni punto dell'ordine del giorno. Dentro valgono:</p>
                    <dl class="space-y-1.5">
                        <div v-for="(descr, chiave) in datiDelTipo.segnaposti.punti" :key="chiave">
                            <dt class="font-mono text-xs text-blue-700 dark:text-blue-300" v-text="`{{${chiave}}}`"></dt>
                            <dd class="text-xs text-slate-600 dark:text-slate-400">{{ descr }}</dd>
                        </div>
                    </dl>
                    <template v-if="Object.keys(datiDelTipo.segnaposti.destinatario).length">
                        <h4 class="font-semibold pt-2">Chi riceve</h4>
                        <p class="text-xs text-slate-600">Si riempiono per ogni persona, alla stampa e nell'email:</p>
                        <dl class="space-y-1.5">
                            <div v-for="(descr, chiave) in datiDelTipo.segnaposti.destinatario" :key="chiave">
                                <dt><button type="button" class="font-mono text-xs text-blue-700 dark:text-blue-300 hover:underline" :disabled="!puo.gestire" @click="inserisci(chiave)" v-text="`{{${chiave}}}`"></button></dt>
                                <dd class="text-xs text-slate-600 dark:text-slate-400">{{ descr }}</dd>
                            </div>
                        </dl>
                    </template>
                    <h4 class="font-semibold pt-2">Il markup</h4>
                    <p class="text-xs text-slate-600">«# » all'inizio della riga per un titolo, <code>**testo**</code> per il grassetto, «- » per un elenco, una riga vuota fra i paragrafi.</p>
                </div>
            </div>
        </div>
        <ConfirmDialog
            :model-value="confermaElimina"
            title="Eliminare il modello?"
            description="I testi già generati con questo modello non cambiano. Se era il predefinito, lo diventa il primo che resta dello stesso tipo."
            confirm-text="Elimina"
            variant="destructive"
            @update:model-value="(x: boolean) => { if (!x) confermaElimina = false; }"
            @confirm="elimina"
        />
    </GestionaleLayout>
</template>
