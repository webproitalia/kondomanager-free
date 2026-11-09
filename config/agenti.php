<?php

/*
| Agenti AI (Web Pro Italia, 09/11/2026). Vedi App\Http\Middleware\AutenticaAgente.
| `mcp_url`: l'indirizzo del server MCP che parla con questa installazione (di solito https://<dominio>/mcp),
| mostrato in Impostazioni → Agenti AI. Vuoto = MCP non ancora collegato: la pagina lo dice.
*/
return [
    'mcp_url' => env('KONDO_MCP_URL'),
    'richieste_al_minuto' => (int) env('KONDO_AGENTI_RICHIESTE_MINUTO', 240),
];
