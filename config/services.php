<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    /*
    | Registrazioni delle riunioni (Web Pro Italia, 17/11/2026): Deepgram trascrive l'audio (il testo in diretta e la passata finale con le
    | voci), Gemini scrive il riassunto e la bozza per punto dell'ordine del giorno. Senza la chiave di Deepgram la pagina «Registrazioni» non
    | mostra il tasto per registrare e lo dice; senza quella di Gemini la trascrizione esce lo stesso, senza riassunto. Vedi config/registrazioni.php.
    */
    'deepgram' => [
        'key' => env('KONDO_DEEPGRAM_KEY'),
        'model' => env('KONDO_DEEPGRAM_MODEL', 'nova-3'),
        // L'indirizzo dell'API: per tenere l'audio in Europa, quello UE di Deepgram (https://api.eu.deepgram.com/v1/listen).
        'url' => env('KONDO_DEEPGRAM_URL', 'https://api.deepgram.com/v1/listen'),
    ],

    'gemini' => [
        'key' => env('KONDO_GEMINI_KEY'),
        'model' => env('KONDO_GEMINI_MODEL', 'gemini-2.5-flash'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
