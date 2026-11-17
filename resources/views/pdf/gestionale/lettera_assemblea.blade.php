@extends('pdf.base')

{{--
  LE LETTERE DELL'ASSEMBLEA (Web Pro Italia, 17/11/2026): l'avviso di convocazione (generico, o uno per persona) e la lettera di trasmissione del
  verbale. Ogni voce di `$fogli` comincia su una pagina nuova, con le sue pagine numerate da 1: il PDF «per persona» si stampa in un colpo e si
  imbusta un foglio alla volta.

  L'impaginazione è quella delle lettere ai condòmini: in alto a sinistra lo studio che scrive (se le impostazioni di stampa lo dicono), con il
  logo e i suoi dati, e sotto il condominio; a destra il destinatario, alla distanza che va in una busta con la finestra. Senza destinatario
  (l'avviso generico) la colonna di destra resta vuota. Con la carta intestata lo studio è sulla carta: a sinistra «Amministratore del Condominio …».
  Il layout non scrive la sua intestazione: lo fa questa vista, per ogni foglio.

  Il testo è quello scritto dallo studio, mostrato com'è e mai eseguito, con il markup della nota del fascicolo (`TestoNota`: «# » titolo,
  `**…**` grassetto, «- » o «1. » elenco, una riga vuota fra i paragrafi) e le righe di un paragrafo una sotto l'altra. `parti`: il testo
  spezzato dove chi scrive ha messo una riga di soli trattini (`---`); fra una parte e l'altra una linea tratteggiata (il modulo di delega, da
  ritagliare). La firma dell'amministratore (l'immagine delle impostazioni di stampa) sta alla fine della prima parte, non in fondo al foglio:
  dopo c'è il modulo che firma il condòmino.

  `luogo_data`, `oggetto`, `saluto` e `chiusura` (la lettera di trasmissione): il luogo e la data sotto la testata, l'oggetto in grassetto sopra
  il testo, il saluto prima e la chiusura dopo. L'avviso di convocazione il luogo e la data li porta nel suo testo.
--}}
@php
    $firmaLettera = $firma_stampe_absolute_path ?? null;
    $firma_stampe_absolute_path = null;
    $intestazione_propria = true;
    $piede_senza_data = true;
    $carta ??= app(\App\Services\PDF\CartaDiStampa::class)->perGruppo(\App\Services\PDF\GruppoCarta::RENDICONTO_E_ASSEMBLEA);
    $coloreTitoli = \App\Services\PDF\Carta::coloreDi($carta, '#1e3a5f');
    $condominioVia = trim((string) ($condominio->indirizzo ?? ''));
    $condominioCitta = trim(implode(' ', array_filter([$condominio->cap ?? null, $condominio->comune ?? null, filled($condominio->provincia ?? null) ? '(' . $condominio->provincia . ')' : null], 'filled')));
@endphp

@section('title', ($titolo ?? 'Assemblea') . ' — ' . ($condominio->nome ?? ''))

@section('content')
<style>
    .testo-assemblea { font-size: 10pt; line-height: 1.5; color: #1a1a1a; }
    .testo-assemblea h3 { font-size: 11.5pt; color: {{ $coloreTitoli }}; margin: 12px 0 6px 0; }
    .testo-assemblea p { margin: 0 0 8px 0; text-align: justify; }
    .testo-assemblea ul, .testo-assemblea ol { margin: 0 0 8px 0; padding-left: 18px; }
    .testo-assemblea li { margin-bottom: 2px; }
</style>

@foreach($fogli as $i => $f)
    @if($i > 0)
        <pagebreak resetpagenum="1" />
    @endif

    {{-- `overflow: wrap`: una parola più larga della colonna (una PEC, un sito) va a capo dove la colonna finisce, e la colonna non si allarga. --}}
    <table style="width: 100%; border-collapse: collapse; margin-bottom: 16px; overflow: wrap;">
        <tr>
            <td style="width: {{ \App\Services\PDF\Carta::larghezzaMittente($carta) }}; padding: 0 {{ \App\Services\PDF\Carta::SPAZIO_PRIMA_DEL_DESTINATARIO }}mm 0 0; vertical-align: top;">
                @if($carta->haStudio() || $carta->cartaIntestata() !== null)
                    @if($carta->haStudio())
                        @include('pdf._carta.mittente')
                    @endif
                    <div style="font-size: 9pt; color: #333; line-height: 1.4;">
                        Amministratore del <strong>{{ \App\Services\Gestionale\Lettere\TestiLettere::condominioConNome((string) $condominio->nome) }}</strong>
                        @if($condominioVia !== '')<br>{{ $condominioVia }}@endif
                        @if($condominioCitta !== '')<br>{{ $condominioCitta }}@endif
                        @if(filled($condominio->codice_fiscale ?? null))<br>C.F. {{ $condominio->codice_fiscale }}@endif
                    </div>
                @else
                    <div style="font-size: 11pt; font-weight: bold; color: {{ $coloreTitoli }}; line-height: 1.25;">{{ $condominio->nome }}</div>
                    <div style="font-size: 9pt; color: #333; margin-top: 2px; line-height: 1.45;">
                        @if($condominioVia !== ''){{ $condominioVia }}<br>@endif
                        @if($condominioCitta !== ''){{ $condominioCitta }}<br>@endif
                        @if(filled($condominio->codice_fiscale ?? null))C.F. {{ $condominio->codice_fiscale }}@endif
                    </div>
                @endif
            </td>
            <td style="padding: 0; vertical-align: top; text-align: right;">
                @if(!empty($f['destinatario']))
                    <div style="font-size: 11pt; font-weight: bold; color: #222; line-height: 1.3;">{{ $f['destinatario']['nome'] ?? '' }}</div>
                    @if(filled($f['destinatario']['indirizzo'] ?? null))
                        <div style="font-size: 9.5pt; color: #333; margin-top: 2px; line-height: 1.4;">{{ $f['destinatario']['indirizzo'] }}</div>
                    @endif
                @endif
            </td>
        </tr>
    </table>

    @if(filled($f['luogo_data'] ?? null))
        <div style="font-size: 9pt; color: #444; margin-bottom: 8px;">{{ $f['luogo_data'] }}</div>
    @endif
    @if(filled($f['oggetto'] ?? null))
        <div style="font-size: 10pt; font-weight: bold; margin-bottom: 10px;">Oggetto: {{ $f['oggetto'] }}</div>
    @endif
    @if(filled($f['saluto'] ?? null))
        <div style="font-size: 10pt; margin-bottom: 8px;">{{ $f['saluto'] }}</div>
    @endif

    @foreach($f['parti'] as $n => $parte)
        @if($n > 0)
            {{-- Il taglio: quello che segue si stacca (il modulo di delega). In una tabella, perché mPDF non tenga insieme la linea e il blocco di prima. --}}
            <table style="width: 100%; border-collapse: collapse; margin: 14px 0 10px 0;">
                <tr><td style="border-top: 1px dashed #777; font-size: 7pt; color: #777; padding: 2px 0 0 0;">da ritagliare e consegnare</td></tr>
            </table>
        @endif
        <div class="testo-assemblea" @if($n > 0) style="page-break-inside: avoid;" @endif>
            {!! \App\Services\Fascicolo\TestoNota::html((string) $parte, true) !!}
        </div>
        @if($n === 0)
            @if(filled($f['chiusura'] ?? null))
                <div style="font-size: 10pt; margin-top: 4px;">{{ $f['chiusura'] }}</div>
            @endif
            @if(!empty($firmaLettera))
                <div style="margin-top: 4px; page-break-inside: avoid;">
                    <img src="{{ $firmaLettera }}" style="max-width: 180px; max-height: 70px;">
                </div>
            @endif
        @endif
    @endforeach
@endforeach
@endsection
