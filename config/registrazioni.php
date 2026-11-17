<?php

/*
| Registrazioni delle riunioni del condominio (Web Pro Italia, 17/11/2026). Le chiavi di Deepgram e di Gemini stanno in config/services.php.
|
| `giorni_audio`: dopo quanti giorni dalla fine della registrazione l'audio si cancella da solo (comando `registrazioni:pulisci-audio`, ogni
| notte). La trascrizione e il riassunto restano finché la persona non elimina la registrazione. Predefinito 30.
| `secondi_segmento`: ogni quanti secondi il browser chiude un pezzo di audio e lo manda. Ogni pezzo è un file intero: quello che è salito
| resta anche se il browser si chiude.
| `timeout_elaborazione`: i secondi che l'elaborazione finale (unione, voci, riassunto) può durare. Deve stare sotto `retry_after` della coda
| (config/queue.php), altrimenti la coda la crederebbe abbandonata e la farebbe partire una seconda volta.
*/
return [
    'giorni_audio' => (int) env('KONDO_REGISTRAZIONI_GIORNI_AUDIO', 30),
    'secondi_segmento' => (int) env('KONDO_REGISTRAZIONI_SECONDI_SEGMENTO', 25),
    'timeout_elaborazione' => 1800,
];
