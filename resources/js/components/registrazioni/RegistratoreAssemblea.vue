<script setup lang="ts">
/**
 * Il registratore di una riunione del condominio (Web Pro Italia, 17/11/2026), da incorporare in una pagina: la pagina «Registrazioni» del
 * condominio e la pagina di un'assemblea, che gli passa `assembleaId`.
 *
 * Come Follow Call: il microfono si registra a pezzi da 25 secondi (il MediaRecorder si chiude e si riapre, così ogni pezzo è un file intero;
 * Safari registra in mp4, gli altri in webm); ogni pezzo sale subito e torna con il suo testo, che compare in diretta. Se il browser si
 * chiude, i pezzi saliti restano e la registrazione si chiude dall'elenco. «Ferma» aspetta che salgano tutti, poi chiude: la trascrizione con
 * le voci e il riassunto li prepara il server, in qualche minuto.
 *
 * Il gestionale si usa dal computer. Per un'assemblea in videochiamata la casella «Prendi anche l'audio del computer» (accesa di serie dove
 * il browser lo permette: Chrome ed Edge) chiede di condividere una scheda o lo schermo con l'audio e lo mescola al microfono con un
 * AudioContext; il video si ferma subito. Se la persona non condivide l'audio si registra il solo microfono, e il registratore lo dice.
 * Lo schermo resta acceso (Wake Lock) finché si registra.
 */
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import axios from 'axios';
import { router } from '@inertiajs/vue3';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { usePermission } from '@/composables/permissions';
import { Mic, Square, Loader2, MonitorSpeaker, CheckCircle2, AlertTriangle } from 'lucide-vue-next';
import { audioDelComputerPossibile, durata, formatoDaMime, testoInDiretta, tipoAudio, type Formato } from '@/lib/registrazioni/registratore';

interface Servizio { trascrizione: boolean; riassunto: boolean; giorni_audio: number; secondi_segmento: number }

const props = withDefaults(defineProps<{
    condominioId: number;
    assembleaId?: number | null;
    /** Il titolo da dare alla registrazione (per esempio quello dell'assemblea); la persona lo può cambiare prima di partire. */
    titolo?: string | null;
    /** C'è la trascrizione (la chiave di Deepgram)? Se la pagina non lo dice, il registratore lo chiede al server. */
    disponibile?: boolean | null;
    /** Dopo «Ferma», il collegamento per aprire la registrazione. */
    mostraCollegamento?: boolean;
}>(), { assembleaId: null, titolo: null, disponibile: null, mostraCollegamento: true });

const emit = defineEmits<{
    avviata: [id: number];
    terminata: [id: number];
    errore: [messaggio: string];
}>();

const { generateRoute } = usePermission();
const rotta = (nome: string, extra: Record<string, unknown> = {}) => route(generateRoute(`gestionale.registrazioni.${nome}`), { condominio: props.condominioId, ...extra });

type Stato = 'pronto' | 'avvio' | 'registra' | 'chiusura' | 'chiusa';
const stato = ref<Stato>('pronto');
const avvisati = ref(false);
const titoloScelto = ref(props.titolo ?? '');
const puoAudioComputer = audioDelComputerPossibile(typeof navigator !== 'undefined' ? navigator : undefined);
const audioComputer = ref(puoAudioComputer);
const servizio = ref<Servizio | null>(props.disponibile === null ? null : { trascrizione: !!props.disponibile, riassunto: true, giorni_audio: 30, secondi_segmento: 25 });
const caricamentoServizio = ref(props.disponibile === null);
const avvisi = ref<Array<{ tipo: 'info' | 'attenzione' | 'errore'; testo: string }>>([]);
const pezziTesto = reactive<Record<number, string>>({});
const secondi = ref(0);
const registrazioneId = ref<number | null>(null);
const indirizzo = ref<string | null>(null);
const esito = ref<string | null>(null);
const conComputer = ref(false);

const testo = computed(() => testoInDiretta(pezziTesto));
const puoPartire = computed(() => avvisati.value && stato.value === 'pronto' && !!servizio.value?.trascrizione);
const scatolaTesto = ref<HTMLElement | null>(null);

// Lo stato del registratore che non serve allo schermo.
let microfono: MediaStream | null = null;
let schermo: MediaStream | null = null;
let contesto: AudioContext | null = null;
let flusso: MediaStream | null = null;
let rec: MediaRecorder | null = null;
let tipo: { mime: string; formato: Formato } | null = null;
let numero = 0;
let chiudendo = false;
let ciclo: ReturnType<typeof setTimeout> | null = null;
let orologio: ReturnType<typeof setInterval> | null = null;
let inizio = 0;
let coda: Promise<unknown> = Promise.resolve();
let fermato: Promise<void> = Promise.resolve();
let inSospeso: Array<{ n: number; blob: Blob }> = [];
let lucchettoSchermo: { release: () => Promise<void> } | null = null;
let togliGuardiaNavigazione: (() => void) | null = null;

function avviso(testo: string, tipo: 'info' | 'attenzione' | 'errore' = 'attenzione') {
    if (!avvisi.value.some((a) => a.testo === testo)) avvisi.value.push({ tipo, testo });
}

onMounted(async () => {
    if (props.disponibile !== null) return;
    try {
        servizio.value = (await axios.get(rotta('servizio'))).data as Servizio;
    } catch {
        servizio.value = { trascrizione: false, riassunto: false, giorni_audio: 30, secondi_segmento: 25 };
    } finally {
        caricamentoServizio.value = false;
    }
});

/**
 * Il microfono e, se chiesto, l'audio del computer, mescolati in un flusso solo. Le trappole già trovate in Follow Call: la condivisione
 * va chiesta con il video (Chrome non la apre col solo audio) e il video si ferma subito; una condivisione senza la spunta «Condividi
 * audio» non ha tracce audio, e allora si registra il solo microfono.
 */
async function prendiAudio(): Promise<MediaStream> {
    microfono = await navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: false, noiseSuppression: true, channelCount: 1 } });
    conComputer.value = false;
    if (!audioComputer.value || !navigator.mediaDevices.getDisplayMedia) return microfono;

    try {
        // Le opzioni in più (Chrome): l'audio di tutto il sistema fra le scelte, e non la scheda del gestionale stesso.
        schermo = await navigator.mediaDevices.getDisplayMedia({ video: true, audio: true, systemAudio: 'include', selfBrowserSurface: 'exclude' } as DisplayMediaStreamOptions);
    } catch {
        avviso('Non hai condiviso l\'audio del computer: registro solo il microfono. Le voci di chi è collegato in videochiamata entrano solo se escono dalle casse.');

        return microfono;
    }

    if (!schermo.getAudioTracks().length) {
        schermo.getTracks().forEach((t) => t.stop());
        schermo = null;
        avviso('Nella condivisione non c\'era l\'audio: registro solo il microfono. La prossima volta scegli la scheda della videochiamata e lascia spuntato «Condividi anche l\'audio della scheda».');

        return microfono;
    }

    contesto = new AudioContext();
    if (contesto.state === 'suspended') await contesto.resume().catch(() => undefined);
    const uscita = contesto.createMediaStreamDestination();
    contesto.createMediaStreamSource(microfono).connect(uscita);
    contesto.createMediaStreamSource(new MediaStream(schermo.getAudioTracks())).connect(uscita);
    schermo.getVideoTracks().forEach((t) => t.stop());
    schermo.getAudioTracks()[0].addEventListener('ended', () => {
        if (stato.value === 'registra') avviso('La condivisione dell\'audio del computer si è fermata: da qui in poi registro solo il microfono.');
    });
    conComputer.value = true;

    return uscita.stream;
}

function spegniAudio() {
    [microfono, schermo, flusso].forEach((s) => s?.getTracks().forEach((t) => t.stop()));
    contesto?.close().catch(() => undefined);
    microfono = schermo = flusso = null;
    contesto = null;
}

async function tieniAccesoLoSchermo() {
    try {
        const wl = (navigator as unknown as { wakeLock?: { request: (t: 'screen') => Promise<{ release: () => Promise<void> }> } }).wakeLock;
        if (wl && document.visibilityState === 'visible') lucchettoSchermo = await wl.request('screen');
    } catch {
        // Senza Wake Lock (o col permesso negato) si registra lo stesso: lo schermo si spegne secondo le impostazioni del computer.
    }
}
const allaVisibilita = () => {
    if (document.visibilityState === 'visible' && stato.value === 'registra') tieniAccesoLoSchermo();
};
const primaDiChiudere = (e: BeforeUnloadEvent) => {
    e.preventDefault();
    e.returnValue = '';
};

// Un pezzo alla volta: si chiude e si riapre ogni 25 secondi, così ogni pezzo è un file intero che si trascrive da solo.
function giro() {
    if (chiudendo || !flusso) return;
    const pezzi: Blob[] = [];
    rec = new MediaRecorder(flusso, tipo ? { mimeType: tipo.mime, audioBitsPerSecond: 48000 } : undefined);
    const questo = rec;
    let fine: () => void = () => undefined;
    fermato = new Promise<void>((r) => (fine = r));
    questo.ondataavailable = (e) => {
        if (e.data && e.data.size) pezzi.push(e.data);
    };
    questo.onstop = () => {
        if (pezzi.length) {
            const n = ++numero;
            const blob = new Blob(pezzi, { type: tipo?.mime || questo.mimeType || 'audio/webm' });
            coda = coda.then(() => manda(n, blob));
        }
        fine();
        if (!chiudendo) giro();
    };
    questo.start();
    ciclo = setTimeout(() => {
        try {
            if (questo.state !== 'inactive') questo.stop();
        } catch {
            // già fermo
        }
    }, (servizio.value?.secondi_segmento ?? 25) * 1000);
}

const pausa = (ms: number) => new Promise((r) => setTimeout(r, ms));

/** Manda un pezzo, con tre tentativi; se la rete non c'è, il pezzo resta da parte e si riprova prima di chiudere. */
async function manda(n: number, blob: Blob, tentativi = 3): Promise<boolean> {
    for (let i = 0; i < tentativi; i++) {
        try {
            const dati = new FormData();
            dati.append('numero', String(n));
            dati.append('audio', blob, `pezzo-${n}.${tipo?.formato ?? formatoDaMime(blob.type)}`);
            const r = (await axios.post(rotta('segmento', { registrazione: registrazioneId.value }), dati, { headers: { Accept: 'application/json' }, timeout: 120000 })).data;
            pezziTesto[n] = r.testo || '';
            if (scatolaTesto.value) setTimeout(() => scatolaTesto.value && (scatolaTesto.value.scrollTop = scatolaTesto.value.scrollHeight), 0);

            return true;
        } catch (e: unknown) {
            const risposta = (e as { response?: { status?: number; data?: { message?: string } } }).response;
            if (risposta?.status && [403, 404, 409, 422].includes(risposta.status)) {
                avviso(`Un pezzo non è stato accettato: ${risposta.data?.message ?? 'la registrazione non riceve più audio'}.`, 'errore');
                if (risposta.status === 409 && stato.value === 'registra') ferma();

                return false;
            }
            await pausa(1500 * (i + 1));
        }
    }
    inSospeso.push({ n, blob });
    avviso('Un pezzo non è salito (la rete?). Lo tengo da parte e riprovo prima di chiudere: non chiudere la pagina.');

    return false;
}

async function avvia() {
    if (!puoPartire.value) return;
    stato.value = 'avvio';
    avvisi.value = [];
    esito.value = null;
    try {
        flusso = await prendiAudio();
    } catch (e) {
        spegniAudio();
        stato.value = 'pronto';
        avviso(`Non riesco a usare il microfono: ${(e as Error).message || 'permesso negato'}. Controlla che il browser abbia il permesso del microfono per questo sito.`, 'errore');

        return;
    }

    tipo = tipoAudio((t) => typeof MediaRecorder !== 'undefined' && MediaRecorder.isTypeSupported(t));
    try {
        const r = (await axios.post(rotta('avvia'), {
            avviso_presenti: avvisati.value,
            assemblea_id: props.assembleaId,
            titolo: titoloScelto.value.trim() || null,
            formato: tipo?.formato ?? 'webm',
        }, { headers: { Accept: 'application/json' } })).data;
        registrazioneId.value = r.id;
        indirizzo.value = r.url ?? null;
        if (servizio.value && r.secondi_segmento) servizio.value.secondi_segmento = r.secondi_segmento;
    } catch (e: unknown) {
        spegniAudio();
        stato.value = 'pronto';
        const dati = (e as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }).response?.data;
        const motivo = Object.values(dati?.errors ?? {})[0]?.[0] ?? dati?.message ?? 'il server non risponde';
        avviso(`La registrazione non è partita: ${motivo}`, 'errore');
        emit('errore', motivo);

        return;
    }

    numero = 0;
    chiudendo = false;
    inSospeso = [];
    coda = Promise.resolve();
    Object.keys(pezziTesto).forEach((k) => delete pezziTesto[Number(k)]);
    stato.value = 'registra';
    giro();
    inizio = Date.now();
    secondi.value = 0;
    orologio = setInterval(() => (secondi.value = Math.floor((Date.now() - inizio) / 1000)), 1000);
    await tieniAccesoLoSchermo();
    document.addEventListener('visibilitychange', allaVisibilita);
    window.addEventListener('beforeunload', primaDiChiudere);
    // Una visita Inertia verso un'altra pagina fermerebbe il registratore: si chiede prima. Non per gli aggiornamenti della stessa pagina
    // (l'elenco che si rinfresca, un pulsante che torna qui): quelli non toccano il registratore.
    togliGuardiaNavigazione = router.on('before', (evento) => {
        const visita = evento.detail.visit;
        if (stato.value !== 'registra' && stato.value !== 'chiusura') return true;
        if (visita.method !== 'get' || visita.url.pathname === window.location.pathname) return true;

        return window.confirm('La registrazione è in corso: se lasci la pagina si ferma. I pezzi già saliti restano e la potrai chiudere dall\'elenco. Vuoi uscire?');
    });
    emit('avviata', registrazioneId.value!);
}

function sgancia() {
    if (ciclo) clearTimeout(ciclo);
    if (orologio) clearInterval(orologio);
    ciclo = orologio = null;
    document.removeEventListener('visibilitychange', allaVisibilita);
    window.removeEventListener('beforeunload', primaDiChiudere);
    lucchettoSchermo?.release().catch(() => undefined);
    lucchettoSchermo = null;
    togliGuardiaNavigazione?.();
    togliGuardiaNavigazione = null;
}

async function ferma() {
    if (stato.value !== 'registra') return;
    stato.value = 'chiusura';
    chiudendo = true;
    if (ciclo) clearTimeout(ciclo);
    try {
        if (rec && rec.state !== 'inactive') rec.stop();
    } catch {
        // già fermo
    }
    await fermato;
    await coda;
    spegniAudio();

    // I pezzi rimasti da parte: un ultimo giro, prima di chiudere.
    const rimasti = inSospeso;
    inSospeso = [];
    for (const p of rimasti) {
        if (!(await manda(p.n, p.blob))) inSospeso = inSospeso.filter((x) => x.n !== p.n).concat(p);
    }
    if (inSospeso.length) {
        avviso(`${inSospeso.length === 1 ? 'Un pezzo non è salito' : `${inSospeso.length} pezzi non sono saliti`}: nella trascrizione mancheranno quei 25 secondi.`, 'errore');
    }

    try {
        const r = (await axios.post(rotta('termina', { registrazione: registrazioneId.value }), {}, { headers: { Accept: 'application/json' } })).data;
        esito.value = r.message ?? 'Registrazione chiusa.';
    } catch (e: unknown) {
        const motivo = (e as { response?: { data?: { message?: string } } }).response?.data?.message;
        avviso(`La registrazione è ferma ma non sono riuscito a chiuderla${motivo ? `: ${motivo}` : ''}. I pezzi saliti sono salvi: chiudila dall'elenco con «Chiudi e trascrivi».`, 'errore');
    }
    sgancia();
    stato.value = 'chiusa';
    emit('terminata', registrazioneId.value!);
}

function nuova() {
    stato.value = 'pronto';
    avvisati.value = false;
    registrazioneId.value = null;
    indirizzo.value = null;
    esito.value = null;
    avvisi.value = [];
    secondi.value = 0;
    titoloScelto.value = props.titolo ?? '';
    Object.keys(pezziTesto).forEach((k) => delete pezziTesto[Number(k)]);
}

// La pagina si chiude o si cambia (dopo la conferma): l'ultimo pezzo prova a salire, la registrazione resta aperta e si chiude dall'elenco.
onBeforeUnmount(() => {
    if (stato.value === 'registra') {
        chiudendo = true;
        try {
            if (rec && rec.state !== 'inactive') rec.stop();
        } catch {
            // già fermo
        }
        setTimeout(spegniAudio, 500);
    } else {
        spegniAudio();
    }
    sgancia();
});

defineExpose({ avvia, ferma, stato });
</script>

<template>
    <div class="rounded-xl border bg-white dark:bg-slate-900 p-4 space-y-3 text-sm" data-testid="registratore">
        <div v-if="caricamentoServizio" class="flex items-center gap-2 text-slate-500"><Loader2 class="w-4 h-4 animate-spin" /> Un momento…</div>

        <div v-else-if="!servizio?.trascrizione" class="rounded-lg border border-amber-200 bg-amber-50 dark:bg-amber-950/30 dark:border-amber-900 p-3 text-amber-800 dark:text-amber-200" data-testid="registratore-spento">
            La registrazione non è attiva su questo gestionale: per trascrivere serve la chiave di Deepgram, che chi gestisce il server imposta in
            <code>KONDO_DEEPGRAM_KEY</code>. Le registrazioni già fatte restano consultabili.
        </div>

        <template v-else>
            <!-- Prima di partire: il titolo, l'avviso ai presenti, l'audio del computer -->
            <div v-if="stato === 'pronto' || stato === 'avvio'" class="space-y-2">
                <label class="block text-xs space-y-1 max-w-md">
                    <span class="block text-slate-600 dark:text-slate-300">Titolo (facoltativo)</span>
                    <Input v-model="titoloScelto" maxlength="150" placeholder="Se lo lasci vuoto lo propone il riassunto" class="h-8 text-xs" data-testid="registratore-titolo" />
                </label>
                <label class="flex items-start gap-2 rounded-lg bg-slate-50 dark:bg-slate-800/60 p-2.5 cursor-pointer" data-testid="registratore-avviso">
                    <Checkbox :model-value="avvisati" class="mt-0.5" @update:model-value="(v: boolean | 'indeterminate') => (avvisati = v === true)" />
                    <span>
                        <span class="font-medium">Ho avvisato i presenti che la riunione viene registrata solo per scrivere il verbale</span>
                        <span class="block text-xs text-slate-500">Obbligatorio. Chi registra se ne prende la responsabilità; il gestionale si annota quando l'hai confermato.</span>
                    </span>
                </label>
                <label v-if="puoAudioComputer" class="flex items-start gap-2 rounded-lg bg-slate-50 dark:bg-slate-800/60 p-2.5 cursor-pointer" data-testid="registratore-computer">
                    <Checkbox :model-value="audioComputer" class="mt-0.5" @update:model-value="(v: boolean | 'indeterminate') => (audioComputer = v === true)" />
                    <span>
                        <span class="font-medium flex items-center gap-1.5"><MonitorSpeaker class="w-4 h-4" /> Prendi anche l'audio del computer (videochiamata)</span>
                        <span class="block text-xs text-slate-500">Per chi è collegato con Meet, Zoom o Teams. Il browser ti chiede che cosa condividere: scegli la scheda della videochiamata (o lo schermo intero) e lascia spuntato «Condividi audio». Il video non si registra.</span>
                    </span>
                </label>
                <p v-else class="text-xs text-slate-500" data-testid="registratore-solo-microfono">
                    Questo browser registra solo il microfono. Per prendere anche le voci di una videochiamata usa Chrome o Edge sul computer.
                </p>
            </div>

            <!-- Il tasto, il tempo, lo stato -->
            <div class="flex items-center gap-3">
                <button
                    v-if="stato === 'pronto' || stato === 'avvio'"
                    type="button"
                    class="grid place-items-center w-14 h-14 rounded-full bg-gradient-to-b from-red-500 to-red-700 text-white shadow-md ring-4 ring-red-500/10 transition active:scale-95 disabled:opacity-40 disabled:cursor-not-allowed"
                    :disabled="!puoPartire"
                    aria-label="Registra"
                    data-testid="registratore-rec"
                    @click="avvia"
                >
                    <Loader2 v-if="stato === 'avvio'" class="w-6 h-6 animate-spin" />
                    <Mic v-else class="w-6 h-6" />
                </button>
                <button
                    v-else-if="stato === 'registra' || stato === 'chiusura'"
                    type="button"
                    class="grid place-items-center w-14 h-14 rounded-full bg-gradient-to-b from-red-500 to-red-700 text-white shadow-md ring-4 ring-red-500/20 transition active:scale-95 disabled:opacity-60"
                    :class="stato === 'registra' ? 'animate-pulse' : ''"
                    :disabled="stato === 'chiusura'"
                    aria-label="Ferma"
                    data-testid="registratore-stop"
                    @click="ferma"
                >
                    <Loader2 v-if="stato === 'chiusura'" class="w-6 h-6 animate-spin" />
                    <Square v-else class="w-5 h-5 fill-white" />
                </button>
                <CheckCircle2 v-else class="w-10 h-10 text-emerald-600" />

                <div class="flex-1 min-w-0">
                    <p class="font-semibold text-base">
                        <template v-if="stato === 'pronto'">{{ avvisati ? 'Registra' : 'Conferma l\'avviso ai presenti, poi registra' }}</template>
                        <template v-else-if="stato === 'avvio'">Preparo il microfono…</template>
                        <template v-else-if="stato === 'registra'">Sto registrando</template>
                        <template v-else-if="stato === 'chiusura'">Mando gli ultimi pezzi e chiudo…</template>
                        <template v-else>Registrazione chiusa</template>
                    </p>
                    <p class="text-xs text-slate-500 flex items-center gap-1.5">
                        <span class="inline-block w-2 h-2 rounded-full" :class="stato === 'registra' ? 'bg-red-600 animate-pulse' : 'bg-slate-300'" />
                        <template v-if="stato === 'registra'">{{ conComputer ? 'Microfono e audio del computer' : 'Solo microfono' }} · ogni {{ servizio?.secondi_segmento ?? 25 }} secondi un pezzo sale al server</template>
                        <template v-else-if="stato === 'chiusa'">{{ esito ?? 'Ferma.' }}</template>
                        <template v-else>Pronto</template>
                    </p>
                </div>
                <span v-if="stato === 'registra' || stato === 'chiusura'" class="font-bold tabular-nums text-lg" data-testid="registratore-tempo">{{ durata(secondi) }}</span>
            </div>

            <ul v-if="avvisi.length" class="space-y-1.5" data-testid="registratore-avvisi">
                <li
                    v-for="a in avvisi"
                    :key="a.testo"
                    class="flex gap-2 rounded-lg border p-2 text-xs"
                    :class="a.tipo === 'errore' ? 'border-rose-200 bg-rose-50 text-rose-800 dark:bg-rose-950/30 dark:border-rose-900 dark:text-rose-200' : 'border-amber-200 bg-amber-50 text-amber-800 dark:bg-amber-950/30 dark:border-amber-900 dark:text-amber-200'"
                >
                    <AlertTriangle class="w-4 h-4 shrink-0" /> <span>{{ a.testo }}</span>
                </li>
            </ul>

            <!-- Il testo in diretta -->
            <div
                v-if="stato !== 'pronto' && stato !== 'avvio'"
                ref="scatolaTesto"
                class="max-h-64 overflow-y-auto rounded-lg border bg-slate-50 dark:bg-slate-800/60 p-3 text-[13px] leading-relaxed whitespace-pre-wrap"
                aria-live="polite"
                data-testid="registratore-testo"
            >
                <span v-if="testo">{{ testo }}</span>
                <span v-else class="text-slate-400">Il parlato compare qui, un pezzo ogni {{ servizio?.secondi_segmento ?? 25 }} secondi. La trascrizione con le voci separate arriva alla fine.</span>
            </div>

            <div v-if="stato === 'chiusa'" class="flex flex-wrap gap-2">
                <a v-if="mostraCollegamento && indirizzo" :href="indirizzo" class="inline-flex items-center h-8 px-3 rounded-md border text-xs font-medium hover:bg-accent">Apri la registrazione</a>
                <Button size="sm" variant="outline" class="h-8 text-xs" @click="nuova">Nuova registrazione</Button>
            </div>
        </template>
    </div>
</template>
