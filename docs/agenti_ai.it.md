# Agenti AI: usare KondoManager con una chiave

Questa versione (fork di Web Pro Italia) permette a un agente AI (Claude, un server MCP, uno script) di usare il gestionale al posto di una persona, con una chiave personale.

## Le chiavi

Impostazioni → **Agenti AI**. Una chiave:

- appartiene a un utente amministratore o collaboratore, e ne eredita ruoli e permessi;
- può essere di **sola consultazione** o anche di **modifica**;
- può scadere dopo 30, 90 o 365 giorni, e si revoca con un clic;
- si mostra una volta sola, alla creazione: nel database resta solo la sua impronta (sha256).

La stessa pagina mostra il **registro delle modifiche** fatte dagli agenti: quando, con quale chiave, quale funzione, con che esito. I dati inviati non si registrano.

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

Con l'intestazione `X-Kondo-Prova: 1` una scrittura viene eseguita dentro una transazione che poi si annulla, con posta, notifiche e code finte. La risposta dice se andrebbe a buon fine e con quali avvisi o errori; nel database non resta niente. I file caricati durante un'anteprima possono restare nello storage.

### Catalogo

`GET /agenti/catalogo` elenca tutte le funzioni che la chiave può usare: nome della rotta, metodi, percorso, parametri, se scrive, se restituisce un file, i permessi richiesti e, se il controller usa un FormRequest, i campi del modulo con le loro regole. Con `?rotta=nome&parametri[condominio]=2` restituisce i campi di una rotta con i vincoli del condominio vero.

## Cosa un agente non può fare

Qualunque sia il suo utente: accesso e password, 2FA, profilo, utenti, ruoli e permessi, impostazioni (chiavi comprese), backup e ripristino, aggiornamenti, installazione, log, cancellazione di un condominio o di un esercizio, e ogni rotta che chiede di ridigitare la password. L'elenco è in `AutenticaAgente::VIETATE`.

## Configurazione

| Variabile | Serve a |
|---|---|
| `KONDO_MCP_URL` | indirizzo del server MCP collegato: la pagina Agenti AI mostra come collegare un assistente |
| `KONDO_AGENTI_RICHIESTE_MINUTO` | limite di richieste al minuto per chiave (predefinito 240) |

## File

- `app/Http/Middleware/AutenticaAgente.php`: riconoscimento della chiave, busta, anteprima, registro, divieti.
- `app/Http/Controllers/Agenti/CatalogoAgentiController.php`: il catalogo.
- `app/Http/Controllers/Impostazioni/ImpostazioniAgentiController.php` e `resources/js/pages/impostazioni/impostazioniAgenti.vue`: la pagina.
- `app/Models/ChiaveAgente.php`, `app/Models/AzioneAgente.php`, migrazione `2026_11_09_100000_create_agenti_tables.php`.
