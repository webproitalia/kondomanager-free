# Agenti AI: usare KondoManager con una chiave

Questa versione (fork di Web Pro Italia) permette a un agente AI (Claude, un server MCP, uno script) di usare il gestionale al posto di una persona, con una chiave personale.

## Le chiavi

Impostazioni → **Agenti AI**. Una chiave:

- appartiene a un utente amministratore o collaboratore, e ne eredita ruoli e permessi;
- può essere di **sola consultazione** o anche di **modifica**;
- può scadere dopo 30, 90 o 365 giorni, e si revoca con un clic;
- si mostra una volta sola, alla creazione: nel database resta solo la sua impronta (sha256);
- può avere il permesso di **eliminare un condominio con tutto il suo contenuto** (spento di serie; vedi sotto).

La stessa pagina mostra il **registro** degli agenti: ogni modifica e ogni richiesta rifiutata (anche le letture),
con quando, quale chiave, quale funzione, l'esito e, per i rifiuti, la frase con cui il gestionale ha detto di no e i
nomi dei campi sbagliati. I dati inviati non si registrano mai.

### Eliminare un condominio con tutto il suo contenuto

Chi carica i condomìni sbaglia dei caricamenti, e il tasto «elimina» normale si ferma (giustamente) davanti a un
condominio che ha casse, pagamenti o deleghe. La funzione `condomini.elimina-tutto`
(`DELETE /condomini/{condominio}/tutto`) è la strada dichiarata per buttare un caricamento e rifarlo:

- chiede `conferma_nome`, il nome del condominio riscritto uguale (maiuscole e spazi doppi non contano);
- a un agente serve il permesso sulla chiave, che l'amministratore accende e spegne nella pagina Agenti AI
  (la pagina è vietata agli agenti: una chiave non può darselo da sola);
- in anteprima (`X-Kondo-Prova: 1`) dice che cosa verrebbe eliminato e non cancella niente;
- prima di cancellare salva una copia: `storage/app/private/condomini-eliminati/<data>-<id>-<nome>/` con
  `dati.json.gz` (tutte le righe del condominio, tabella per tabella) e i file dei suoi documenti; le copie si
  tengono 30 giorni;
- toglie dalla rubrica solo le persone che non hanno altri condomìni né un accesso al programma.

La rotta `condomini.destroy` resta vietata agli agenti e continua a comportarsi come prima.

## Come si usa

L'agente chiama gli **stessi indirizzi del browser** con l'intestazione `Authorization: Bearer kag_…`:

```bash
curl -H "Authorization: Bearer kag_…" https://gestionale.example/condomini
curl -H "Authorization: Bearer kag_…" https://gestionale.example/admin/gestionale/2/fatture
curl -X POST -H "Authorization: Bearer kag_…" -H "Content-Type: application/json" \
     -d '{"subject":"Perdita in cantina", …}' https://gestionale.example/admin/segnalazioni
```

Valgono i middleware delle rotte, i FormRequest e le policy, come per una persona: il middleware `AutenticaAgente` mette l'utente della chiave nella guardia `web`, usa una sessione in memoria (niente righe in `sessions`, niente cookie) e risponde con una busta JSON:

| Campo | Contenuto |
|---|---|
| `ok`, `stato` | esito e codice HTTP |
| `tipo` | `pagina` (dati di una pagina), `esito` (dopo una scrittura), `dati` (JSON del gestionale), `errore` |
| `dati` | le props della pagina, senza i dati condivisi del layout |
| `avvisi` | i messaggi che la persona vedrebbe («Incasso registrato con successo») |
| `errori` | gli errori dei moduli, campo per campo |
| `rimanda_a` | dove il gestionale porterebbe la persona dopo la scrittura |

I file (PDF, XML, Excel) passano come sono.

### Anteprima

Con l'intestazione `X-Kondo-Prova: 1` una scrittura viene eseguita dentro una transazione che poi si annulla, con posta, notifiche e code finte. La risposta dice se andrebbe a buon fine e con quali avvisi o errori; nel database non resta niente. Anche i file veri non si toccano: per la durata dell'anteprima i dischi non cancellano, non spostano e non sovrascrivono (`App\Support\Agenti\DiscoInProva`), perché il rollback del database non riporta indietro un file cancellato. I file nuovi caricati durante un'anteprima possono restare nello storage.

### Catalogo

`GET /agenti/catalogo` elenca tutte le funzioni che la chiave può usare: nome della rotta, metodi, percorso, parametri, se scrive, se restituisce un file, i permessi richiesti e, se il controller usa un FormRequest, i campi del modulo con le loro regole. Con `?rotta=nome&parametri[condominio]=2` restituisce i campi di una rotta con i vincoli del condominio vero.

## Sistemi esterni collegati (segnalazioni)

Un'app o un programma fuori dal gestionale, per esempio l'app dei condòmini, può tenere le sue segnalazioni in pari con quelle di KondoManager, nei due sensi e senza cron. Usa una chiave «Consultare e modificare», meglio se di un utente tecnico dedicato.

- `GET /agenti/esterni/condomini`: i condomini (id, nome, codice fiscale, indirizzo), per dire a quale corrisponde ciascuno di quelli del sistema esterno.
- `PUT /agenti/esterni/{sorgente}/segnalazioni/{riferimento}`: il sistema esterno manda lo stato INTERO della sua segnalazione (titolo, testo, persona, stato, urgenza, tutte le risposte) e riceve quello del gestionale (id, stato, urgenza, i commenti scritti qui). La chiamata si può ripetere quante volte si vuole: niente doppioni, ogni oggetto è legato dal suo riferimento (tabella `collegamenti_esterni`).
- Stato: `aperta`, `in_lavorazione`, `chiusa`. Urgenza: sì/no (alta e urgente = sì). Con `stato_base` e `urgente_base` il sistema esterno dice quale valore aveva all'ultima sincronizzazione: se è cambiato là si applica qui, altrimenti vale quello del gestionale.
- La segnalazione nasce non pubblicata (non compare nel portale condòmini di KondoManager), con la persona agganciata all'anagrafica del condominio quando si trova per email o per nome.
- **Inbox**: ogni segnalazione aperta ha il suo compito in «Ticket & manutenzione» (prima il riquadro restava sempre a zero); si chiude da solo quando la segnalazione è chiusa. Una risposta del condòmino entra come «Nuovo commento».
- **Avviso in uscita**: con `KONDO_WEBHOOK_URL` e `KONDO_WEBHOOK_SEGRETO`, quando una segnalazione collegata cambia qui (stato, priorità, testo, commento, cancellazione) parte dalla coda un JSON firmato (`X-Kondo-Firma: sha256=HMAC del corpo`): `{evento, sorgente, riferimento, id}`. Per `segnalazione.cambiata` il sistema esterno risponde rifacendo la chiamata qui sopra. Riprova da sola per circa un'ora.
- Una segnalazione eliminata qui non viene ricreata: la chiamata risponde `scollegata`.
- **Segnalazioni nate qui**: una segnalazione creata nel gestionale parte verso il sistema esterno con i suoi dati (evento `segnalazione.nata`); quello la crea da sé e la «adotta» con la stessa chiamata, mandando `kondo_id`. Il testo scritto qui non viene toccato.
- **Bacheca**: una comunicazione creata, cambiata o tolta qui parte con i suoi dati (`comunicazione.cambiata`, `comunicazione.eliminata`; `pubblicata` è vero solo se è pubblicata, approvata, non privata e senza destinatari scelti uno per uno). Nell'altro senso, `PUT /agenti/esterni/{sorgente}/comunicazioni/{riferimento}` porta qui un avviso scritto nel sistema esterno (titolo, testo, importante, condomini, eliminata), senza far partire le notifiche del gestionale.
- **Rubrica**: `GET /agenti/esterni/condomini/{condominio}/persone` elenca le anagrafiche del condominio con contatti e unità. Quando un'anagrafica cambia parte un solo avviso `rubrica.cambiata`, mezzo minuto dopo, anche se ne cambiano duecento insieme.

Codice: `App\Services\Agenti\SegnalazioniEsterne`, `App\Observers\Agenti\SegnalazioneCollegataObserver`, `App\Jobs\Agenti\AvvisaSistemaEsterno`, `App\Models\CollegamentoEsterno`.

## Registrazioni delle riunioni

Le riunioni registrate dal browser (Gestionale → Registrazioni, dal 17/11/2026). Un agente legge l'elenco (`admin.gestionale.registrazioni.index`) e il dettaglio (`admin.gestionale.registrazioni.show`: `trascrizione` con le voci «[mm:ss] Persona N: …», `riassunto`, `bozza_punti` = `[{numero, discussione, delibera}]` con `delibera` nulla quando una decisione chiara non è stata detta, `trascrizione_live`, `stato`, `errore`). Collega o scollega un'assemblea con `PUT …/registrazioni/{id}/assemblea` (`assemblea_id`, vuoto per scollegare): se la registrazione è pronta e l'assemblea ha l'ordine del giorno, la bozza per punti si prepara da sola. Può chiudere una registrazione rimasta aperta, o riprovarne una in errore, con `POST …/registrazioni/{id}/termina`. Avviare una registrazione e mandare l'audio restano cose da browser (microfono e conferma dell'avviso ai presenti): sono vietate agli agenti.

## Convocazione, foglio delle firme e invii dell'assemblea

Dal 17/11/2026 (Gestionale → Assemblee → un'assemblea → «Convocazione»). L'elenco delle funzioni con i campi arriva anche nei dati dell'assemblea (`admin.gestionale.assemblee.dati`, chiave `funzioni`) e nella pagina della convocazione (`per_gli_agenti`).

- **Leggere**: `admin.gestionale.assemblee.convocazione.show` dà il testo dell'avviso, quello che il modello darebbe adesso (`proposta`), gli `avvisi` (fra cui `cinque_giorni`: meno di cinque giorni fra l'avviso e la prima convocazione, art. 66 disp. att. c.c.), i segnaposto e l'anteprima degli invii (`invio`).
- **Scrivere il testo**: `…convocazione.genera` (POST: `modello_id`, `sovrascrivi`) lo genera dal modello dello studio; `…convocazione.update` (PUT: `testo`, `collegamento_videoconferenza`) lo corregge. I segnaposto `{{destinatario.…}}` si lasciano nel testo: li riempie la stampa per persona. I modelli stanno in `…assemblee.modelli.*`, con `tipo` = `verbale`, `convocazione`, `email_convocazione`, `trasmissione_verbale`.
- **I PDF** (file): `…convocazione.stampa` (l'avviso generico; `?persona=ID` per quello di una persona), `…convocazione.per-persona.stampa`, `…convocazione.zip`, `…foglio-firme.stampa`, `…verbale.trasmissione.stampa` (la lettera di trasmissione del verbale, `?persone[]=ID`), `…invii.pdf` (l'avviso com'è partito per email). Nessuna stampa scrive niente.
- **Lo stato degli invii**: `…invii.anteprima` (GET, `cosa` = `convocazione` o `verbale`; `?chi=assenti|tutti` per il verbale; `?indirizzi[]=email|email_secondaria|pec`): per ogni persona gli indirizzi, se è proposta, `mandata` / `in_coda` / `non_partita`, quando e come, e le righe del registro (`invii`).
- **Mandare davvero** (`…invii.invia`, POST): è una scrittura verso l'esterno, le email partono. La richiesta porta sempre `conferma: true` e `persone` (gli `id` dell'anteprima); senza, risponde 422 e non parte niente. Facoltativi `indirizzi`, `oggetto`, `testo`, `anche_gia_mandate` (chi l'ha già ricevuta per email con lo stesso testo viene saltato, se non lo si chiede). Il verbale si manda solo se è approvato. Con `X-Kondo-Prova: 1` la risposta dice che cosa partirebbe e non parte niente: nessuna email, nessuna riga, nessun PDF. Un agente manda solo se la persona per cui lavora glielo ha chiesto.
- **Registrare una spedizione** fatta fuori dal programma: `…invii.spedizione` (POST: `persone`, `canale` = `raccomandata`, `pec`, `a_mano`, `posta`, `altro`; `data`; `numeri` per `id` della persona; `nota`). Non manda niente. `…invii.annulla` (DELETE) annulla una spedizione registrata per sbaglio.
- Quando la convocazione parte, `data_avviso` si riempie (se era vuota) e l'assemblea passa a `convocata`. Approvare il verbale resta di una persona.

Codice: `App\Services\Assemblee\ConvocazioneService`, `InviiAssemblea`, `DestinatariAssemblea`, `SegnapostiConvocazione`; le righe stanno in `registro_invii` con i tipi `convocazione_assemblea` e `verbale_assemblea`.

## Cosa un agente non può fare

Qualunque sia il suo utente: accesso e password, 2FA, profilo, utenti, ruoli e permessi, impostazioni (chiavi comprese), backup e ripristino, aggiornamenti, installazione, log, cancellazione di un condominio (con il tasto normale) o di un esercizio, avvio di una registrazione e invio dell'audio, e ogni rotta che chiede di ridigitare la password. L'elenco è in `AutenticaAgente::VIETATE`; le funzioni che chiedono un permesso in più sulla chiave sono in `AutenticaAgente::CON_PERMESSO`.

## Configurazione

| Variabile | Serve a |
|---|---|
| `KONDO_MCP_URL` | indirizzo del server MCP collegato: la pagina Agenti AI mostra come collegare un assistente |
| `KONDO_AGENTI_RICHIESTE_MINUTO` | limite di richieste al minuto per chiave (predefinito 240) |
| `KONDO_WEBHOOK_URL`, `KONDO_WEBHOOK_SEGRETO` | avviso in uscita al sistema esterno collegato (vedi sopra) |

## File

- `app/Http/Middleware/AutenticaAgente.php`: riconoscimento della chiave, busta, anteprima, registro, divieti.
- `app/Http/Controllers/Agenti/CatalogoAgentiController.php`: il catalogo.
- `app/Http/Controllers/Impostazioni/ImpostazioniAgentiController.php` e `resources/js/pages/impostazioni/impostazioniAgenti.vue`: la pagina.
- `app/Models/ChiaveAgente.php`, `app/Models/AzioneAgente.php`, migrazioni `2026_11_09_100000_create_agenti_tables.php` e `2026_11_15_100000_agenti_permesso_eliminare_e_motivo.php`.
- `app/Services/Condomini/EliminaCondominio.php`, `app/Http/Requests/Condominio/EliminaCondominioRequest.php`: eliminare un condominio con tutto il suo contenuto.
