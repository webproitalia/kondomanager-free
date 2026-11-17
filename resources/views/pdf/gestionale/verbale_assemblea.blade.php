@extends('pdf.base')

{{--
  IL VERBALE DELL'ASSEMBLEA (Web Pro Italia, 17/11/2026). Stampa il testo del verbale così com'è, generato da un modello dello studio e poi
  corretto a mano o da un assistente, con il markup della nota del fascicolo (`TestoNota`: «# » titolo, `**…**` grassetto, «- » elenco, una riga
  vuota fra i paragrafi) e le righe di un paragrafo una sotto l'altra. Tutto quello che il testo contiene è mostrato com'è, mai eseguito.
  La testata e la carta sono quelle dello studio per il gruppo «Rendiconto e assemblea». In bozza: la filigrana (la mette la configurazione di
  mPDF) e una riga in testa che lo dice.
--}}
@php
    $coloreTitoli = \App\Services\PDF\Carta::coloreDi($carta ?? null, '#1e3a5f');
@endphp

@section('title', 'Verbale dell\'assemblea — ' . ($condominio->nome ?? ''))

@section('content')
<style>
    .verbale { font-size: 10pt; line-height: 1.5; color: #1a1a1a; }
    .verbale h3 { font-size: 11.5pt; color: {{ $coloreTitoli }}; margin: 14px 0 6px 0; }
    .verbale p { margin: 0 0 8px 0; text-align: justify; }
    .verbale ul, .verbale ol { margin: 0 0 8px 0; padding-left: 18px; }
    .verbale li { margin-bottom: 2px; }
</style>

@if(!empty($bozza))
    <table style="width: 100%; border-collapse: collapse; margin-bottom: 10px;">
        <tr>
            <td style="padding: 5px 10px; border: 1.5px dashed #9c4221; color: #9c4221; font-size: 9.5pt; font-weight: bold; text-align: center;">
                BOZZA — verbale non ancora approvato{{ ($verbale->versione ?? 1) > 1 ? ' (versione ' . $verbale->versione . ')' : '' }}
            </td>
        </tr>
    </table>
@endif

<div class="verbale">
    {!! \App\Services\Fascicolo\TestoNota::html((string) $testo, true) !!}
</div>
@endsection
