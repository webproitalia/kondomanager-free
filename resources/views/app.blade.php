<!DOCTYPE html>
{{-- data-km-menu e data-km-schede: dove sta il menu e dove si aprono le schede, scelti in Impostazioni → Aspetto (Web Pro Italia): li legge resources/js/composables/useImpalcatura.ts.
     data-km-stile: lo stile dell'area di lavoro, «professionale» o «morbido»: lo legge resources/css/stile-professionale.css --}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-km-menu="{{ \App\Support\Tema::menu() }}" data-km-schede="{{ \App\Support\Tema::schede() }}" data-km-stile="{{ \App\Support\Tema::stile() }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
         <meta name="csrf-token" content="{{ csrf_token() }}">

        <title data-inertia>{{ config('app.name', 'Laravel') }}</title>

        <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">
        <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
        <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">

        @routes
        @vite(['resources/js/app.ts'])
        {{-- Aspetto scelto in Impostazioni → Aspetto (Web Pro Italia): ridefinisce solo le variabili dei colori --}}
        <style id="km-aspetto">{!! \App\Support\Tema::css() !!}</style>
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
