<script setup lang="ts">
/**
 * Il verbale dell'assemblea (Web Pro Italia, 17/11/2026): si genera dal modello dello studio con i dati dell'assemblea,
 * poi il testo è libero e si corregge per intero. Discussione e delibera dei punti hanno i loro campi (le fonti dei
 * segnaposto); intestazione, apertura e chiusura sono testi facoltativi che un modello può richiamare.
 *
 * Stati: bozza → da approvare → approvato. Approva una persona con il permesso «Approva verbali»; il PDF va
 * nell'archivio del condominio e il verbale non si modifica più, finché non si apre una nuova versione.
 */
import { computed, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import Alert from '@/components/Alert.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import AssembleeGuide from '@/components/gestionale/assemblee/AssembleeGuide.vue';
import { usePermission } from '@/composables/permissions';
import { BadgeCheck, FileDown, FileSignature, RefreshCw, Save, ScrollText, Send, Undo2, Wand2 } from 'lucide-vue-next';
import { NOMI_ESITO, NOMI_STATO_VERBALE, type Fotografia } from '@/lib/gestionale/assemblee';
import type { Flash } from '@/types/flash';

const props = defineProps<{
    condominio: any; condomini: any; esercizio: any;
    fotografia: Fotografia;
    modelli: { id: number; nome: string; descrizione: string | null; predefinito: boolean }[];
    proposta: string;
    segnaposti: { semplici: Record<string, string>; punti: Record<string, string> };
    puo: { gestire: boolean; approvare: boolean };
}>();

const { generatePath } = usePermission();
const page = usePage<{ flash?: { message?: Flash }; errors?: Record<string, string> }>();
const flashMessage = computed(() => page.props.flash?.message);
const errori = computed(() => page.props.errors ?? {});

const a = computed(() => props.fotografia.assemblea);
const v = computed(() => props.fotografia.verbale);
const base = computed(() => generatePath('gestionale/:condominio/assemblee/:assemblea', { condominio: props.condominio.id, assemblea: a.value.id }));
const scrivibile = computed(() => props.puo.gestire && v.value.stato === 'bozza');
const opzioni = { preserveScroll: true, preserveState: true };
const mostraGuida = ref(false);

// --- Il testo ------------------------------------------------------------------------------------------------
const testo = ref(v.value.testo ?? '');
const altri = ref({ intestazione: v.value.intestazione ?? '', apertura: v.value.apertura ?? '', chiusura: v.value.chiusura ?? '' });
const punti = ref(props.fotografia.punti.map((p) => ({ id: p.id, discussione: p.discussione ?? '', delibera: p.delibera ?? '' })));
watch(() => props.fotografia, (f) => {
    testo.value = f.verbale.testo ?? '';
    altri.value = { intestazione: f.verbale.intestazione ?? '', apertura: f.verbale.apertura ?? '', chiusura: f.verbale.chiusura ?? '' };
    punti.value = f.punti.map((p) => ({ id: p.id, discussione: p.discussione ?? '', delibera: p.delibera ?? '' }));
});
const modello = ref<number | null>(v.value.modello_id ?? props.modelli.find((m) => m.predefinito)?.id ?? props.modelli[0]?.id ?? null);
const mostraProposta = ref(false);
const mostraAltri = ref(!!(v.value.intestazione || v.value.apertura || v.value.chiusura));

function salvaTesto() {
    router.put(`${base.value}/verbale`, { testo: testo.value, ...altri.value }, opzioni);
}
function salvaPunti() {
    router.put(`${base.value}/verbale`, { punti: punti.value }, opzioni);
}
const confermaGenera = ref(false);
function genera(sovrascrivi = false) {
    if (!sovrascrivi && (v.value.testo ?? '').trim() !== '') {
        confermaGenera.value = true;
        return;
    }
    confermaGenera.value = false;
    router.post(`${base.value}/verbale/genera`, { modello_id: modello.value, sovrascrivi }, opzioni);
}

// --- Gli stati -----------------------------------------------------------------------------------------------
const confermaApprova = ref(false);
const confermaRiapri = ref(false);
const azione = (percorso: string) => router.post(`${base.value}/verbale/${percorso}`, {}, opzioni);
const stampa = () => window.open(`${base.value}/verbale/stampa`, '_blank');
const cambiato = computed(() => testo.value !== (v.value.testo ?? '')
    || altri.value.intestazione !== (v.value.intestazione ?? '') || altri.value.apertura !== (v.value.apertura ?? '') || altri.value.chiusura !== (v.value.chiusura ?? ''));

const pageGuides = [
    { title: 'Dal modello', description: 'Scegli il modello dello studio e genera: il programma riempie date, presenze, costituzione, voti con i nomi ed esiti.', icon: Wand2, colorVariant: 'blue' as const },
    { title: 'Testo libero', description: 'Il testo generato si corregge per intero, a mano o da un assistente. Rigenerare lo sovrascrive, e lo chiede prima.', icon: ScrollText, colorVariant: 'amber' as const },
    { title: 'Approvazione', description: 'Lo approva una persona: il PDF va nell\'archivio del condominio e il verbale non si modifica più.', icon: BadgeCheck, colorVariant: 'emerald' as const },
];
const breadcrumbs = computed(() => [
    { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
    { title: 'Assemblee', href: generatePath('gestionale/:condominio/assemblee', { condominio: props.condominio.id }) },
    { title: a.value.titolo, href: base.value },
    { title: 'Verbale' },
]);
const coloreStato: Record<string, string> = {
    bozza: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
    da_approvare: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300',
    approvato: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300',
};
</script>

<template>
    <Head :title="`Verbale — ${a.titolo}`" />
    <GestionaleLayout>
        <div class="px-6 py-6 space-y-4">
            <PageHeaderGuide
                page-title="Verbale dell'assemblea"
                :page-subtitle="a.titolo"
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
                    <Link v-if="puo.gestire" :href="generatePath('gestionale/:condominio/assemblee/modelli', { condominio: props.condominio.id })">
                        <Button variant="outline" class="h-8 px-3 gap-2 bg-white dark:bg-slate-900"><FileSignature class="w-4 h-4" /> Modelli</Button>
                    </Link>
                    <Button variant="outline" class="h-8 px-3 gap-2 bg-white dark:bg-slate-900" data-testid="stampa-verbale" @click="stampa"><FileDown class="w-4 h-4" /> {{ v.stato === 'approvato' ? 'PDF approvato' : 'Stampa la bozza' }}</Button>
                </template>
            </PageHeaderGuide>

            <div v-if="flashMessage"><Alert :message="flashMessage.message" :type="flashMessage.type" /></div>
            <div v-if="Object.keys(errori).length" class="rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800 dark:bg-rose-950/40 dark:border-rose-900 dark:text-rose-200">
                <p v-for="(m, k) in errori" :key="k">{{ m }}</p>
            </div>

            <!-- Lo stato e le azioni -->
            <div class="rounded-xl border bg-white dark:bg-slate-900 p-4 flex flex-wrap items-center gap-3">
                <span class="text-sm rounded-full px-3 py-1 font-semibold" :class="coloreStato[v.stato]" data-testid="stato-verbale">{{ NOMI_STATO_VERBALE[v.stato] }}</span>
                <span class="text-sm text-slate-500">versione {{ v.versione }}<template v-if="v.modello"> · generato da «{{ v.modello }}»</template></span>
                <span v-if="v.approvato_at" class="text-sm text-slate-600">approvato il {{ v.approvato_at }} da {{ v.approvato_da }}</span>
                <span v-if="v.documento" class="text-sm text-slate-600">· nell'archivio: «{{ v.documento.nome }}»</span>
                <div class="ml-auto flex flex-wrap gap-2">
                    <Button v-if="v.stato === 'bozza' && puo.gestire" class="gap-2" data-testid="invia-verbale" @click="azione('invia')"><Send class="w-4 h-4" /> Manda in approvazione</Button>
                    <Button v-if="v.stato === 'da_approvare' && puo.gestire" variant="outline" class="gap-2" @click="azione('ritira')"><Undo2 class="w-4 h-4" /> Torna in bozza</Button>
                    <Button v-if="v.stato === 'da_approvare' && puo.approvare" class="gap-2 bg-emerald-600 hover:bg-emerald-700" data-testid="approva-verbale" @click="confermaApprova = true"><BadgeCheck class="w-4 h-4" /> Approva</Button>
                    <Button v-if="v.stato === 'approvato' && puo.approvare" variant="outline" class="gap-2" @click="confermaRiapri = true"><RefreshCw class="w-4 h-4" /> Apri una nuova versione</Button>
                </div>
            </div>

            <!-- Il testo -->
            <div class="rounded-xl border bg-white dark:bg-slate-900 p-4 space-y-3">
                <div class="flex flex-wrap items-end gap-2">
                    <h3 class="font-semibold mr-auto">Il testo del verbale</h3>
                    <label class="text-sm flex items-center gap-2">Modello
                        <select v-model="modello" :disabled="!scrivibile" class="h-9 rounded-md border px-2 bg-transparent dark:bg-slate-900 min-w-[260px]">
                            <option v-for="m in modelli" :key="m.id" :value="m.id">{{ m.nome }}{{ m.predefinito ? ' (predefinito)' : '' }}</option>
                        </select>
                    </label>
                    <Button :disabled="!scrivibile" variant="outline" class="gap-2" data-testid="genera-verbale" @click="genera(false)"><Wand2 class="w-4 h-4" /> {{ v.testo ? 'Rigenera dal modello' : 'Genera dal modello' }}</Button>
                    <Button :disabled="!scrivibile" :variant="cambiato ? 'default' : 'outline'" class="gap-2" data-testid="salva-verbale" title="Salvare il testo, anche senza cambiarlo, dice che lo hai riletto con i dati di adesso" @click="salvaTesto"><Save class="w-4 h-4" /> Salva il testo</Button>
                </div>
                <p class="text-xs text-slate-500">Si scrive come la nota del fascicolo: una riga che comincia con «# » è un titolo, <code>**testo**</code> è in grassetto, le righe con «- » sono un elenco, una riga vuota separa i paragrafi. Nel PDF ogni riga resta una riga.</p>
                <p v-if="!v.testo" class="text-sm rounded-md bg-amber-50 text-amber-900 px-3 py-2 dark:bg-amber-950/40 dark:text-amber-200">
                    Il verbale non ha ancora un testo: generalo dal modello (o scrivilo). Finché non c'è, la stampa della bozza mostra quello che darebbe il modello predefinito.
                </p>
                <Textarea v-model="testo" :disabled="!scrivibile" rows="28" class="font-mono text-[13px] leading-relaxed" data-testid="testo-verbale" />
                <div>
                    <button type="button" class="text-sm underline text-slate-600" @click="mostraProposta = !mostraProposta">{{ mostraProposta ? 'Nascondi' : 'Mostra' }} quello che il modello darebbe adesso, con i dati di oggi</button>
                    <pre v-if="mostraProposta" class="mt-2 whitespace-pre-wrap text-[13px] rounded-md border bg-slate-50 dark:bg-slate-800 p-3 max-h-[480px] overflow-auto">{{ proposta }}</pre>
                </div>
            </div>

            <!-- I punti -->
            <div class="rounded-xl border bg-white dark:bg-slate-900 p-4 space-y-4">
                <div class="flex items-center gap-2">
                    <h3 class="font-semibold mr-auto">Discussione e delibera dei punti</h3>
                    <Button :disabled="!scrivibile" variant="outline" class="gap-2" @click="salvaPunti"><Save class="w-4 h-4" /> Salva i punti</Button>
                </div>
                <p class="text-xs text-slate-500">Entrano nel testo quando lo generi dal modello. Un assistente (o la registrazione) può proporli: riempie solo quelli vuoti.</p>
                <div v-for="(p, i) in fotografia.punti" :key="p.id" class="rounded-lg border p-3 space-y-2">
                    <div class="flex flex-wrap items-baseline gap-2">
                        <span class="font-semibold">{{ p.numero }}. {{ p.titolo }}</span>
                        <span class="text-xs text-slate-500">
                            {{ p.maggioranza === 'informativa' ? 'informativo' : p.esito ? `esito: ${NOMI_ESITO[p.esito]}` : `esito da confermare (i voti dicono: ${p.votazione.esito_proposto ?? 'nessun voto'})` }}
                        </span>
                    </div>
                    <div class="grid gap-2 lg:grid-cols-2">
                        <label class="text-sm space-y-1"><span class="block text-slate-600">Discussione</span><Textarea v-model="punti[i].discussione" :disabled="!scrivibile" rows="4" /></label>
                        <label class="text-sm space-y-1"><span class="block text-slate-600">Delibera</span><Textarea v-model="punti[i].delibera" :disabled="!scrivibile" rows="4" /></label>
                    </div>
                </div>
            </div>

            <!-- I testi facoltativi -->
            <div class="rounded-xl border bg-white dark:bg-slate-900 p-4 space-y-3">
                <button type="button" class="font-semibold text-left" @click="mostraAltri = !mostraAltri">{{ mostraAltri ? '▾' : '▸' }} Testi facoltativi: intestazione, apertura, chiusura</button>
                <template v-if="mostraAltri">
                    <p class="text-xs text-slate-500">Un modello li richiama con <code v-pre>{{intestazione}}</code>, <code v-pre>{{apertura}}</code>, <code v-pre>{{chiusura}}</code>: il modello di serie mette l'apertura dopo la costituzione e la chiusura prima della frase finale. Si salvano con «Salva il testo».</p>
                    <label class="text-sm space-y-1 block"><span class="block text-slate-600">Intestazione</span><Textarea v-model="altri.intestazione" :disabled="!scrivibile" rows="3" /></label>
                    <label class="text-sm space-y-1 block"><span class="block text-slate-600">Apertura</span><Textarea v-model="altri.apertura" :disabled="!scrivibile" rows="3" /></label>
                    <label class="text-sm space-y-1 block"><span class="block text-slate-600">Chiusura</span><Textarea v-model="altri.chiusura" :disabled="!scrivibile" rows="3" /></label>
                </template>
            </div>
        </div>

        <ConfirmDialog
            :model-value="confermaGenera"
            title="Sostituire il testo del verbale?"
            description="Il verbale ha già un testo: generarlo di nuovo dal modello lo sostituisce per intero, con le correzioni fatte a mano."
            confirm-text="Sostituisci"
            cancel-text="Lascia com'è"
            variant="warning"
            @update:model-value="(x: boolean) => { if (!x) confermaGenera = false; }"
            @confirm="genera(true)"
        />
        <ConfirmDialog
            :model-value="confermaApprova"
            title="Approvare il verbale?"
            description="Il PDF del verbale va nell'archivio dei documenti del condominio e da qui in poi niente dell'assemblea si modifica più. Per correggerlo servirà una nuova versione."
            confirm-text="Approva"
            @update:model-value="(x: boolean) => { if (!x) confermaApprova = false; }"
            @confirm="confermaApprova = false; azione('approva')"
        />
        <ConfirmDialog
            :model-value="confermaRiapri"
            title="Aprire una nuova versione?"
            description="Il verbale torna in bozza con il numero di versione che sale. Il PDF approvato resta nell'archivio; alla prossima approvazione se ne aggiunge un altro."
            confirm-text="Apri la nuova versione"
            variant="warning"
            @update:model-value="(x: boolean) => { if (!x) confermaRiapri = false; }"
            @confirm="confermaRiapri = false; azione('riapri')"
        />
        <AssembleeGuide v-model:open="mostraGuida" />
    </GestionaleLayout>
</template>
