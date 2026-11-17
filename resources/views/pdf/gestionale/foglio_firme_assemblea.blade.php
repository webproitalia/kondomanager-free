@extends('pdf.base')

{{--
  IL FOGLIO DELLE FIRME DELL'ASSEMBLEA (Web Pro Italia, 17/11/2026), da stampare e portare in sala: una riga per avente diritto — il nome (con
  i contitolari per cui vale), le unità, i millesimi su 1.000 — e lo spazio per la firma di chi è presente, per il nome del delegato e per la
  sua firma. In fondo i totali (teste e millesimi) e lo spazio per presidente e segretario. Orizzontale: le firme vogliono spazio. La testata e
  la carta sono quelle dello studio per il gruppo «Rendiconto e assemblea».
--}}
@php
    $coloreTitoli = \App\Services\PDF\Carta::coloreDi($carta ?? null, '#1e3a5f');
    $piede_compatto = true;
@endphp

@section('title', 'Foglio delle firme — ' . ($condominio->nome ?? ''))

@section('content')
<div style="border-bottom: 2px solid {{ $coloreTitoli }}; padding-bottom: 5px; margin-bottom: 8px;">
    <h2 style="margin: 0; font-size: 13pt; color: {{ $coloreTitoli }};">Foglio delle firme — assemblea {{ $assemblea->tipo === 'straordinaria' ? 'straordinaria' : 'ordinaria' }}</h2>
    <div style="font-size: 9pt; color: #333; margin-top: 3px;">
        <strong>{{ \App\Services\Gestionale\Lettere\TestiLettere::condominioConNome((string) $condominio->nome) }}</strong> — {{ $assemblea->titolo }}
    </div>
    <div style="font-size: 9pt; color: #333; margin-top: 2px;">
        @if($prima)Prima convocazione: {{ $prima }}@endif
        @if($prima && $seconda) · @endif
        @if($seconda)Seconda convocazione: {{ $seconda }}@endif
        @if($dove) · {{ $dove }}@endif
    </div>
    <div style="font-size: 9pt; color: #333; margin-top: 4px;">
        Convocazione tenuta: &nbsp; [&nbsp;&nbsp;] prima &nbsp;&nbsp; [&nbsp;&nbsp;] seconda &nbsp;&nbsp;&nbsp; Data: ____ / ____ / ________ &nbsp;&nbsp;&nbsp; Ora di inizio: ____ : ____
    </div>
</div>

<table style="width: 100%; border-collapse: collapse; font-size: 9pt;">
    <thead>
        <tr style="background: #eef2f7; color: #1e3a5f;">
            <th style="text-align: right; padding: 4px; border: 1px solid #c0ccd8; width: 4%;">N.</th>
            <th style="text-align: left; padding: 4px; border: 1px solid #c0ccd8; width: 24%;">Condòmino</th>
            <th style="text-align: left; padding: 4px; border: 1px solid #c0ccd8; width: 13%;">Unità</th>
            <th style="text-align: right; padding: 4px; border: 1px solid #c0ccd8; width: 8%;">Millesimi</th>
            <th style="text-align: left; padding: 4px; border: 1px solid #c0ccd8; width: 18%;">Firma (presente)</th>
            <th style="text-align: left; padding: 4px; border: 1px solid #c0ccd8; width: 15%;">Delegato a</th>
            <th style="text-align: left; padding: 4px; border: 1px solid #c0ccd8; width: 18%;">Firma del delegato</th>
        </tr>
    </thead>
    <tbody>
        @forelse($righe as $n => $r)
            <tr>
                <td style="padding: 4px; border: 1px solid #c0ccd8; text-align: right; height: 11mm; vertical-align: middle;">{{ $n + 1 }}</td>
                <td style="padding: 4px; border: 1px solid #c0ccd8; vertical-align: middle;">
                    <strong>{{ $r['nome'] }}</strong>
                    @if($r['contitolari'] !== [])
                        <br><span style="font-size: 7.5pt; color: #555;">anche per {{ implode(', ', $r['contitolari']) }}</span>
                    @endif
                </td>
                <td style="padding: 4px; border: 1px solid #c0ccd8; vertical-align: middle;">{{ $r['unita'] }}</td>
                <td style="padding: 4px; border: 1px solid #c0ccd8; text-align: right; vertical-align: middle; white-space: nowrap;">{{ $r['millesimi'] }}</td>
                <td style="padding: 4px; border: 1px solid #c0ccd8;"></td>
                <td style="padding: 4px; border: 1px solid #c0ccd8; vertical-align: middle; font-size: 8.5pt;">{{ $r['delegato'] }}</td>
                <td style="padding: 4px; border: 1px solid #c0ccd8;"></td>
            </tr>
        @empty
            <tr><td colspan="7" style="padding: 8px; border: 1px solid #c0ccd8; text-align: center; color: #555;">Nessun avente diritto: controlla i titolari delle unità del condominio.</td></tr>
        @endforelse
    </tbody>
</table>

<table style="width: 100%; border-collapse: collapse; font-size: 9.5pt; margin-top: 8px; page-break-inside: avoid;">
    <tr>
        <td style="padding: 5px 8px; border: 1px solid #c0ccd8; background: #f9fafb; width: 50%;">
            <strong>Aventi diritto:</strong> {{ $teste }} {{ $teste === 1 ? 'condòmino' : 'condòmini' }} per <strong>{{ $millesimi_totali }}</strong> millesimi su 1.000
            @if(!empty($assemblea->tabella?->nome)) (tabella «{{ $assemblea->tabella->nome }}»)@endif
        </td>
        <td style="padding: 5px 8px; border: 1px solid #c0ccd8; width: 50%;">
            <strong>Presenti</strong> (di persona e per delega): n. ________ &nbsp; per millesimi ________________
        </td>
    </tr>
    @if(!empty($unita_senza_titolari))
        <tr>
            <td colspan="2" style="padding: 4px 8px; border: 1px solid #c0ccd8; font-size: 8pt; color: #555;">
                Unità senza un titolare in archivio (non hanno una riga, i loro millesimi restano nel valore dell'edificio):
                {{ implode(', ', array_map(fn ($u) => $u['etichetta'], $unita_senza_titolari)) }}
            </td>
        </tr>
    @endif
</table>

<table style="width: 100%; border-collapse: collapse; font-size: 9.5pt; margin-top: 16px; page-break-inside: avoid;">
    <tr>
        <td style="width: 50%; padding: 0 20px 0 0;">Il presidente<br><br><br>______________________________________</td>
        <td style="width: 50%; padding: 0 0 0 20px;">Il segretario<br><br><br>______________________________________</td>
    </tr>
</table>
@endsection
