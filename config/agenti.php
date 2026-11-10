<?php

/*
| Agenti AI (Web Pro Italia, 09/11/2026). Vedi App\Http\Middleware\AutenticaAgente.
| `mcp_url`: l'indirizzo del server MCP che parla con questa installazione (di solito https://<dominio>/mcp),
| mostrato in Impostazioni → Agenti AI. Vuoto = MCP non ancora collegato: la pagina lo dice.
*/
return [
    'mcp_url' => env('KONDO_MCP_URL'),
    'richieste_al_minuto' => (int) env('KONDO_AGENTI_RICHIESTE_MINUTO', 240),

    /*
    | Avviso in uscita verso un sistema esterno collegato (10/11/2026), per esempio l'app dei condòmini.
    | Quando una segnalazione cambia qui (stato, priorità, un commento nuovo, cancellazione), il gestionale manda un
    | piccolo JSON firmato a questo indirizzo: «la segnalazione N è cambiata». Il sistema esterno risponde
    | risincronizzandola. Vuoto = nessun avviso. La firma è HMAC-SHA256 del corpo con `webhook_segreto`,
    | nell'intestazione X-Kondo-Firma.
    */
    'webhook_url' => env('KONDO_WEBHOOK_URL'),
    'webhook_segreto' => env('KONDO_WEBHOOK_SEGRETO'),
];
