<script setup lang="ts">
/**
 * Gli invii dell'assemblea (Web Pro Italia, 17/11/2026): la stessa finestra per l'avviso di convocazione e per il
 * verbale approvato. Mostra chi riceve che cosa (gli indirizzi email di ognuno, chi non ne ha), che cosa è già stato
 * mandato a ogni persona e come, e fa due cose: manda per email a chi ha un indirizzo (con il PDF allegato) e registra
 * la spedizione fatta fuori dal programma (raccomandata, PEC, consegna a mano). Ogni invio resta nel registro, per
 * persona: è la prova di chi è stato convocato e di quando il verbale è stato comunicato agli assenti.
 *
 * L'email parte solo dopo la conferma, alle persone spuntate. Chi l'ha già ricevuta con lo stesso testo viene saltato,
 * se non lo si chiede. I conti e le regole stanno sul server: questa finestra legge l'anteprima e la rilegge dopo ogni
 * invio.
 */
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { CircleAlert, FileDown, Mail, MailCheck, PackageCheck, Send, Undo2 } from 'lucide-vue-next';
import { NOMI_STATO_INVIO, giorno, giornoEOra, type AnteprimaInvio, type EsitoInvio, type PersonaInvio } from '@/lib/gestionale/assemblee';

const props = defineProps<{
    cosa: 'convocazione' | 'verbale';
    /** L'indirizzo dell'assemblea: `gestionale/:condominio/assemblee/:assemblea`. */
    base: string;
    invio: AnteprimaInvio;
    puoGestire: boolean;
}>();
const emit = defineEmits<{ (e: 'aggiornato'): void }>();

const anteprima = ref<AnteprimaInvio>(props.invio);
const scelte = ref<number[]>(props.invio.persone.filter((p) => p.proposta).map((p) => p.id));
const indirizzi = ref<string[]>([...props.invio.indirizzi]);
const chi = ref<'assenti' | 'tutti'>(props.invio.chi === 'tutti' ? 'tutti' : 'assenti');
const oggetto = ref(props.invio.oggetto);
const testo = ref(props.invio.testo);
const ancheGiaMandate = ref(false);
const inCorso = ref(false);
const errore = ref<string | null>(null);
const esito = ref<EsitoInvio | null>(null);
const messaggio = ref<string | null>(null);
const aperta = ref<number | null>(null);

watch(() => props.invio, (nuova) => { anteprima.value = nuova; });

const url = computed(() => `${props.base}/invii/${props.cosa}`);
const nome = computed(() => (props.cosa === 'convocazione' ? 'la convocazione' : 'il verbale'));
const sceltePersone = computed(() => anteprima.value.persone.filter((p) => scelte.value.includes(p.id)));
const conEmail = computed(() => sceltePersone.value.filter((p) => !p.senza_email));
const senzaEmail = computed(() => sceltePersone.value.filter((p) => p.senza_email));
const quantiMessaggi = computed(() => conEmail.value.reduce((n, p) => n + p.indirizzi.length, 0));
const tutteScelte = computed(() => anteprima.value.persone.length > 0 && scelte.value.length === anteprima.value.persone.length);

function frase(e: any): string {
    const dati = e?.response?.data;
    const primo = dati?.errors ? (Object.values(dati.errors)[0] as string[])[0] : null;
    return primo ?? dati?.message ?? 'Non è riuscito: riprova.';
}

/** Rilegge l'anteprima dal server; con `riproponi` le spunte tornano quelle proposte (gli assenti, chi non l'ha ancora avuta). */
async function rileggi(riproponi = false) {
    try {
        // Senza nessun tipo spuntato si chiede un tipo che non esiste: il server altrimenti proporrebbe primaria e secondaria.
        const { data } = await axios.get(url.value, { params: { indirizzi: indirizzi.value.length ? indirizzi.value : ['nessuno'], chi: chi.value } });
        anteprima.value = data;
        if (riproponi) scelte.value = (data as AnteprimaInvio).persone.filter((p) => p.proposta).map((p) => p.id);
    } catch (e) {
        errore.value = frase(e);
    }
}
const cambiaTipo = (tipo: string) => {
    indirizzi.value = indirizzi.value.includes(tipo) ? indirizzi.value.filter((t) => t !== tipo) : [...indirizzi.value, tipo];
    rileggi();
};
const cambiaChi = (valore: 'assenti' | 'tutti') => { chi.value = valore; rileggi(true); };
const spunta = (p: PersonaInvio) => { scelte.value = scelte.value.includes(p.id) ? scelte.value.filter((id) => id !== p.id) : [...scelte.value, p.id]; };
const spuntaTutte = () => { scelte.value = tutteScelte.value ? [] : anteprima.value.persone.map((p) => p.id); };

// --- L'invio per email -------------------------------------------------------------------------------------------
const conferma = ref(false);
async function manda() {
    conferma.value = false;
    inCorso.value = true;
    errore.value = null;
    esito.value = null;
    messaggio.value = null;
    try {
        const { data } = await axios.post(url.value, {
            conferma: true,
            persone: conEmail.value.map((p) => p.id),
            indirizzi: indirizzi.value,
            oggetto: oggetto.value,
            testo: testo.value,
            anche_gia_mandate: ancheGiaMandate.value,
        });
        esito.value = data;
        await rileggi(true);
        emit('aggiornato');
    } catch (e) {
        errore.value = frase(e);
    } finally {
        inCorso.value = false;
    }
}

// --- La spedizione registrata a mano -----------------------------------------------------------------------------
const oggi = new Date().toLocaleDateString('sv-SE');
const registra = ref(false);
const spedizione = ref({ canale: 'raccomandata', data: oggi, nota: '' });
const numeri = ref<Record<number, string>>({});
async function registraSpedizione() {
    inCorso.value = true;
    errore.value = null;
    messaggio.value = null;
    try {
        const { data } = await axios.post(`${url.value}/spedizione`, {
            persone: scelte.value,
            canale: spedizione.value.canale,
            data: spedizione.value.data,
            numeri: Object.fromEntries(Object.entries(numeri.value).filter(([id, n]) => scelte.value.includes(Number(id)) && n.trim() !== '')),
            nota: spedizione.value.nota || null,
        });
        messaggio.value = data.registrate === 1 ? 'Spedizione registrata per 1 persona.' : `Spedizione registrata per ${data.registrate} persone.`;
        registra.value = false;
        numeri.value = {};
        await rileggi(true);
        emit('aggiornato');
    } catch (e) {
        errore.value = frase(e);
    } finally {
        inCorso.value = false;
    }
}
async function annulla(id: number) {
    errore.value = null;
    try {
        await axios.delete(`${props.base}/invii/${id}`);
        await rileggi();
        emit('aggiornato');
    } catch (e) {
        errore.value = frase(e);
    }
}

// --- Le stampe ---------------------------------------------------------------------------------------------------
const pdfDellaPersona = (p: PersonaInvio) => window.open(props.cosa === 'convocazione'
    ? `${props.base}/convocazione/stampa?persona=${p.id}`
    : `${props.base}/verbale/trasmissione/stampa?persone[]=${p.id}`, '_blank');
const lettereDelleScelte = () => window.open(`${props.base}/verbale/trasmissione/stampa?${scelte.value.map((id) => `persone[]=${id}`).join('&')}`, '_blank');

const statoDi = (p: PersonaInvio) => {
    if (p.mandata) return { testo: `${p.come === 'email' ? 'Mandata per email' : `Spedita: ${p.come}`} il ${giorno(p.quando)}${p.testo_cambiato ? ' (il testo è cambiato dopo)' : ''}`, colore: 'text-emerald-700 dark:text-emerald-400' };
    if (p.in_coda) return { testo: 'In coda: sta partendo', colore: 'text-blue-700 dark:text-blue-300' };
    if (p.non_partita) return { testo: 'Non partita', colore: 'text-rose-700 dark:text-rose-400' };
    return { testo: 'Non ancora mandata', colore: 'text-slate-500' };
};
</script>

<template>
    <div class="rounded-xl border bg-white dark:bg-slate-900 p-4 space-y-4" :data-testid="`invio-${cosa}`">
        <div class="flex flex-wrap items-center gap-3">
            <h3 class="font-semibold mr-auto flex items-center gap-2"><Mail class="w-4 h-4" /> {{ cosa === 'convocazione' ? 'Manda la convocazione' : 'Manda il verbale' }}</h3>
            <span class="text-sm text-slate-500">
                {{ anteprima.riepilogo.persone }} {{ anteprima.riepilogo.persone === 1 ? 'persona' : 'persone' }} ·
                {{ anteprima.riepilogo.gia_mandate }} già {{ anteprima.riepilogo.gia_mandate === 1 ? 'raggiunta' : 'raggiunte' }}
                <template v-if="anteprima.riepilogo.in_coda"> · {{ anteprima.riepilogo.in_coda }} in coda</template>
                <template v-if="anteprima.riepilogo.non_partite"> · <span class="text-rose-700">{{ anteprima.riepilogo.non_partite }} non partite</span></template>
            </span>
        </div>

        <p v-if="!anteprima.si_puo_mandare" class="text-sm rounded-md bg-amber-50 text-amber-900 px-3 py-2 dark:bg-amber-950/40 dark:text-amber-200" data-testid="perche-no">{{ anteprima.perche_no }}</p>
        <p v-if="anteprima.senza_server_posta" class="text-sm rounded-md bg-amber-50 text-amber-900 px-3 py-2 dark:bg-amber-950/40 dark:text-amber-200">
            Nessun server di posta configurato: le email non partono. Configuralo nelle impostazioni della posta; intanto puoi stampare e registrare la spedizione.
        </p>
        <p v-if="anteprima.riepilogo.testo_cambiato" class="text-sm rounded-md bg-amber-50 text-amber-900 px-3 py-2 dark:bg-amber-950/40 dark:text-amber-200">
            Il testo è cambiato dopo che {{ nome }} è {{ anteprima.riepilogo.testo_cambiato === 1 ? 'stata mandata a 1 persona' : `stata mandata a ${anteprima.riepilogo.testo_cambiato} persone` }}:
            se la modifica conta, spuntale e rimandala.
        </p>
        <p v-if="cosa === 'verbale' && !anteprima.elenco_preparato" class="text-sm rounded-md bg-amber-50 text-amber-900 px-3 py-2 dark:bg-amber-950/40 dark:text-amber-200">
            L'elenco delle presenze non è stato preparato: il programma non sa chi era assente. Scegli «a tutti i condòmini», o spunta le persone a mano.
        </p>
        <p v-if="errore" class="text-sm rounded-md border border-rose-200 bg-rose-50 text-rose-800 px-3 py-2 dark:bg-rose-950/40 dark:text-rose-200" data-testid="errore-invio"><CircleAlert class="inline w-4 h-4 mr-1" />{{ errore }}</p>
        <p v-if="messaggio" class="text-sm rounded-md bg-emerald-50 text-emerald-800 px-3 py-2 dark:bg-emerald-950/40 dark:text-emerald-200">{{ messaggio }}</p>
        <div v-if="esito" class="text-sm rounded-md bg-emerald-50 text-emerald-900 px-3 py-2 space-y-1 dark:bg-emerald-950/40 dark:text-emerald-200" data-testid="esito-invio">
            <p><MailCheck class="inline w-4 h-4 mr-1" />
                {{ esito.messaggi }} {{ esito.messaggi === 1 ? 'messaggio' : 'messaggi' }} a {{ esito.persone }} {{ esito.persone === 1 ? 'persona' : 'persone' }}:
                {{ esito.inviate }} {{ esito.inviate === 1 ? 'partito' : 'partiti' }}, {{ esito.in_coda }} in coda<template v-if="esito.non_partite">, <strong class="text-rose-700">{{ esito.non_partite }} non {{ esito.non_partite === 1 ? 'partito' : 'partiti' }}</strong></template>.
            </p>
            <p v-for="e in esito.errori" :key="e" class="text-rose-700">{{ e }}</p>
            <p v-if="esito.saltate.length">Saltate perché l'avevano già ricevuta per email: {{ esito.saltate.join(', ') }}.</p>
            <p v-if="esito.senza_indirizzo.length">Senza un indirizzo fra quelli scelti: {{ esito.senza_indirizzo.join(', ') }}.</p>
            <p v-if="esito.restanti" class="font-semibold">Restano {{ esito.restanti.persone.length }} persone ({{ esito.restanti.messaggi }} messaggi): sono già spuntate, premi di nuovo «Manda per email».</p>
        </div>

        <!-- Chi e a quali indirizzi -->
        <div class="flex flex-wrap items-center gap-x-5 gap-y-2 text-sm">
            <div v-if="cosa === 'verbale'" class="flex items-center gap-1">
                <span class="text-slate-500 mr-1">A chi:</span>
                <button v-for="c in (['assenti', 'tutti'] as const)" :key="c" type="button" class="h-8 px-3 rounded-md border text-sm font-medium"
                    :class="chi === c ? 'bg-slate-900 text-white border-slate-900 dark:bg-white dark:text-slate-900' : 'bg-white dark:bg-slate-900'"
                    :data-testid="`chi-${c}`" @click="cambiaChi(c)">{{ c === 'assenti' ? 'agli assenti' : 'a tutti i condòmini' }}</button>
            </div>
            <div class="flex items-center gap-3">
                <span class="text-slate-500">Indirizzi:</span>
                <label v-for="(etichetta, tipo) in anteprima.tipi_indirizzo" :key="tipo" class="flex items-center gap-1">
                    <input type="checkbox" class="h-4 w-4" :checked="indirizzi.includes(String(tipo))" @change="cambiaTipo(String(tipo))" /> {{ etichetta }}
                </label>
                <span class="text-xs text-slate-500">(la PEC parte come email semplice)</span>
            </div>
        </div>

        <!-- Le persone -->
        <div class="rounded-lg border divide-y dark:divide-slate-800">
            <label class="flex items-center gap-2 px-3 py-2 text-sm bg-slate-50 dark:bg-slate-800/50">
                <input type="checkbox" class="h-4 w-4" :checked="tutteScelte" @change="spuntaTutte" />
                <span class="font-medium">Tutte</span>
                <span class="ml-auto text-slate-500">{{ scelte.length }} spuntate: {{ conEmail.length }} con un indirizzo ({{ quantiMessaggi }} {{ quantiMessaggi === 1 ? 'messaggio' : 'messaggi' }}), {{ senzaEmail.length }} senza</span>
            </label>
            <div v-for="p in anteprima.persone" :key="p.id" class="px-3 py-2 text-sm" data-testid="riga-invio">
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                    <input type="checkbox" class="h-4 w-4" :checked="scelte.includes(p.id)" @change="spunta(p)" />
                    <div class="min-w-[220px] flex-1">
                        <p class="font-medium">{{ p.nome }}
                            <span v-if="cosa === 'verbale' && p.assente === true" class="ml-1 text-xs rounded-full bg-slate-100 px-2 py-0.5 font-normal text-slate-600 dark:bg-slate-800 dark:text-slate-300">assente</span>
                        </p>
                        <p class="text-xs text-slate-500">{{ p.unita.join(', ') }}<template v-if="p.indirizzo_postale"> · {{ p.indirizzo_postale }}</template></p>
                    </div>
                    <div class="min-w-[220px] text-xs">
                        <p v-for="i in p.indirizzi" :key="i.tipo">{{ i.indirizzo }} <span class="text-slate-400">({{ anteprima.tipi_indirizzo[i.tipo] }})</span></p>
                        <p v-if="p.senza_email" class="text-amber-800 dark:text-amber-300">{{ p.solo_pec ? 'solo la PEC: spuntala, o spedisci a parte' : 'nessun indirizzo email: da stampare' }}</p>
                    </div>
                    <button type="button" class="text-xs underline decoration-dotted min-w-[190px] text-left" :class="statoDi(p).colore" @click="aperta = aperta === p.id ? null : p.id">{{ statoDi(p).testo }}</button>
                    <Button variant="ghost" size="sm" class="h-7 gap-1 text-xs" :title="cosa === 'convocazione' ? 'Il suo avviso in PDF' : 'La sua lettera di trasmissione in PDF'" @click="pdfDellaPersona(p)"><FileDown class="w-3.5 h-3.5" /> PDF</Button>
                    <Input v-if="registra && scelte.includes(p.id)" v-model="numeri[p.id]" class="h-7 w-[170px] text-xs" placeholder="numero (se c'è)" />
                </div>
                <ul v-if="aperta === p.id" class="mt-2 ml-7 space-y-1 text-xs text-slate-600 dark:text-slate-300">
                    <li v-if="!p.invii.length">Nessun invio registrato.</li>
                    <li v-for="r in p.invii" :key="r.id" class="flex flex-wrap items-center gap-2">
                        <span>{{ giornoEOra(r.quando) }}</span>
                        <span v-if="r.canale === 'email'">email a {{ r.destinatario }}: <strong :class="r.stato === 'non_partita' ? 'text-rose-700' : ''">{{ NOMI_STATO_INVIO[r.stato] }}</strong></span>
                        <span v-else>{{ r.spedizione?.come }} del {{ giorno(r.spedizione?.data) }}<template v-if="r.spedizione?.numero"> n. {{ r.spedizione.numero }}</template><template v-if="r.spedizione?.nota"> — {{ r.spedizione.nota }}</template>:
                            <strong :class="r.stato === 'annullata' ? 'text-rose-700' : ''">{{ r.stato === 'annullata' ? 'annullata' : 'registrata' }}</strong></span>
                        <span v-if="r.versione && cosa === 'verbale' && !r.attuale" class="text-slate-400">(versione {{ r.versione }})</span>
                        <span v-if="r.da" class="text-slate-400">· {{ r.da }}</span>
                        <span v-if="r.errore" class="text-rose-700">{{ r.errore }}</span>
                        <a v-if="r.pdf" :href="`${base}/invii/${r.id}/pdf`" target="_blank" class="underline">l'avviso partito</a>
                        <button v-if="puoGestire && r.canale === 'stampa' && r.stato === 'spedita'" type="button" class="underline text-rose-700 flex items-center gap-1" @click="annulla(r.id)"><Undo2 class="w-3 h-3" /> annulla</button>
                    </li>
                </ul>
            </div>
            <p v-if="!anteprima.persone.length" class="p-3 text-sm text-slate-500">Non risulta nessun avente diritto: controlla i titolari delle unità.</p>
        </div>

        <!-- Il messaggio -->
        <div v-if="puoGestire" class="grid gap-3 lg:grid-cols-2">
            <div class="space-y-2">
                <label class="text-sm space-y-1 block"><span class="block font-medium">Oggetto dell'email</span><Input v-model="oggetto" class="h-9" :data-testid="`oggetto-${cosa}`" /></label>
                <label class="text-sm space-y-1 block"><span class="block font-medium">Testo dell'email</span><Textarea v-model="testo" rows="6" class="text-sm" :data-testid="`testo-${cosa}`" /></label>
                <p class="text-xs text-slate-500">
                    Parte dal modello dello studio<template v-if="anteprima.modello"> «{{ anteprima.modello }}»</template> (si cambia da «Modelli»). Il saluto «Gentile …,» e la chiusura li mette il programma;
                    <code v-pre>{{destinatario.nome}}</code> e gli altri segnaposto si riempiono per ogni persona. In allegato: <strong>{{ anteprima.allegato }}</strong><template v-if="cosa === 'convocazione'"> (l'avviso con il suo nome e la sua delega)</template>.
                </p>
            </div>
            <div v-if="anteprima.esempio" class="text-sm rounded-lg border bg-slate-50 dark:bg-slate-800/50 p-3 space-y-1 self-start">
                <p class="text-xs uppercase text-slate-500">Come arriva a {{ anteprima.esempio.persona }} (con il testo del modello)</p>
                <p class="font-semibold">{{ anteprima.esempio.oggetto }}</p>
                <p>{{ anteprima.esempio.saluto }}</p>
                <p v-for="(r, i) in anteprima.esempio.righe" :key="i">{{ r }}</p>
                <p>{{ anteprima.esempio.chiusura }}</p>
            </div>
        </div>

        <!-- Le azioni -->
        <div v-if="puoGestire" class="flex flex-wrap items-center gap-2 border-t pt-3">
            <Button :disabled="inCorso || !anteprima.si_puo_mandare || anteprima.senza_server_posta || !conEmail.length || !indirizzi.length" class="gap-2" :data-testid="`manda-${cosa}`" @click="conferma = true">
                <Send class="w-4 h-4" /> Manda per email ({{ quantiMessaggi }})
            </Button>
            <label class="flex items-center gap-1 text-xs text-slate-600"><input v-model="ancheGiaMandate" type="checkbox" class="h-4 w-4" /> anche a chi l'ha già ricevuta</label>
            <Button variant="outline" :disabled="inCorso || !scelte.length || (cosa === 'verbale' && !anteprima.si_puo_mandare)" class="gap-2 ml-auto" :data-testid="`registra-${cosa}`" @click="registra = !registra">
                <PackageCheck class="w-4 h-4" /> Registra la spedizione ({{ scelte.length }})
            </Button>
            <Button v-if="cosa === 'verbale'" variant="outline" :disabled="!scelte.length || !anteprima.si_puo_mandare" class="gap-2" @click="lettereDelleScelte"><FileDown class="w-4 h-4" /> Lettere di trasmissione ({{ scelte.length }})</Button>
        </div>
        <div v-if="puoGestire && registra" class="rounded-lg border bg-slate-50 dark:bg-slate-800/50 p-3 space-y-2 text-sm" data-testid="modulo-spedizione">
            <p class="text-xs text-slate-600">Per le {{ scelte.length }} persone spuntate: {{ nome }} è stat{{ cosa === 'convocazione' ? 'a' : 'o' }} spedit{{ cosa === 'convocazione' ? 'a' : 'o' }} o consegnat{{ cosa === 'convocazione' ? 'a' : 'o' }} fuori dal programma. Non parte niente: resta scritto nel registro. Il numero della raccomandata di ognuno si scrive accanto al nome.</p>
            <div class="flex flex-wrap items-end gap-3">
                <label class="space-y-1"><span class="block font-medium">Come</span>
                    <select v-model="spedizione.canale" class="h-9 rounded-md border px-2 bg-transparent dark:bg-slate-900 min-w-[240px]">
                        <option v-for="(etichetta, canale) in anteprima.canali_spedizione" :key="canale" :value="canale">{{ etichetta }}</option>
                    </select>
                </label>
                <label class="space-y-1"><span class="block font-medium">Data</span><Input v-model="spedizione.data" type="date" :max="oggi" class="h-9" /></label>
                <label class="space-y-1 flex-1 min-w-[240px]"><span class="block font-medium">Nota (facoltativa)</span><Input v-model="spedizione.nota" class="h-9" placeholder="per esempio: consegnata al portiere" /></label>
                <Button :disabled="inCorso || !scelte.length" class="gap-2" data-testid="conferma-spedizione" @click="registraSpedizione"><PackageCheck class="w-4 h-4" /> Registra</Button>
            </div>
        </div>

        <ConfirmDialog
            :model-value="conferma"
            :title="cosa === 'convocazione' ? 'Mandare la convocazione?' : 'Mandare il verbale?'"
            :description="`Partono ${quantiMessaggi} email a ${conEmail.length} ${conEmail.length === 1 ? 'persona' : 'persone'}, con il PDF allegato. Le email partono davvero e non si richiamano.${senzaEmail.length ? ` ${senzaEmail.length} ${senzaEmail.length === 1 ? 'persona spuntata non ha' : 'persone spuntate non hanno'} un indirizzo: per loro stampa e registra la spedizione.` : ''}`"
            confirm-text="Manda"
            variant="warning"
            @update:model-value="(x: boolean) => { if (!x) conferma = false; }"
            @confirm="manda"
        />
    </div>
</template>
