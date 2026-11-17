<script setup lang="ts">
/**
 * La guida delle assemblee, nella finestra che si apre di lato (Web Pro Italia, 17/11/2026): chi ha diritto di
 * intervenire e come si contano le teste, costituzione e maggioranze (art. 1136 c.c.), voti ed esito, deleghe, il verbale
 * e le scelte fatte dal programma che lo studio può chiedere di cambiare. In testa, quello che si fa prima e dopo
 * l'assemblea: la convocazione, il foglio delle firme, il verbale mandato agli assenti.
 */
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { ChevronRight, Gavel } from 'lucide-vue-next';

defineProps<{ open: boolean }>();
defineEmits(['update:open']);

const maggioranze = [
    { nome: 'Ordinaria (art. 1136 c. 2-3)', serve: 'Le decisioni di tutti i giorni: rendiconto, preventivo, spese ordinarie.', regola: 'Più della metà degli intervenuti e almeno 500 millesimi in prima convocazione; più della metà degli intervenuti e almeno 333,33 millesimi in seconda.' },
    { nome: 'Qualificata, metà del valore (art. 1136 c. 4)', serve: 'Nomina e revoca dell\'amministratore, liti fuori dalle sue attribuzioni, ricostruzione dell\'edificio, riparazioni straordinarie di notevole entità, approvazione e modifica del regolamento (art. 1138 c.c.).', regola: 'Più della metà degli intervenuti e almeno 500 millesimi, in prima e in seconda.' },
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
                    <section data-testid="guida-convocazione">
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">La convocazione</h3>
                        <ul class="space-y-2">
                            <li>L'avviso si genera da un <strong>modello dello studio</strong> («Modelli», scheda «Avviso di convocazione»), come il verbale: il programma scrive le due convocazioni, il luogo, l'ordine del giorno e la modalità; poi il testo è libero. Rigenerare lo sostituisce, e lo chiede prima.</li>
                            <li>In fondo al modello di serie c'è il <strong>modulo di delega</strong>, staccato da una riga di tre trattini (<code>---</code>). L'avviso richiama la delega scritta dell'art. 67 disp. att. c.c. Nell'avviso generico il modulo è in bianco; in quello di ogni persona porta già il suo nome e le sue unità.</li>
                            <li><strong>I cinque giorni.</strong> L'art. 66 disp. att. c.c. chiede che l'avviso sia comunicato almeno cinque giorni prima della data della prima convocazione. Il programma confronta le due date (i giorni di calendario fra la data dell'avviso, o oggi se non è scritta, e il giorno della prima convocazione) e, se sono meno di cinque, lo dice; se sono esattamente cinque dice che non resta margine. Non blocca l'invio, e non decide quando un avviso si considera comunicato (alla spedizione o al ricevimento) né come si contano i giorni ai fini di legge: lo valuta lo studio, che per la posta tiene un margine. <em>Scelta del programma.</em></li>
                            <li><strong>A chi va.</strong> A ogni titolare di un diritto reale sulle unità (proprietari, comproprietari, nudi proprietari e usufruttuari), non solo a chi rappresenta la testa: nessuno che abbia titolo resta senza avviso. Chi non deve riceverlo si toglie dall'elenco prima di mandare. Un'unità senza titolari in archivio non ha nessuno a cui scrivere: la pagina lo segnala. <em>Scelta del programma.</em></li>
                            <li><strong>Come parte.</strong> Per email a chi ha un indirizzo (primaria e secondaria; la PEC solo se la spunti, e parte come un'email semplice), con l'avviso in PDF allegato. «Inviata» vuol dire che il server di posta ha accettato il messaggio, non che è stato letto. Per gli altri: stampa «Uno per persona», spedisci o consegna, e segna com'è andata con <strong>«Registra la spedizione»</strong> (raccomandata, PEC mandata dal tuo programma, consegna a mano), con la data e il numero.</li>
                            <li>Quali mezzi valgono per comunicare l'avviso lo dice l'art. 66 disp. att. c.c.: lo verifica lo studio. Il programma non sceglie al posto suo: registra, per ogni persona, quando e come l'avviso è partito. È quell'elenco che serve a dimostrare la regolare convocazione.</li>
                            <li>Quando la convocazione parte, la <strong>data dell'avviso</strong> si riempie (se era vuota) e l'assemblea passa da «In preparazione» a «Convocata». Se dopo cambi il testo, la pagina dice a chi era già stato mandato quello di prima.</li>
                            <li>Se l'assemblea è anche in videoconferenza, il collegamento si scrive nella pagina della convocazione ed entra nell'avviso. Se la riunione a distanza è ammessa (regolamento, consenso dei condòmini) il programma non lo controlla.</li>
                        </ul>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">Il foglio delle firme</h3>
                        <p>Si stampa dalla pagina dell'assemblea («Foglio firme») e si porta in sala: una riga per avente diritto, con le unità e i millesimi, e lo spazio per la firma di chi è presente, per il nome del delegato e per la sua firma; in fondo i totali e le firme di presidente e segretario. Se l'elenco delle presenze non è ancora stato preparato, il foglio lo calcola dai titolari senza salvarlo. Le presenze poi si segnano nel programma: il foglio firmato si conserva con il verbale.</p>
                    </section>

                    <section data-testid="guida-verbale-assenti">
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">Il verbale agli assenti</h3>
                        <ul class="space-y-2">
                            <li>Dopo l'approvazione, nella pagina del verbale compare <strong>«Manda il verbale»</strong>. Prima no: un verbale non approvato non si manda.</li>
                            <li>Di serie va <strong>agli assenti</strong>: per loro i trenta giorni per impugnare le deliberazioni decorrono dalla comunicazione (art. 1137 c.c.). Chi è intervenuto per delega non è fra gli assenti. Con «a tutti i condòmini» va a tutti.</li>
                            <li>Per email a chi ha un indirizzo, con il PDF approvato allegato e un testo breve (il modello «Trasmissione del verbale», che lo studio cambia). Per chi non ha un indirizzo: «Lettere di trasmissione» stampa la lettera da accompagnare al verbale, e «Registra la spedizione» segna com'è partita.</li>
                            <li>Per ogni persona resta scritto quando e come le è stato mandato. Se il verbale viene riaperto e approvato di nuovo, gli invii della versione di prima restano nel registro e la nuova versione si manda da capo.</li>
                        </ul>
                    </section>

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
                            <li><strong>Gli intervenuti di un punto sono tutti quelli che sono in sala</strong> (presenti o per delega, non usciti) <strong>più chi ha un voto registrato su quel punto</strong>: chi è uscito dopo aver votato tiene il suo voto. <em>Scelta del programma.</em></li>
                            <li><strong>Chi è in sala senza un voto registrato è un «presente senza voto»</strong>: conta fra gli intervenuti come non votante, e per la maggioranza pesa come un astenuto. Il conteggio lo mostra a parte, la pagina avvisa e il verbale lo scrive con il nome. Se non era più in sala, segna la sua uscita. Il programma guarda chi è in sala adesso: chi arriva dopo la votazione di un punto risulta «presente senza voto» anche su quel punto, e il verbale va corretto a mano in quel passaggio. <em>Scelta del programma.</em></li>
                            <li>«Più della metà degli intervenuti»: l'astenuto e il presente senza voto contano fra gli intervenuti e non fra i favorevoli. <em>Scelta del programma.</em></li>
                            <li><strong>Il voto di chi è segnato assente non conta</strong>: non si può registrare, e segnando assente chi ha già votato i suoi voti si tolgono (la pagina lo dice).</li>
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
                            <li>Si genera da un <strong>modello dello studio</strong> («Modelli»): un testo con i segnaposto, che il programma riempie con i dati. Poi il testo è libero: si corregge per intero, a mano o da un assistente.</li>
                            <li>Discussione e delibera di ogni punto si scrivono nei loro campi e entrano nel testo alla generazione. Rigenerare sovrascrive il testo, e chiede la conferma.</li>
                            <li>La votazione di ogni punto riporta favorevoli, contrari e astenuti con i nomi e i millesimi, come servono per l'art. 1137 c.c.</li>
                            <li><strong>Prima di mandarlo in approvazione</strong> il programma controlla che il testo corrisponda ai dati: le ore d'inizio e di fine, nessuna parte rimasta da compilare («______», «Esito da confermare»), esiti coerenti con i voti registrati, e nessun dato cambiato dopo l'ultimo testo (in quel caso si rigenera, o si rilegge il testo e lo si salva). <strong>In attesa di approvazione niente dell'assemblea si modifica</strong>: per correggere, «Torna in bozza».</li>
                            <li>Stati: bozza → da approvare → approvato. <strong>Lo approva una persona</strong> (mai un assistente AI), con il permesso «Approva verbali». Il PDF va nell'archivio dei documenti del condominio, categoria «Verbali di assemblea», non ancora pubblicato ai condòmini. Da lì niente si modifica; per correggere si apre una nuova versione.</li>
                        </ul>
                    </section>
                </div>
            </div>
        </SheetContent>
    </Sheet>
</template>
