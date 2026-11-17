<script setup lang="ts">
/**
 * La pagina dell'assemblea, da usare in sala su un portatile (Web Pro Italia, 17/11/2026).
 *
 * In alto, sempre in vista, teste e millesimi presenti e se l'assemblea è validamente costituita. Sotto, lo svolgimento
 * (convocazione, presidente, segretario, ore, registrazione) e due schede: le presenze (presente, delega, assente, chi
 * arriva e chi esce, con la ricerca per nome o per unità) e le votazioni punto per punto (tutti i presenti favorevoli, poi
 * i pochi da cambiare; il programma conta e propone l'esito, la persona lo conferma). I conti li fa il server: ogni
 * tocco salva e la pagina si aggiorna.
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import Alert from '@/components/Alert.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AssembleeGuide from '@/components/gestionale/assemblee/AssembleeGuide.vue';
import RegistratoreAssemblea from '@/components/registrazioni/RegistratoreAssemblea.vue';
import { usePermission } from '@/composables/permissions';
import { CheckCircle2, CircleAlert, ClipboardList, Clock, Gavel, LogIn, LogOut, Mail, Pencil, PlayCircle, ScrollText, Search, Square, Users, Vote } from 'lucide-vue-next';
import {
    NOMI_ESITO, NOMI_MODALITA, NOMI_STATO, corrispondeAllaRicerca, giornoEOra,
    type Esito, type Fotografia, type Maggioranza, type Presenza, type Punto, type TipoPresenza, type Voto,
} from '@/lib/gestionale/assemblee';
import type { Flash } from '@/types/flash';

const props = defineProps<{
    condominio: any; condomini: any; esercizio: any;
    fotografia: Fotografia;
    persone: { id: number; nome: string }[];
    maggioranze: Maggioranza[];
    puo: { gestire: boolean; approvare: boolean };
    /** Le registrazioni di questa assemblea, le più recenti prima (Web Pro Italia, 17/11/2026). */
    registrazioni?: { id: number; titolo: string; stato: string; iniziata: string | null; durata_secondi: number | null; bozza: boolean; errore: string | null }[];
}>();

const { generatePath } = usePermission();
const page = usePage<{ flash?: { message?: Flash }; errors?: Record<string, string> }>();
const flashMessage = computed(() => page.props.flash?.message);
const errori = computed(() => page.props.errors ?? {});

const a = computed(() => props.fotografia.assemblea);
const costituzione = computed(() => props.fotografia.conteggi.costituzione);
const base = computed(() => generatePath('gestionale/:condominio/assemblee/:assemblea', { condominio: props.condominio.id, assemblea: a.value.id }));
const modificabile = computed(() => props.puo.gestire && !a.value.bloccata);

// La registrazione dell'assemblea. Quando si ferma, il server prepara in qualche minuto la trascrizione con le voci, il riassunto e la
// bozza per i punti dell'ordine del giorno, che entra da sola nei campi ancora vuoti del verbale: la pagina si rilegge per dirlo.
const registrazioni = computed(() => props.registrazioni ?? []);
const inElaborazione = computed(() => registrazioni.value.some((r) => r.stato === 'in_elaborazione'));
const statoRegistrazione = (r: { stato: string; bozza: boolean }) => ({
    in_corso: 'in corso',
    in_elaborazione: 'la sto trascrivendo: qualche minuto',
    pronta: r.bozza ? 'pronta: la bozza è entrata nei punti vuoti del verbale' : 'pronta',
    errore: 'non riuscita',
}[r.stato] ?? r.stato);
const minuti = (secondi: number | null) => secondi === null ? '' : `${Math.max(1, Math.round(secondi / 60))} min`;
const rileggiRegistrazioni = () => router.reload({ only: ['registrazioni', 'fotografia'] });
let attesa: ReturnType<typeof setInterval> | null = null;
watch(inElaborazione, (attiva) => {
    if (attiva && attesa === null) attesa = setInterval(rileggiRegistrazioni, 20000);
    if (!attiva && attesa !== null) { clearInterval(attesa); attesa = null; }
}, { immediate: true });
onBeforeUnmount(() => { if (attesa !== null) clearInterval(attesa); });
const mostraGuida = ref(false);
const scheda = ref<'presenze' | 'votazioni'>('presenze');

const opzioni = { preserveScroll: true, preserveState: true };
const invia = (metodo: 'put' | 'post', percorso: string, dati: Record<string, unknown> = {}) => {
    if (!modificabile.value) return;
    router[metodo](`${base.value}${percorso}`, dati as any, opzioni);
};

// --- Svolgimento ---------------------------------------------------------------------------------------------
const svolgimento = ref({ presidente: a.value.presidente ?? '', segretario: a.value.segretario ?? '', ora_inizio: a.value.ora_inizio ?? '', ora_fine: a.value.ora_fine ?? '' });
watch(a, (n) => { svolgimento.value = { presidente: n.presidente ?? '', segretario: n.segretario ?? '', ora_inizio: n.ora_inizio ?? '', ora_fine: n.ora_fine ?? '' }; });
const salvaCampo = (campo: keyof typeof svolgimento.value) => {
    const valore = svolgimento.value[campo] || null;
    if ((a.value as any)[campo] === valore) return;
    invia('put', '/svolgimento', { [campo]: valore });
};

// --- Presenze ------------------------------------------------------------------------------------------------
const ricerca = ref('');
const filtro = ref<'tutti' | 'presenti' | 'assenti'>('tutti');
const presenzeMostrate = computed(() => props.fotografia.presenze
    .filter((p) => corrispondeAllaRicerca(p, ricerca.value))
    .filter((p) => filtro.value === 'tutti' || (filtro.value === 'presenti' ? p.tipo !== 'assente' : p.tipo === 'assente')));
const presenzeUrl = (p: Presenza) => `/presenze/${p.id}`;
const segnaTipo = (p: Presenza, tipo: TipoPresenza) => { if (p.tipo !== tipo) invia('put', presenzeUrl(p), { tipo }); };
const delegati = ref<Record<number, string>>({});
const nomeDelegato = (p: Presenza) => delegati.value[p.id] ?? p.delegato ?? '';
function salvaDelegato(p: Presenza) {
    const testo = (delegati.value[p.id] ?? '').trim();
    if (testo === '' || testo === p.delegato) return;
    const inRubrica = props.persone.find((x) => x.nome.toLowerCase() === testo.toLowerCase());
    invia('put', presenzeUrl(p), inRubrica ? { delegato_anagrafica_id: inRubrica.id } : { delegato_nome: testo });
}
const contatoriElenco = computed(() => ({
    tutti: props.fotografia.presenze.length,
    presenti: props.fotografia.presenze.filter((p) => p.tipo !== 'assente').length,
    assenti: props.fotografia.presenze.filter((p) => p.tipo === 'assente').length,
}));

// --- Votazioni -----------------------------------------------------------------------------------------------
const puntoScelto = ref<number | null>(props.fotografia.punti.find((p) => !p.esito && p.maggioranza !== 'informativa')?.id ?? props.fotografia.punti[0]?.id ?? null);
const punto = computed<Punto | null>(() => props.fotografia.punti.find((p) => p.id === puntoScelto.value) ?? null);
const votoDi = (p: Presenza) => punto.value?.voti.find((v) => v.presenza_id === p.id)?.voto ?? null;
// Chi vota su questo punto: chi è in sala, più chi ha già un voto (anche se poi è uscito).
const votanti = computed(() => props.fotografia.presenze.filter((p) => p.in_sala || votoDi(p) !== null));
const votantiMostrati = computed(() => votanti.value.filter((p) => corrispondeAllaRicerca(p, ricerca.value)));
const puntiUrl = computed(() => `/punti/${puntoScelto.value}`);
const vota = (p: Presenza, voto: Voto | null) => invia('put', `${puntiUrl.value}/voti`, { voti: [{ presenza_id: p.id, voto: votoDi(p) === voto ? null : voto }] });
const tutti = (voto: Voto) => invia('put', `${puntiUrl.value}/voti`, { tutti: voto });
const azzera = () => invia('put', `${puntiUrl.value}/voti`, { azzera: true });
const conferma = (esito: Esito | null) => invia('put', puntiUrl.value, { esito });

const coloreVoto: Record<Voto, string> = {
    favorevole: 'bg-emerald-600 text-white border-emerald-600',
    contrario: 'bg-rose-600 text-white border-rose-600',
    astenuto: 'bg-amber-500 text-white border-amber-500',
};
const coloreTipo: Record<TipoPresenza, string> = {
    presente: 'bg-emerald-600 text-white border-emerald-600',
    delega: 'bg-blue-600 text-white border-blue-600',
    assente: 'bg-slate-500 text-white border-slate-500',
};

const quando = computed(() => {
    const d = a.value.convocazione_tenuta === 'seconda' ? a.value.data_seconda_convocazione : (a.value.data_prima_convocazione ?? a.value.data_seconda_convocazione);
    return d ? giornoEOra(d) : 'data da fissare';
});

const pageGuides = [
    { title: 'Teste e millesimi', description: 'In alto, sempre in vista: chi è in sala (di persona o per delega) e se l\'assemblea è validamente costituita nella convocazione scelta.', icon: Users, colorVariant: 'blue' as const },
    { title: 'Le votazioni', description: '«Tutti i presenti favorevoli», poi un tocco sui pochi contrari o astenuti. Il programma conta teste e millesimi e propone l\'esito: lo confermi tu.', icon: Vote, colorVariant: 'amber' as const },
    { title: 'Il verbale', description: 'Finita l\'assemblea, il verbale si genera dal modello dello studio con i dati di questa pagina, e poi si corregge liberamente.', icon: ScrollText, colorVariant: 'emerald' as const },
];
const breadcrumbs = computed(() => [
    { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
    { title: 'Assemblee', href: generatePath('gestionale/:condominio/assemblee', { condominio: props.condominio.id }) },
    { title: a.value.titolo },
]);
const bottone = 'h-9 px-3 rounded-md border text-sm font-medium transition-colors';
</script>

<template>
    <Head :title="a.titolo" />
    <GestionaleLayout>
        <div class="px-6 py-6 space-y-4">
            <PageHeaderGuide
                :page-title="a.titolo"
                :page-subtitle="`${a.tipo === 'straordinaria' ? 'Straordinaria' : 'Ordinaria'} · ${quando} · ${a.luogo ?? 'luogo da scrivere'} · ${NOMI_MODALITA[a.modalita]} · ${NOMI_STATO[a.stato]}`"
                :guides="pageGuides"
                :breadcrumbs="(breadcrumbs as any)"
                :condominio="(props.condominio as any)"
                :condomini="(props.condomini as any)"
                :back-url="generatePath('gestionale/:condominio/assemblee', { condominio: props.condominio.id })"
                back-text="Assemblee"
                has-text-guide
                text-guide-title="Guida"
                @open-text-guide="mostraGuida = true"
            >
                <template #actions>
                    <Link v-if="modificabile" :href="`${base}/edit`"><Button variant="outline" class="h-8 px-3 gap-2 bg-white dark:bg-slate-900"><Pencil class="w-4 h-4" /> Date e ordine del giorno</Button></Link>
                    <!-- La convocazione (l'avviso da mandare) e il foglio delle firme da stampare e portare in sala (17/11/2026). -->
                    <Link :href="`${base}/convocazione`"><Button variant="outline" class="h-8 px-3 gap-2 bg-white dark:bg-slate-900" data-testid="vai-alla-convocazione"><Mail class="w-4 h-4" /> Convocazione</Button></Link>
                    <a :href="`${base}/foglio-firme/stampa`" target="_blank"><Button variant="outline" class="h-8 px-3 gap-2 bg-white dark:bg-slate-900" data-testid="foglio-firme"><ClipboardList class="w-4 h-4" /> Foglio firme</Button></a>
                    <Link :href="`${base}/verbale`"><Button class="h-8 px-3 gap-2" data-testid="vai-al-verbale"><ScrollText class="w-4 h-4" /> Verbale</Button></Link>
                </template>
            </PageHeaderGuide>

            <div v-if="flashMessage"><Alert :message="flashMessage.message" :type="flashMessage.type" /></div>
            <div v-if="Object.keys(errori).length" class="rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800 dark:bg-rose-950/40 dark:border-rose-900 dark:text-rose-200">
                <p v-for="(m, k) in errori" :key="k">{{ m }}</p>
            </div>
            <p v-if="a.bloccata" class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200">
                Il verbale di questa assemblea è approvato: presenze e voti non si modificano più.
            </p>

            <!-- Teste e millesimi, sempre in vista -->
            <div class="sticky top-0 z-10 rounded-xl border bg-white/95 dark:bg-slate-900/95 backdrop-blur p-3 flex flex-wrap items-center gap-x-6 gap-y-2" data-testid="conteggi">
                <div class="flex items-baseline gap-2">
                    <span class="text-2xl font-bold tabular-nums">{{ costituzione.teste_presenti }}</span>
                    <span class="text-sm text-slate-500">su {{ costituzione.teste_totali }} condòmini in sala<template v-if="costituzione.teste_per_delega"> ({{ costituzione.teste_per_delega }} per delega)</template></span>
                </div>
                <div class="flex items-baseline gap-2">
                    <span class="text-2xl font-bold tabular-nums">{{ costituzione.millesimi_presenti_testo }}</span>
                    <span class="text-sm text-slate-500">millesimi su 1.000</span>
                </div>
                <div class="flex items-center gap-2 text-sm font-semibold" :class="costituzione.valida ? 'text-emerald-700 dark:text-emerald-400' : 'text-rose-700 dark:text-rose-400'">
                    <CheckCircle2 v-if="costituzione.valida" class="w-5 h-5" /><CircleAlert v-else class="w-5 h-5" />
                    <span>{{ costituzione.valida ? 'Validamente costituita' : 'Non costituita' }} in {{ costituzione.convocazione }} convocazione</span>
                    <span v-if="costituzione.mancano" class="font-normal">— {{ costituzione.mancano }}</span>
                </div>
                <div class="ml-auto flex items-center gap-1 text-sm">
                    <span class="text-slate-500 mr-1">Convocazione:</span>
                    <button v-for="c in (['prima', 'seconda'] as const)" :key="c" type="button" :disabled="!modificabile"
                        :class="[bottone, a.convocazione_tenuta === c ? 'bg-slate-900 text-white border-slate-900 dark:bg-white dark:text-slate-900' : 'bg-white dark:bg-slate-900']"
                        @click="invia('put', '/svolgimento', { convocazione_tenuta: c })">{{ c }}<span v-if="costituzione.per_convocazione[c]" class="ml-1">✓</span></button>
                </div>
            </div>

            <ul v-if="fotografia.conteggi.avvisi.length" class="space-y-1">
                <li v-for="(av, i) in fotografia.conteggi.avvisi" :key="i" class="text-sm rounded-md px-3 py-1.5"
                    :class="av.tipo === 'error' ? 'bg-rose-50 text-rose-800 dark:bg-rose-950/40 dark:text-rose-200' : av.tipo === 'warning' ? 'bg-amber-50 text-amber-900 dark:bg-amber-950/40 dark:text-amber-200' : 'bg-slate-50 text-slate-700 dark:bg-slate-800 dark:text-slate-300'">
                    {{ av.testo }}
                </li>
            </ul>

            <!-- Svolgimento -->
            <div class="rounded-xl border bg-white dark:bg-slate-900 p-4 grid gap-3 lg:grid-cols-6 items-end">
                <label class="text-sm space-y-1 lg:col-span-2"><span class="block font-medium">Presidente</span>
                    <Input v-model="svolgimento.presidente" :disabled="!modificabile" class="h-9" placeholder="Chi presiede" @change="salvaCampo('presidente')" />
                </label>
                <label class="text-sm space-y-1 lg:col-span-2"><span class="block font-medium">Segretario</span>
                    <Input v-model="svolgimento.segretario" :disabled="!modificabile" class="h-9" placeholder="Chi scrive il verbale" @change="salvaCampo('segretario')" />
                </label>
                <label class="text-sm space-y-1"><span class="block font-medium">Inizio</span>
                    <Input v-model="svolgimento.ora_inizio" :disabled="!modificabile" type="time" class="h-9" @change="salvaCampo('ora_inizio')" />
                </label>
                <label class="text-sm space-y-1"><span class="block font-medium">Fine</span>
                    <Input v-model="svolgimento.ora_fine" :disabled="!modificabile" type="time" class="h-9" @change="salvaCampo('ora_fine')" />
                </label>
                <div class="lg:col-span-6 flex flex-wrap items-center gap-3">
                    <Button v-if="a.stato !== 'in_corso' && a.stato !== 'chiusa'" :disabled="!modificabile" class="gap-2" @click="invia('put', '/svolgimento', { adesso: 'inizia' })"><PlayCircle class="w-4 h-4" /> Inizia l'assemblea</Button>
                    <Button v-if="a.stato === 'in_corso'" :disabled="!modificabile" variant="outline" class="gap-2" @click="invia('put', '/svolgimento', { adesso: 'chiudi' })"><Square class="w-4 h-4" /> Chiudi l'assemblea</Button>
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" :checked="a.registrata" :disabled="!modificabile" class="h-4 w-4" @change="invia('put', '/svolgimento', { registrata: ($event.target as HTMLInputElement).checked })" />
                        La riunione è registrata (i presenti ne sono informati: il verbale lo scrive)
                    </label>
                </div>
                <!-- La registrazione dell'assemblea (Web Pro Italia, 17/11/2026): il registratore, e le registrazioni già fatte. Quando una
                     registrazione è pronta, la sua bozza entra da sola nei campi ancora vuoti di discussione e delibera dei punti. -->
                <div class="lg:col-span-6 space-y-3" data-testid="posto-registratore">
                    <RegistratoreAssemblea v-if="modificabile" :condominio-id="condominio.id" :assemblea-id="a.id" :titolo="a.titolo"
                        :mostra-collegamento="false" @avviata="rileggiRegistrazioni" @terminata="rileggiRegistrazioni" />
                    <ul v-if="registrazioni.length" class="text-sm divide-y rounded-lg border" data-testid="registrazioni-assemblea">
                        <li v-for="r in registrazioni" :key="r.id" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2">
                            <Link :href="generatePath('gestionale/:condominio/registrazioni/:registrazione', { condominio: condominio.id, registrazione: r.id })"
                                class="font-medium underline decoration-dotted">{{ r.titolo }}</Link>
                            <span class="text-slate-500">{{ minuti(r.durata_secondi) }}</span>
                            <span :class="r.stato === 'errore' ? 'text-red-600' : 'text-slate-600 dark:text-slate-300'">{{ statoRegistrazione(r) }}</span>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Le due schede -->
            <div class="flex gap-1 border-b">
                <button v-for="s in (['presenze', 'votazioni'] as const)" :key="s" type="button"
                    class="px-4 py-2 text-sm font-semibold border-b-2 -mb-px"
                    :class="scheda === s ? 'border-slate-900 text-slate-900 dark:border-white dark:text-white' : 'border-transparent text-slate-500'"
                    @click="scheda = s">{{ s === 'presenze' ? 'Presenze' : 'Votazioni' }}</button>
                <div class="ml-auto relative w-[320px] mb-1">
                    <Search class="w-4 h-4 absolute left-2 top-2.5 text-slate-400" />
                    <Input v-model="ricerca" class="h-9 pl-8" placeholder="Cerca per nome o per unità" data-testid="ricerca" />
                </div>
            </div>

            <!-- PRESENZE -->
            <div v-if="scheda === 'presenze'" class="space-y-3">
                <div v-if="!fotografia.presenze.length" class="rounded-xl border bg-white dark:bg-slate-900 p-6 text-sm space-y-3">
                    <p>L'elenco degli aventi diritto si prepara dai titolari delle unità e dalla tabella {{ a.tabella ? `«${a.tabella}»` : 'dei millesimi (scegline una da «Date e ordine del giorno»)' }}.</p>
                    <Button :disabled="!modificabile || !a.tabella_id" class="gap-2" data-testid="prepara-elenco" @click="invia('post', '/presenze/prepara')"><Users class="w-4 h-4" /> Prepara l'elenco degli aventi diritto</Button>
                </div>
                <template v-else>
                    <div class="flex flex-wrap items-center gap-2 text-sm">
                        <button v-for="f in (['tutti', 'presenti', 'assenti'] as const)" :key="f" type="button"
                            :class="[bottone, filtro === f ? 'bg-slate-900 text-white border-slate-900 dark:bg-white dark:text-slate-900' : 'bg-white dark:bg-slate-900']"
                            @click="filtro = f">{{ f === 'tutti' ? 'Tutti' : f === 'presenti' ? 'Presenti e deleghe' : 'Assenti' }} ({{ contatoriElenco[f] }})</button>
                        <Button v-if="modificabile" variant="ghost" size="sm" class="ml-auto text-xs" title="Rilegge titolari e millesimi: tiene quello che è già segnato" @click="invia('post', '/presenze/prepara')">Aggiorna l'elenco dai titolari</Button>
                    </div>
                    <div class="rounded-xl border bg-white dark:bg-slate-900 divide-y dark:divide-slate-800">
                        <div v-for="p in presenzeMostrate" :key="p.id" class="p-3 flex flex-wrap items-center gap-x-4 gap-y-2" data-testid="riga-presenza">
                            <div class="min-w-[280px] flex-1">
                                <p class="font-semibold">{{ p.nome }}<span v-if="p.contitolari.length" class="font-normal text-slate-500"> (anche per {{ p.contitolari.join(', ') }})</span></p>
                                <p class="text-xs text-slate-500">{{ p.unita.map((u) => u.etichetta).join(', ') }} · <strong class="tabular-nums">{{ p.millesimi_su_mille }}</strong> millesimi</p>
                                <label v-if="p.candidati.length > 1" class="text-xs text-slate-600 flex items-center gap-1 mt-1">Chi vota:
                                    <select :value="p.anagrafica_id" :disabled="!modificabile" class="h-7 rounded border px-1 bg-transparent dark:bg-slate-900" @change="invia('put', presenzeUrl(p), { anagrafica_id: Number(($event.target as HTMLSelectElement).value) })">
                                        <option v-for="c in p.candidati" :key="c.id" :value="c.id">{{ c.nome }} ({{ c.ruolo.toLowerCase() }})</option>
                                    </select>
                                </label>
                            </div>
                            <div class="flex gap-1">
                                <button v-for="t in (['presente', 'delega', 'assente'] as const)" :key="t" type="button" :disabled="!modificabile"
                                    :class="[bottone, 'min-w-[92px]', p.tipo === t ? coloreTipo[t] : 'bg-white dark:bg-slate-900 hover:bg-slate-50']"
                                    @click="segnaTipo(p, t)">{{ t === 'presente' ? 'Presente' : t === 'delega' ? 'Delega' : 'Assente' }}</button>
                            </div>
                            <div v-if="p.tipo === 'delega'" class="flex items-center gap-1">
                                <Input :model-value="nomeDelegato(p)" :disabled="!modificabile" list="rubrica-assemblea" class="h-9 w-[230px]" placeholder="A chi (rubrica o nome)"
                                    @update:model-value="(v: string | number) => (delegati[p.id] = String(v))" @change="salvaDelegato(p)" @keyup.enter="salvaDelegato(p)" />
                            </div>
                            <div v-if="p.tipo !== 'assente'" class="flex items-center gap-1 text-xs text-slate-600">
                                <Clock class="w-3.5 h-3.5" />
                                <span v-if="p.ora_arrivo">entra {{ p.ora_arrivo }}</span>
                                <span v-if="p.ora_uscita" class="text-rose-700">esce {{ p.ora_uscita }}</span>
                                <Button v-if="p.in_sala" variant="ghost" size="sm" class="h-8 gap-1" :disabled="!modificabile" @click="invia('put', presenzeUrl(p), { segna: 'uscita' })"><LogOut class="w-3.5 h-3.5" /> Esce</Button>
                                <Button v-else variant="ghost" size="sm" class="h-8 gap-1" :disabled="!modificabile" @click="invia('put', presenzeUrl(p), { segna: 'rientro' })"><LogIn class="w-3.5 h-3.5" /> Rientra</Button>
                            </div>
                            <Button v-else-if="a.stato === 'in_corso'" variant="ghost" size="sm" class="h-8 gap-1 text-xs" :disabled="!modificabile" title="Arriva ad assemblea cominciata: il verbale scrive «entra alle…»" @click="invia('put', presenzeUrl(p), { segna: 'arrivo' })"><LogIn class="w-3.5 h-3.5" /> Arrivato adesso</Button>
                        </div>
                        <p v-if="!presenzeMostrate.length" class="p-4 text-sm text-slate-500">Nessuno corrisponde alla ricerca.</p>
                    </div>
                    <datalist id="rubrica-assemblea"><option v-for="x in persone" :key="x.id" :value="x.nome" /></datalist>
                </template>
            </div>

            <!-- VOTAZIONI -->
            <div v-else class="grid gap-4 lg:grid-cols-[300px_1fr]">
                <div class="rounded-xl border bg-white dark:bg-slate-900 divide-y dark:divide-slate-800 self-start">
                    <button v-for="pt in fotografia.punti" :key="pt.id" type="button" class="w-full text-left p-3 text-sm hover:bg-slate-50 dark:hover:bg-slate-800/50"
                        :class="pt.id === puntoScelto ? 'bg-slate-100 dark:bg-slate-800' : ''" @click="puntoScelto = pt.id">
                        <span class="font-semibold">{{ pt.numero }}. {{ pt.titolo }}</span>
                        <span class="block text-xs text-slate-500">
                            {{ pt.maggioranza === 'informativa' ? 'Informativo' : pt.esito ? NOMI_ESITO[pt.esito] : pt.votazione.intervenuti.teste ? `Da confermare (${pt.votazione.esito_proposto})` : 'Da votare' }}
                        </span>
                    </button>
                    <p v-if="!fotografia.punti.length" class="p-3 text-sm text-slate-500">L'ordine del giorno è vuoto.</p>
                </div>

                <div v-if="punto" class="space-y-3">
                    <div class="rounded-xl border bg-white dark:bg-slate-900 p-4 space-y-2">
                        <h3 class="text-lg font-bold">{{ punto.numero }}. {{ punto.titolo }}</h3>
                        <p v-if="punto.descrizione" class="text-sm text-slate-600">{{ punto.descrizione }}</p>
                        <p class="text-sm"><strong>Maggioranza:</strong> {{ punto.votazione.regola }}</p>
                    </div>

                    <div v-if="punto.votazione.informativa" class="rounded-xl border bg-white dark:bg-slate-900 p-4 text-sm">Punto informativo: non si vota. Discussione e comunicazioni si scrivono nel verbale.</div>
                    <template v-else>
                        <!-- Il risultato -->
                        <div class="rounded-xl border p-4 grid gap-3 md:grid-cols-4 bg-white dark:bg-slate-900" data-testid="risultato">
                            <div v-for="(g, chiave) in { Favorevoli: punto.votazione.favorevoli, Contrari: punto.votazione.contrari, Astenuti: punto.votazione.astenuti }" :key="chiave">
                                <p class="text-xs uppercase text-slate-500">{{ chiave }}</p>
                                <p class="text-xl font-bold tabular-nums">{{ g.teste }} <span class="text-sm font-normal text-slate-500">· {{ g.millesimi_testo }}‰</span></p>
                            </div>
                            <div>
                                <p class="text-xs uppercase text-slate-500">Proposta</p>
                                <p class="text-xl font-bold" :class="punto.votazione.esito_proposto === 'approvato' ? 'text-emerald-700' : 'text-rose-700'">
                                    {{ punto.votazione.esito_proposto ? NOMI_ESITO[punto.votazione.esito_proposto] : '—' }}
                                </p>
                            </div>
                            <p class="md:col-span-4 text-sm text-slate-600">{{ punto.votazione.spiegazione }}</p>
                            <p v-if="punto.votazione.senza_voto.length" class="md:col-span-4 text-sm text-amber-800">
                                {{ punto.votazione.senza_voto.length }} in sala senza un voto su questo punto: non contano fra gli intervenuti finché non li segni.
                            </p>
                            <div class="md:col-span-4 flex flex-wrap items-center gap-2 border-t pt-3">
                                <span class="text-sm">Esito confermato: <strong>{{ punto.esito ? NOMI_ESITO[punto.esito] : 'nessuno' }}</strong></span>
                                <Button v-if="punto.votazione.esito_proposto && punto.esito !== punto.votazione.esito_proposto" size="sm" :disabled="!modificabile" data-testid="conferma-esito" @click="conferma(punto.votazione.esito_proposto)">
                                    Conferma: {{ NOMI_ESITO[punto.votazione.esito_proposto] }}
                                </Button>
                                <Button v-if="punto.esito !== 'rinviato'" size="sm" variant="outline" :disabled="!modificabile" @click="conferma('rinviato')">Rinviato</Button>
                                <Button v-if="punto.esito" size="sm" variant="ghost" :disabled="!modificabile" @click="conferma(null)">Togli l'esito</Button>
                            </div>
                        </div>

                        <!-- I voti -->
                        <div class="rounded-xl border bg-white dark:bg-slate-900">
                            <div class="p-3 flex flex-wrap gap-2 border-b">
                                <Button :disabled="!modificabile" class="gap-2 bg-emerald-600 hover:bg-emerald-700" data-testid="tutti-favorevoli" @click="tutti('favorevole')"><Gavel class="w-4 h-4" /> Tutti i presenti favorevoli</Button>
                                <Button variant="outline" :disabled="!modificabile" @click="tutti('contrario')">Tutti contrari</Button>
                                <Button variant="ghost" :disabled="!modificabile" class="ml-auto" @click="azzera">Azzera i voti</Button>
                            </div>
                            <div class="divide-y dark:divide-slate-800">
                                <div v-for="p in votantiMostrati" :key="p.id" class="p-2.5 flex flex-wrap items-center gap-3">
                                    <div class="min-w-[260px] flex-1 text-sm">
                                        <span class="font-medium">{{ p.nome }}</span>
                                        <span v-if="p.delegato" class="text-slate-500"> — per delega a {{ p.delegato }}</span>
                                        <span class="text-slate-500"> · {{ p.millesimi_su_mille }}‰</span>
                                        <span v-if="!p.in_sala" class="text-rose-700 text-xs"> (uscito)</span>
                                    </div>
                                    <div class="flex gap-1">
                                        <button v-for="v in (['favorevole', 'contrario', 'astenuto'] as const)" :key="v" type="button" :disabled="!modificabile"
                                            :class="[bottone, 'min-w-[104px]', votoDi(p) === v ? coloreVoto[v] : 'bg-white dark:bg-slate-900 hover:bg-slate-50']"
                                            @click="vota(p, v)">{{ v === 'favorevole' ? 'Favorevole' : v === 'contrario' ? 'Contrario' : 'Astenuto' }}</button>
                                    </div>
                                </div>
                                <p v-if="!votanti.length" class="p-4 text-sm text-slate-500">Nessuno in sala: segna le presenze nella scheda «Presenze».</p>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </div>
        <AssembleeGuide v-model:open="mostraGuida" />
    </GestionaleLayout>
</template>
