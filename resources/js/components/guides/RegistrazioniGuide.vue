<script setup lang="ts">
/**
 * La guida delle «Registrazioni» del condominio, nella finestra che si apre di lato: che cosa fa, l'avviso ai presenti, l'assemblea in
 * videochiamata (come si condivide l'audio del computer), che cosa succede durante e dopo, e la privacy (l'audio si cancella da solo dopo
 * i giorni impostati, la trascrizione resta finché non elimini la registrazione).
 */
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { ChevronRight, FileText, Mic, MonitorSpeaker, ShieldCheck, Users } from 'lucide-vue-next';

defineProps<{ open: boolean; giorniAudio?: number }>();
defineEmits(['update:open']);
</script>

<template>
    <Sheet :open="open" @update:open="$emit('update:open', $event)">
        <SheetContent class="sm:max-w-2xl overflow-y-auto w-full sm:w-[600px] p-0" data-testid="guida-registrazioni">
            <div class="px-6 py-8">
                <SheetHeader class="mb-8">
                    <div class="flex items-center gap-3 mb-2">
                        <div class="p-2 bg-red-100 text-red-700 rounded-lg dark:bg-red-900 dark:text-red-300">
                            <Mic class="w-6 h-6" />
                        </div>
                        <SheetTitle class="text-2xl font-extrabold tracking-tight">Guida: registrazioni</SheetTitle>
                    </div>
                    <SheetDescription class="text-base text-slate-600 dark:text-slate-400">
                        Registrare una riunione per scrivere il verbale: prima di partire, in videochiamata, durante, dopo, e che fine fa l'audio.
                    </SheetDescription>
                </SheetHeader>

                <div class="space-y-8 text-sm text-slate-700 dark:text-slate-300">
                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">Che cosa fa</h3>
                        <p class="mb-3">
                            Registra la riunione dal microfono del computer e la trascrive. Mentre parlate il testo compare a pezzi, uno ogni 25 secondi.
                            Quando premi <strong>Ferma</strong>, in qualche minuto arrivano la <strong>trascrizione con le voci separate</strong>
                            («Persona 1», «Persona 2»…) e un <strong>riassunto</strong> con gli argomenti, le decisioni e le cose da fare.
                        </p>
                        <p>
                            Se la registrazione è collegata a un'assemblea con l'ordine del giorno, arriva anche una <strong>bozza per ogni punto</strong>:
                            che cosa si è detto e, solo se è stata detta chiaramente, la delibera. È una bozza: il verbale lo rileggi e lo firmi tu.
                        </p>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">Prima di partire: l'avviso ai presenti</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-red-500 shrink-0 mt-0.5" /><span>Di' ai presenti (e a chi è collegato) che la riunione viene registrata <strong>solo per scrivere il verbale</strong>.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-red-500 shrink-0 mt-0.5" /><span>Poi spunta la casella «Ho avvisato i presenti…»: senza, il tasto per registrare non parte. Il gestionale si annota quando l'hai confermato.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-red-500 shrink-0 mt-0.5" /><span>Il titolo si può lasciare vuoto: lo propone il riassunto.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><MonitorSpeaker class="w-5 h-5" /> Assemblea in videochiamata</h3>
                        <p class="mb-3">
                            Con Chrome o Edge sul computer c'è la casella <strong>«Prendi anche l'audio del computer (videochiamata)»</strong>, già spuntata:
                            così entrano anche le voci di chi è collegato con Meet, Zoom o Teams, mescolate al tuo microfono.
                        </p>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-3">
                            <div class="p-4 bg-white dark:bg-slate-900 rounded-lg border shadow-sm">
                                <h4 class="font-bold mb-1">Videochiamata nel browser</h4>
                                <p class="text-[13px] text-slate-600 dark:text-slate-400">
                                    Quando premi Registra il browser chiede che cosa condividere: scegli la <strong>scheda</strong> della videochiamata (Meet,
                                    Zoom o Teams aperti nel browser) e lascia spuntato <strong>«Condividi anche l'audio della scheda»</strong>. Poi Condividi.
                                </p>
                            </div>
                            <div class="p-4 bg-white dark:bg-slate-900 rounded-lg border shadow-sm">
                                <h4 class="font-bold mb-1">Videochiamata nell'app</h4>
                                <p class="text-[13px] text-slate-600 dark:text-slate-400">
                                    Su Windows scegli <strong>«Schermo intero»</strong> e spunta <strong>«Condividi anche l'audio del sistema»</strong>. Sul Mac
                                    l'audio di un'app non si può condividere: apri la riunione nel browser, oppure lascia che il microfono senta le casse.
                                </p>
                            </div>
                        </div>
                        <ul class="space-y-2">
                            <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Si registra solo l'audio: il video della condivisione si ferma subito.</span></li>
                            <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Se annulli la condivisione, o dimentichi la spunta dell'audio, la registrazione parte lo stesso con il solo microfono, e la pagina te lo dice.</span></li>
                            <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Se in sala la videochiamata esce dalle casse, il microfono la sente già: con la casella spuntata le voci collegate entrano due volte, con un'eco. Con le cuffie, o in una riunione tutta a distanza, tienila spuntata.</span></li>
                            <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Safari e Firefox registrano solo il microfono.</span></li>
                        </ul>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">Durante la riunione</h3>
                        <ul class="space-y-2">
                            <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-emerald-500 shrink-0 mt-0.5" /><span>Lascia aperta la pagina: puoi usare altre schede e altri programmi. Lo schermo resta acceso finché registri; non mettere il computer in stop.</span></li>
                            <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-emerald-500 shrink-0 mt-0.5" /><span>Ogni 25 secondi un pezzo sale al server: se il browser si chiude o la rete cade, quello che è salito è salvo. La registrazione resta «in corso» nell'elenco: la chiudi con <strong>Chiudi e trascrivi</strong>.</span></li>
                            <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-emerald-500 shrink-0 mt-0.5" /><span>Il microfono va vicino a chi parla: un portatile in fondo al tavolo sente poco.</span></li>
                        </ul>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><FileText class="w-5 h-5" /> Dopo</h3>
                        <p class="mb-3">
                            Premi <strong>Ferma</strong>: la pagina manda gli ultimi pezzi e chiude. Trascrizione e riassunto arrivano in qualche minuto, anche
                            se chiudi la pagina. Se qualcosa non va, la registrazione dice «Errore» con il motivo: l'audio è salvo e c'è <strong>Riprova</strong>.
                        </p>
                        <p class="flex gap-2"><Users class="w-4 h-4 shrink-0 mt-0.5" /><span>Le voci non hanno un nome: «Persona 1» è la prima voce che parla. Chi è chi lo capisci dal contesto, e lo scrivi nel verbale.</span></p>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><ShieldCheck class="w-5 h-5" /> Privacy: che fine fa l'audio</h3>
                        <ul class="space-y-2">
                            <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-slate-500 shrink-0 mt-0.5" /><span>L'audio sta in una cartella privata del server, mai pubblica, e non entra nei backup.</span></li>
                            <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-slate-500 shrink-0 mt-0.5" /><span><strong>Si cancella da solo dopo {{ giorniAudio ?? 30 }} giorni</strong> dalla fine della registrazione. Lo puoi cancellare prima con <strong>Cancella l'audio</strong>.</span></li>
                            <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-slate-500 shrink-0 mt-0.5" /><span>La trascrizione, il riassunto e la bozza <strong>restano finché non elimini la registrazione</strong>.</span></li>
                            <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-slate-500 shrink-0 mt-0.5" /><span>Per trascrivere, l'audio va a Deepgram; per il riassunto, il testo va a Gemini di Google.</span></li>
                        </ul>
                    </section>
                </div>
            </div>
        </SheetContent>
    </Sheet>
</template>
