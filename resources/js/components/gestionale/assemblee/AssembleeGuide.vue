<script setup lang="ts">
/**
 * La guida delle assemblee, nella finestra che si apre di lato (Web Pro Italia, 17/11/2026): chi ha diritto di
 * intervenire e come si contano le teste, costituzione e maggioranze (art. 1136 c.c.), voti ed esito, deleghe, il verbale
 * e le scelte fatte dal programma che lo studio può chiedere di cambiare.
 */
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { ChevronRight, Gavel } from 'lucide-vue-next';

defineProps<{ open: boolean }>();
defineEmits(['update:open']);

const maggioranze = [
    { nome: 'Ordinaria (art. 1136 c. 2-3)', serve: 'Le decisioni di tutti i giorni: rendiconto, preventivo, spese ordinarie, regolamento che non tocca i diritti dei singoli.', regola: 'Più della metà degli intervenuti e almeno 500 millesimi in prima convocazione; più della metà degli intervenuti e almeno 333,33 millesimi in seconda.' },
    { nome: 'Qualificata, metà del valore (art. 1136 c. 4)', serve: 'Nomina e revoca dell\'amministratore, liti fuori dalle sue attribuzioni, ricostruzione dell\'edificio, riparazioni straordinarie di notevole entità.', regola: 'Più della metà degli intervenuti e almeno 500 millesimi, in prima e in seconda.' },
    { nome: 'Qualificata, due terzi del valore (art. 1136 c. 5)', serve: 'Le innovazioni dell\'art. 1120 c. 1: opere nuove che migliorano o rendono più comodo l\'uso delle parti comuni.', regola: 'Più della metà degli intervenuti e almeno 666,67 millesimi.' },
    { nome: 'Unanimità', serve: 'Le decisioni che toccano i diritti di ognuno.', regola: 'Tutti i condòmini e tutti i 1.000 millesimi.' },
    { nome: 'Informativa', serve: 'Comunicazioni dell\'amministratore, varie ed eventuali.', regola: 'Non si vota: si discute e si mette a verbale.' },
];
</script>

<template>
    <Sheet :open="open" @update:open="$emit('update:open', $event)">
        <SheetContent class="sm:max-w-2xl overflow-y-auto w-full sm:w-[640px] p-0" data-testid="guida-assemblee">
            <div class="px-6 py-8">
                <SheetHeader class="mb-8">
                    <div class="flex items-center gap-3 mb-2">
                        <div class="p-2 bg-blue-100 text-blue-700 rounded-lg dark:bg-blue-900 dark:text-blue-300"><Gavel class="w-6 h-6" /></div>
                        <SheetTitle class="text-2xl font-extrabold tracking-tight">Guida: assemblee</SheetTitle>
                    </div>
                    <SheetDescription class="text-base text-slate-600 dark:text-slate-400">
                        Come il programma conta teste e millesimi, quali maggioranze conosce, come nasce il verbale. Le scelte che il programma fa da solo
                        sono scritte qui: se lo studio vuole regole diverse, si cambiano.
                    </SheetDescription>
                </SheetHeader>

                <div class="space-y-8 text-sm text-slate-700 dark:text-slate-300">
                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">Chi ha diritto di intervenire</h3>
                        <p class="mb-3">L'elenco si prepara dalle presenze con «Prepara l'elenco», dai titolari delle unità nel giorno dell'assemblea e dalla tabella scelta per i conteggi.</p>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>Un condòmino con più unità è una testa sola</strong>, con la somma dei millesimi (l'appartamento e il box dello stesso proprietario).</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>I comproprietari di un'unità sono una testa sola</strong>: hanno diritto a un solo rappresentante, che designano fra loro (art. 67 disp. att. c.c.). Il programma propone quello con la quota più alta; si cambia dall'elenco, con «Chi vota».</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Chi ha un'unità da solo e un'altra in comproprietà conta due volte: una per la sua unità, una come comproprietario. <em>Scelta del programma.</em></span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>Usufrutto</strong>: la testa è del nudo proprietario, e l'usufruttuario si può scegliere come chi vota. La legge dà all'usufruttuario il voto sugli affari di ordinaria amministrazione e sul godimento dei servizi comuni, al nudo proprietario le altre delibere: in un'assemblea con soli punti ordinari si sceglie l'usufruttuario.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>L'inquilino non è fra gli aventi diritto: se interviene con una delega, si segna come delegato.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Un'unità senza titolari in archivio non fa una testa, ma i suoi millesimi restano nel valore dell'edificio: la pagina lo segnala.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">Quando l'assemblea è validamente costituita</h3>
                        <ul class="space-y-2 mb-3">
                            <li><strong>Prima convocazione</strong>: più della metà dei condòmini e almeno due terzi del valore dell'edificio (666,67 millesimi).</li>
                            <li><strong>Seconda convocazione</strong>: almeno un terzo dei condòmini e un terzo del valore (333,33 millesimi).</li>
                        </ul>
                        <p>Contano i presenti di persona e per delega che sono in sala (non usciti). Finché non scegli la convocazione tenuta, i conti seguono la prima, che chiede di più. Nel verbale la costituzione si conta su chi c'era all'ora d'inizio: chi arriva dopo («Arrivato adesso») compare con «entra alle…».</p>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">Le maggioranze dei punti</h3>
                        <div class="grid grid-cols-1 gap-3">
                            <div v-for="m in maggioranze" :key="m.nome" class="p-4 bg-white dark:bg-slate-900 rounded-lg border shadow-sm">
                                <h4 class="font-bold mb-1">{{ m.nome }}</h4>
                                <p class="text-[13px] text-slate-600 dark:text-slate-400"><strong>Serve per:</strong> {{ m.serve }}</p>
                                <p class="text-[13px] text-slate-600 dark:text-slate-400"><strong>Regola:</strong> {{ m.regola }}</p>
                            </div>
                        </div>
                        <p class="mt-3">Quale maggioranza serve a una delibera lo decide chi prepara l'ordine del giorno. Le leggi speciali (barriere architettoniche, impianti, risparmio energetico…) hanno quorum loro che il programma non conosce: si sceglie la maggioranza più vicina e lo si scrive nella descrizione del punto.</p>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">I voti e l'esito</h3>
                        <ul class="space-y-2">
                            <li>«Tutti i presenti favorevoli» registra il voto di tutti quelli in sala; poi si toccano i pochi contrari o astenuti.</li>
                            <li><strong>Gli intervenuti di un punto sono quelli che hanno un voto registrato</strong>, astenuti compresi. Chi è in sala senza un voto è segnalato, non contato. <em>Scelta del programma.</em></li>
                            <li>«Più della metà degli intervenuti»: l'astenuto conta fra gli intervenuti e non fra i favorevoli. <em>Scelta del programma.</em></li>
                            <li>Il programma <strong>calcola e propone</strong> l'esito; lo conferma la persona («Conferma: approvato»). Può anche segnare «rinviato».</li>
                        </ul>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">Le deleghe</h3>
                        <ul class="space-y-2">
                            <li>Il delegato si sceglie in rubrica o si scrive a mano. Una delega vale come presenza.</li>
                            <li>Con più di venti condòmini un delegato non può rappresentarne più di un quinto, né più di un quinto del valore (art. 67 disp. att. c.c.): la pagina avvisa, non blocca.</li>
                            <li>All'amministratore non si possono conferire deleghe: la pagina avvisa se il delegato sembra essere l'amministratore (lo stesso nome dello studio o di un utente amministratore).</li>
                        </ul>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">Numeri senza arrotondamenti</h3>
                        <p>I millesimi si sommano come numeri interi (in centomillesimi) e i confronti con metà, un terzo e due terzi del valore si fanno senza virgole: 666,67 basta per i due terzi, 666,66 no. Il valore dell'edificio è il totale della tabella scelta; se la tabella non somma esattamente 1.000, i conteggi la riportano a mille e la pagina lo dice. L'arrotondamento a due cifre serve solo a scrivere i numeri.</p>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">Il verbale</h3>
                        <ul class="space-y-2">
                            <li>Si genera da un <strong>modello dello studio</strong> («Modelli di verbale»): un testo con i segnaposto, che il programma riempie con i dati. Poi il testo è libero: si corregge per intero, a mano o da un assistente.</li>
                            <li>Discussione e delibera di ogni punto si scrivono nei loro campi e entrano nel testo alla generazione. Rigenerare sovrascrive il testo, e chiede la conferma.</li>
                            <li>La votazione di ogni punto riporta favorevoli, contrari e astenuti con i nomi e i millesimi, come servono per l'art. 1137 c.c.</li>
                            <li>Stati: bozza → da approvare → approvato. <strong>Lo approva una persona</strong> (mai un assistente AI), con il permesso «Approva verbali». Il PDF va nell'archivio dei documenti del condominio, categoria «Verbali di assemblea», non ancora pubblicato ai condòmini. Da lì niente si modifica; per correggere si apre una nuova versione.</li>
                        </ul>
                    </section>
                </div>
            </div>
        </SheetContent>
    </Sheet>
</template>
