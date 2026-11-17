<script setup lang="ts">
/**
 * Le registrazioni delle riunioni del condominio (Web Pro Italia, 17/11/2026): il registratore in alto, sotto l'elenco. Una registrazione
 * rimasta aperta (il browser si è chiuso) si chiude da qui con «Chiudi e trascrivi»; una finita in errore si riprova. Mentre una
 * registrazione è in preparazione l'elenco si rinfresca da solo.
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import Alert from '@/components/Alert.vue';
import { Button } from '@/components/ui/button';
import RegistratoreAssemblea from '@/components/registrazioni/RegistratoreAssemblea.vue';
import RegistrazioniGuide from '@/components/guides/RegistrazioniGuide.vue';
import { usePermission } from '@/composables/permissions';
import { ETICHETTA_STATO, durataAParole } from '@/lib/registrazioni/registratore';
import { FileText, Loader2, Mic, MonitorSpeaker, ShieldCheck } from 'lucide-vue-next';
import type { Flash } from '@/types/flash';

interface Riga {
    id: number; titolo: string; stato: 'in_corso' | 'in_elaborazione' | 'pronta' | 'errore'; assemblea_id: number | null; quando: string | null;
    durata_secondi: number; pezzi: number; ha_riassunto: boolean; ha_bozza: boolean; audio_cancellato_at: string | null; errore: string | null;
}
const props = defineProps<{
    condominio: { id: number; nome: string }; condomini: any; esercizio: any;
    registrazioni: Riga[];
    servizio: { trascrizione: boolean; riassunto: boolean; giorni_audio: number; secondi_segmento: number };
}>();

const { generateRoute, generatePath } = usePermission();
const page = usePage<{ flash?: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash?.message);
const mostraGuida = ref(false);
const inRegistrazione = ref<number | null>(null);

const apri = (r: Riga) => route(generateRoute('gestionale.registrazioni.show'), { condominio: props.condominio.id, registrazione: r.id });
const chiudi = (r: Riga) => router.post(route(generateRoute('gestionale.registrazioni.termina'), { condominio: props.condominio.id, registrazione: r.id }), {}, { preserveScroll: true });
const rinfresca = () => router.reload({ only: ['registrazioni'] });

// Finché qualcosa è in preparazione l'elenco si rinfresca ogni 5 secondi.
let giro: ReturnType<typeof setInterval> | null = null;
watch(() => props.registrazioni.some((r) => r.stato === 'in_elaborazione'), (attesa) => {
    if (attesa && !giro) giro = setInterval(rinfresca, 5000);
    if (!attesa && giro) {
        clearInterval(giro);
        giro = null;
    }
}, { immediate: true });
onBeforeUnmount(() => giro && clearInterval(giro));

const colore: Record<string, string> = {
    in_corso: 'bg-red-50 text-red-700 border-red-200 dark:bg-red-950/40 dark:text-red-300 dark:border-red-900',
    in_elaborazione: 'bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-950/40 dark:text-amber-300 dark:border-amber-900',
    pronta: 'bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-300 dark:border-emerald-900',
    errore: 'bg-rose-50 text-rose-700 border-rose-200 dark:bg-rose-950/40 dark:text-rose-300 dark:border-rose-900',
};

const pageGuides = computed(() => [
    { title: 'A che cosa serve', description: 'Registri una riunione dal computer e ricevi la trascrizione con le voci separate e un riassunto; per un\'assemblea, anche una bozza per ogni punto dell\'ordine del giorno.', icon: Mic, colorVariant: 'blue' as const },
    { title: 'In videochiamata', description: 'Con Chrome o Edge prendi anche l\'audio della videochiamata: scegli la scheda di Meet, Zoom o Teams e lascia spuntato «Condividi audio».', icon: MonitorSpeaker, colorVariant: 'amber' as const },
    { title: 'Privacy', description: `Prima si avvisano i presenti. L'audio si cancella da solo dopo ${props.servizio.giorni_audio} giorni; la trascrizione resta finché non elimini la registrazione.`, icon: ShieldCheck, colorVariant: 'emerald' as const },
]);

const breadcrumbs = computed(() => [
    { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
    { title: 'Registrazioni' },
]);
</script>

<template>
    <Head title="Registrazioni" />
    <GestionaleLayout>
        <div class="px-6 py-8 space-y-4">
            <PageHeaderGuide
                page-title="Registrazioni"
                page-subtitle="Le riunioni registrate e trascritte, per scrivere il verbale."
                :guides="pageGuides"
                :breadcrumbs="(breadcrumbs as any)"
                :condominio="(props.condominio as any)"
                :condomini="(props.condomini as any)"
                has-text-guide
                text-guide-title="Guida"
                @open-text-guide="mostraGuida = true"
            />
            <div v-if="flashMessage"><Alert :message="flashMessage.message" :type="flashMessage.type" /></div>

            <RegistratoreAssemblea
                :condominio-id="condominio.id"
                :disponibile="servizio.trascrizione"
                @avviata="(id: number) => { inRegistrazione = id; rinfresca(); }"
                @terminata="() => { inRegistrazione = null; rinfresca(); }"
            />

            <div class="rounded-xl border bg-white dark:bg-slate-900 divide-y dark:divide-slate-800" data-testid="elenco-registrazioni">
                <div v-for="r in registrazioni" :key="r.id" class="p-3 text-sm flex flex-wrap items-center gap-3" data-testid="registrazione">
                    <div class="flex-1 min-w-[220px]">
                        <Link :href="apri(r)" class="font-semibold hover:underline">{{ r.titolo }}</Link>
                        <p class="text-xs text-slate-500">
                            {{ r.quando }} · {{ durataAParole(r.durata_secondi) }}
                            <template v-if="r.assemblea_id"> · collegata a un'assemblea</template>
                            <template v-if="r.ha_bozza"> · bozza per punti</template>
                            <template v-if="r.audio_cancellato_at"> · audio cancellato</template>
                        </p>
                        <p v-if="r.stato === 'in_corso' && r.id !== inRegistrazione" class="text-xs text-slate-500">
                            Ancora aperta ({{ r.pezzi }} {{ r.pezzi === 1 ? 'pezzo salito' : 'pezzi saliti' }}): se il browser si è chiuso, chiudila e trascrivila da qui.
                        </p>
                        <p v-if="r.stato === 'errore' && r.errore" class="text-xs text-rose-700 dark:text-rose-300">{{ r.errore }}</p>
                    </div>
                    <span class="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs font-medium" :class="colore[r.stato]">
                        <Loader2 v-if="r.stato === 'in_elaborazione'" class="w-3 h-3 animate-spin" />
                        {{ r.id === inRegistrazione ? 'Sto registrando' : ETICHETTA_STATO[r.stato] ?? r.stato }}
                    </span>
                    <Button v-if="r.stato === 'in_corso' && r.id !== inRegistrazione" size="sm" variant="outline" class="h-7 text-xs" @click="chiudi(r)">Chiudi e trascrivi</Button>
                    <Button v-else-if="r.stato === 'errore' && !r.audio_cancellato_at" size="sm" variant="outline" class="h-7 text-xs" @click="chiudi(r)">Riprova</Button>
                    <Link :href="apri(r)" class="inline-flex items-center gap-1 h-7 px-2 rounded-md text-xs hover:bg-accent"><FileText class="w-3.5 h-3.5" /> Apri</Link>
                </div>
                <p v-if="!registrazioni.length" class="p-4 text-sm text-slate-500">Nessuna registrazione per questo condominio.</p>
            </div>
        </div>
        <RegistrazioniGuide v-model:open="mostraGuida" :giorni-audio="servizio.giorni_audio" />
    </GestionaleLayout>
</template>
