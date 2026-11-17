<script setup lang="ts">
/**
 * Una registrazione (Web Pro Italia, 17/11/2026): il riassunto, la bozza per punti (se è collegata a un'assemblea con l'ordine del giorno),
 * la trascrizione con le voci e, finché quella non c'è, il testo in diretta. Da qui si chiude una registrazione rimasta aperta, si riprova
 * una finita in errore, si collega a un'assemblea, si cancella l'audio e si elimina tutto. In preparazione, la pagina si rinfresca da sola.
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import Alert from '@/components/Alert.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import { Button } from '@/components/ui/button';
import RegistrazioniGuide from '@/components/guides/RegistrazioniGuide.vue';
import { usePermission } from '@/composables/permissions';
import { ETICHETTA_STATO, durataAParole, megabyte, riassuntoInHtml, turni } from '@/lib/registrazioni/registratore';
import { Copy, FileText, ListChecks, Loader2, Mic, ShieldCheck } from 'lucide-vue-next';
import type { Flash } from '@/types/flash';

interface Registrazione {
    id: number; titolo: string; stato: 'in_corso' | 'in_elaborazione' | 'pronta' | 'errore'; assemblea_id: number | null; quando: string | null;
    durata_secondi: number; pezzi: number; ha_riassunto: boolean; ha_bozza: boolean; audio_cancellato_at: string | null; errore: string | null;
    trascrizione: string | null; trascrizione_live: string | null; riassunto: string | null;
    bozza_punti: Array<{ numero: number; discussione: string; delibera: string | null }> | null;
    avviso_presenti_at: string | null; avviso_presenti_quando: string | null; audio_cancellato_il: string | null; finita_at: string | null; autore: string | null; audio: boolean; audio_byte: number; audio_scade_il: string | null;
}
const props = defineProps<{
    condominio: { id: number; nome: string }; condomini: any; esercizio: any;
    registrazione: Registrazione;
    punti: Array<{ numero: number; titolo: string }>;
    assemblee: Array<{ id: number; nome: string }>;
    servizio: { trascrizione: boolean; riassunto: boolean; giorni_audio: number; secondi_segmento: number };
}>();

const { generateRoute, generatePath } = usePermission();
const page = usePage<{ flash?: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash?.message);
const mostraGuida = ref(false);
const r = computed(() => props.registrazione);
const rotta = (nome: string) => route(generateRoute(`gestionale.registrazioni.${nome}`), { condominio: props.condominio.id, registrazione: r.value.id });
const elenco = computed(() => route(generateRoute('gestionale.registrazioni.index'), { condominio: props.condominio.id }));

type Scheda = 'riassunto' | 'punti' | 'trascrizione' | 'diretta';
const schede = computed(() => {
    const s: Array<{ id: Scheda; titolo: string }> = [];
    if (r.value.riassunto) s.push({ id: 'riassunto', titolo: 'Riassunto' });
    if (r.value.bozza_punti?.length) s.push({ id: 'punti', titolo: 'Bozza per punti' });
    if (r.value.trascrizione) s.push({ id: 'trascrizione', titolo: 'Trascrizione' });
    if (!r.value.trascrizione && r.value.trascrizione_live) s.push({ id: 'diretta', titolo: 'Testo in diretta' });

    return s;
});
const scheda = ref<Scheda>('riassunto');
watch(schede, (s) => {
    if (!s.some((x) => x.id === scheda.value) && s.length) scheda.value = s[0].id;
}, { immediate: true });

const titoloPunto = (n: number) => props.punti.find((p) => p.numero === n)?.titolo ?? '';
const testoDaCopiare = computed(() => {
    if (scheda.value === 'riassunto') return r.value.riassunto ?? '';
    if (scheda.value === 'trascrizione') return r.value.trascrizione ?? '';
    if (scheda.value === 'diretta') return r.value.trascrizione_live ?? '';

    return (r.value.bozza_punti ?? []).map((p) => `${p.numero}. ${titoloPunto(p.numero)}\n${p.discussione || '(non se n\'è parlato)'}${p.delibera ? `\nDelibera: ${p.delibera}` : ''}`).join('\n\n');
});
const copiato = ref(false);
async function copia() {
    try {
        await navigator.clipboard.writeText(testoDaCopiare.value);
        copiato.value = true;
        setTimeout(() => (copiato.value = false), 1500);
    } catch {
        // Senza il permesso degli appunti: il testo resta selezionabile a mano.
    }
}

// In preparazione la pagina si rinfresca ogni 5 secondi, finché non è pronta.
let giro: ReturnType<typeof setInterval> | null = null;
watch(() => r.value.stato, (s) => {
    if (s === 'in_elaborazione' && !giro) giro = setInterval(() => router.reload({ only: ['registrazione'] }), 5000);
    if (s !== 'in_elaborazione' && giro) {
        clearInterval(giro);
        giro = null;
        if (s === 'pronta') router.reload({ only: ['registrazione', 'punti'] });
    }
}, { immediate: true });
onBeforeUnmount(() => giro && clearInterval(giro));

const termina = () => router.post(rotta('termina'), {}, { preserveScroll: true });
const assembleaScelta = ref<number | ''>(props.registrazione.assemblea_id ?? '');
const collega = (id: number | null) => router.put(rotta('assemblea'), { assemblea_id: id }, { preserveScroll: true });
const nomeAssemblea = computed(() => props.assemblee.find((a) => a.id === r.value.assemblea_id)?.nome ?? (r.value.assemblea_id ? `Assemblea n. ${r.value.assemblea_id}` : null));

const confermaAudio = ref(false);
const confermaElimina = ref(false);
const cancellaAudio = () => router.delete(rotta('cancella-audio'), { preserveScroll: true, onFinish: () => (confermaAudio.value = false) });
const elimina = () => router.delete(rotta('destroy'), { onFinish: () => (confermaElimina.value = false) });


const pageGuides = computed(() => [
    { title: 'Riassunto e bozza', description: 'Li scrive un modello dalla trascrizione: sono una base per il verbale, da rileggere. Le delibere compaiono solo se nella riunione sono state dette chiaramente.', icon: ListChecks, colorVariant: 'blue' as const },
    { title: 'Le voci', description: '«Persona 1» è la prima voce che parla, «Persona 2» la seconda, e così via. Chi è chi lo capisci dal contesto.', icon: Mic, colorVariant: 'amber' as const },
    { title: 'L\'audio', description: `Si cancella da solo dopo ${props.servizio.giorni_audio} giorni dalla fine; la trascrizione resta finché non elimini la registrazione.`, icon: ShieldCheck, colorVariant: 'emerald' as const },
]);
const breadcrumbs = computed(() => [
    { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
    { title: 'Registrazioni', href: elenco.value },
    { title: r.value.titolo },
]);
</script>

<template>
    <Head :title="registrazione.titolo" />
    <GestionaleLayout>
        <div class="px-6 py-8 space-y-4">
            <PageHeaderGuide
                :page-title="registrazione.titolo"
                :page-subtitle="`${registrazione.quando ?? ''} · ${durataAParole(registrazione.durata_secondi)} · ${ETICHETTA_STATO[registrazione.stato] ?? registrazione.stato}`"
                :guides="pageGuides"
                :breadcrumbs="(breadcrumbs as any)"
                :condominio="(props.condominio as any)"
                :condomini="(props.condomini as any)"
                :back-url="elenco"
                back-text="Registrazioni"
                has-text-guide
                text-guide-title="Guida"
                @open-text-guide="mostraGuida = true"
            />
            <div v-if="flashMessage"><Alert :message="flashMessage.message" :type="flashMessage.type" /></div>

            <!-- Lo stato, quando c'è qualcosa da fare o da aspettare -->
            <div v-if="registrazione.stato === 'in_corso'" class="rounded-xl border border-red-200 bg-red-50 dark:bg-red-950/30 dark:border-red-900 p-3 text-sm flex flex-wrap items-center gap-3" data-testid="stato-in-corso">
                <span class="flex-1">La registrazione è ancora aperta ({{ registrazione.pezzi }} {{ registrazione.pezzi === 1 ? 'pezzo salito' : 'pezzi saliti' }}). Se si sta ancora registrando lasciala andare; se il browser si è chiuso, chiudila qui.</span>
                <Button size="sm" class="h-8" @click="termina">Chiudi e trascrivi</Button>
            </div>
            <div v-else-if="registrazione.stato === 'in_elaborazione'" class="rounded-xl border border-amber-200 bg-amber-50 dark:bg-amber-950/30 dark:border-amber-900 p-3 text-sm flex items-center gap-2" data-testid="stato-in-elaborazione">
                <Loader2 class="w-4 h-4 animate-spin" /> Preparo la trascrizione con le voci{{ servizio.riassunto ? ' e il riassunto' : '' }}: ci vogliono alcuni minuti. La pagina si aggiorna da sola.
            </div>
            <div v-else-if="registrazione.stato === 'errore'" class="rounded-xl border border-rose-200 bg-rose-50 dark:bg-rose-950/30 dark:border-rose-900 p-3 text-sm flex flex-wrap items-center gap-3" data-testid="stato-errore">
                <span class="flex-1">{{ registrazione.errore ?? 'L\'elaborazione non è riuscita.' }}</span>
                <Button v-if="registrazione.audio" size="sm" class="h-8" @click="termina">Riprova</Button>
            </div>
            <p v-if="registrazione.stato === 'pronta' && registrazione.errore" class="rounded-xl border border-amber-200 bg-amber-50 dark:bg-amber-950/30 dark:border-amber-900 p-3 text-sm" data-testid="nota-pronta">{{ registrazione.errore }}</p>
            <p v-if="registrazione.stato === 'pronta' && servizio.riassunto && !registrazione.riassunto" class="text-xs text-slate-500">Il riassunto non è arrivato (Gemini non ha risposto, o la riunione era troppo breve): la trascrizione è completa.</p>

            <!-- I testi -->
            <div v-if="schede.length" class="rounded-xl border bg-white dark:bg-slate-900">
                <div class="flex flex-wrap items-center gap-1 border-b dark:border-slate-800 p-2">
                    <button
                        v-for="s in schede"
                        :key="s.id"
                        type="button"
                        class="h-8 px-3 rounded-md text-sm font-medium"
                        :class="scheda === s.id ? 'bg-accent text-accent-foreground' : 'hover:bg-accent/60 text-slate-600 dark:text-slate-300'"
                        :data-testid="`scheda-${s.id}`"
                        @click="scheda = s.id"
                    >{{ s.titolo }}</button>
                    <Button size="sm" variant="ghost" class="ml-auto h-8 text-xs" @click="copia"><Copy class="w-3.5 h-3.5 mr-1" /> {{ copiato ? 'Copiato' : 'Copia' }}</Button>
                </div>

                <!-- eslint-disable-next-line vue/no-v-html -- il riassunto passa da riassuntoInHtml(), che fa l'escape di tutto il testo -->
                <article v-if="scheda === 'riassunto'" class="p-4 text-sm leading-relaxed riassunto-registrazione" data-testid="testo-riassunto" v-html="riassuntoInHtml(registrazione.riassunto)" />

                <div v-else-if="scheda === 'punti'" class="p-4 space-y-4 text-sm" data-testid="testo-punti">
                    <p class="text-xs text-slate-500">Una bozza da rileggere: la delibera compare solo dove nella riunione è stata detta chiaramente.</p>
                    <section v-for="p in registrazione.bozza_punti ?? []" :key="p.numero" class="space-y-1">
                        <h3 class="font-semibold">{{ p.numero }}. {{ titoloPunto(p.numero) }}</h3>
                        <p v-if="p.discussione" class="whitespace-pre-wrap">{{ p.discussione }}</p>
                        <p v-else class="text-slate-400 italic">Nella registrazione non se n'è parlato.</p>
                        <p v-if="p.delibera" class="rounded-md bg-emerald-50 dark:bg-emerald-950/30 px-2 py-1"><span class="font-medium">Delibera:</span> {{ p.delibera }}</p>
                        <p v-else class="text-xs text-slate-500">Nessuna delibera detta chiaramente.</p>
                    </section>
                </div>

                <div v-else-if="scheda === 'trascrizione'" class="p-4 space-y-3 text-sm leading-relaxed max-h-[70vh] overflow-y-auto" data-testid="testo-trascrizione">
                    <p v-for="(t, i) in turni(registrazione.trascrizione)" :key="i">
                        <span v-if="t.tempo" class="text-xs text-slate-400 tabular-nums mr-1">{{ t.tempo }}</span>
                        <strong v-if="t.voce" class="mr-1">{{ t.voce }}:</strong>{{ t.testo }}
                    </p>
                </div>

                <div v-else class="p-4 text-sm leading-relaxed whitespace-pre-wrap max-h-[70vh] overflow-y-auto" data-testid="testo-diretta">
                    <p class="text-xs text-slate-500 mb-2">Il testo scritto mentre si registrava, senza le voci separate.</p>{{ registrazione.trascrizione_live }}
                </div>
            </div>
            <p v-else-if="registrazione.stato !== 'in_elaborazione'" class="text-sm text-slate-500">Ancora nessun testo.</p>

            <!-- L'assemblea -->
            <div class="rounded-xl border bg-white dark:bg-slate-900 p-4 space-y-2 text-sm" data-testid="collega-assemblea">
                <h3 class="font-semibold flex items-center gap-1.5"><FileText class="w-4 h-4" /> Assemblea</h3>
                <p v-if="nomeAssemblea">Collegata a: <strong>{{ nomeAssemblea }}</strong>.</p>
                <p v-else class="text-slate-500">Non è collegata a un'assemblea.</p>
                <div v-if="assemblee.length" class="flex flex-wrap items-center gap-2">
                    <select v-model="assembleaScelta" class="h-8 rounded-md border bg-background px-2 text-xs max-w-xs" data-testid="scelta-assemblea">
                        <option value="">Scegli un'assemblea…</option>
                        <option v-for="a in assemblee" :key="a.id" :value="a.id">{{ a.nome }}</option>
                    </select>
                    <Button size="sm" variant="outline" class="h-8 text-xs" :disabled="!assembleaScelta || assembleaScelta === registrazione.assemblea_id" @click="collega(Number(assembleaScelta))">Collega</Button>
                    <Button v-if="registrazione.assemblea_id" size="sm" variant="ghost" class="h-8 text-xs" @click="collega(null)">Scollega</Button>
                </div>
                <p class="text-xs text-slate-500">Collegata a un'assemblea con l'ordine del giorno, la registrazione prepara anche una bozza per ogni punto.</p>
            </div>

            <!-- L'audio e l'eliminazione -->
            <div class="rounded-xl border bg-white dark:bg-slate-900 p-4 space-y-2 text-sm" data-testid="audio">
                <h3 class="font-semibold flex items-center gap-1.5"><ShieldCheck class="w-4 h-4" /> Audio e privacy</h3>
                <p v-if="registrazione.avviso_presenti_quando" class="text-xs text-slate-500">Avviso ai presenti confermato il {{ registrazione.avviso_presenti_quando }}<template v-if="registrazione.autore"> da {{ registrazione.autore }}</template>.</p>
                <p v-if="registrazione.audio">
                    Audio conservato: {{ megabyte(registrazione.audio_byte) }}.
                    <template v-if="registrazione.audio_scade_il">Si cancella da solo il {{ registrazione.audio_scade_il }}.</template>
                    <template v-else>Si cancellerà da solo {{ servizio.giorni_audio }} giorni dopo la fine della registrazione.</template>
                </p>
                <p v-else-if="registrazione.audio_cancellato_at">Audio cancellato il {{ registrazione.audio_cancellato_il }}. I testi restano.</p>
                <p v-else class="text-slate-500">Nessun audio conservato.</p>
                <div class="flex flex-wrap gap-2 pt-1">
                    <Button v-if="registrazione.audio && registrazione.stato !== 'in_corso' && registrazione.stato !== 'in_elaborazione'" size="sm" variant="outline" class="h-8 text-xs" @click="confermaAudio = true">Cancella l'audio</Button>
                    <Button size="sm" variant="outline" class="h-8 text-xs text-rose-700 border-rose-200 hover:bg-rose-50" @click="confermaElimina = true">Elimina la registrazione</Button>
                    <Link :href="elenco" class="inline-flex items-center h-8 px-3 rounded-md text-xs hover:bg-accent">Torna alle registrazioni</Link>
                </div>
            </div>
        </div>

        <ConfirmDialog
            v-model="confermaAudio"
            title="Cancellare l'audio?"
            description="I file audio di questa registrazione si cancellano. La trascrizione, il riassunto e la bozza restano. Senza audio non si potrà più rifare la trascrizione."
            confirm-text="Cancella l'audio"
            variant="warning"
            @confirm="cancellaAudio"
        />
        <ConfirmDialog
            v-model="confermaElimina"
            title="Eliminare la registrazione?"
            description="Si cancellano l'audio, la trascrizione, il riassunto e la bozza. Non si torna indietro."
            confirm-text="Elimina"
            variant="destructive"
            @confirm="elimina"
        />
        <RegistrazioniGuide v-model:open="mostraGuida" :giorni-audio="servizio.giorni_audio" />
    </GestionaleLayout>
</template>

<style scoped>
.riassunto-registrazione :deep(h3) { font-weight: 600; margin: 0.9rem 0 0.25rem; }
.riassunto-registrazione :deep(h3:first-child) { margin-top: 0; }
.riassunto-registrazione :deep(p) { margin: 0 0 0.4rem; }
.riassunto-registrazione :deep(ul) { list-style: disc; margin: 0 0 0.5rem 1.2rem; }
.riassunto-registrazione :deep(li) { margin: 0.15rem 0; }
</style>
