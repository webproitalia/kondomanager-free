{{--
  IL FASCICOLO DI BILANCIO (Web Pro Italia, 13/11/2026) — un PDF solo: copertina, nota sintetica esplicativa con il
  sommario, rendiconto delle spese, situazione dei condòmini, rendiconto di cassa e stato patrimoniale, fatture da
  pagare, registro di contabilità, preventivo e rate. I dati arrivano da `FascicoloBilancioService::costruisci()`,
  tutti in centesimi; qui si impagina soltanto.

  Note per chi tocca questo file (mPDF, non un browser):
  - i blocchi a tutta pagina della copertina sono `position: absolute` e figli diretti di <body>: è l'unico modo in cui
    mPDF li posiziona rispetto al foglio (`fixed` parte dai margini e lascia una cornice bianca);
  - il salto pagina è `<pagebreak />`, non la proprietà CSS (su una tabella mPDF la ignora);
  - il corpo del carattere va scritto sulla <table>: mPDF non lo eredita dal blocco che la contiene;
  - ogni sezione comincia con un <bookmark>: è da lì che il controller legge a che pagina è finita, per il sommario.
--}}
@php
    $c = $colori;
    $eur = fn (?int $cent) => number_format(($cent ?? 0) / 100, 2, ',', '.');
    $eurVuoto = fn (?int $cent) => $cent === null || $cent === 0 ? '' : number_format($cent / 100, 2, ',', '.');
    $conSegno = fn (?int $cent) => ($cent ?? 0) < 0 ? '– '.number_format(abs($cent) / 100, 2, ',', '.') : number_format(($cent ?? 0) / 100, 2, ',', '.');
    $chiavi = array_column($sezioni, 'chiave');
    $titoloDi = array_column($sezioni, 'titolo', 'chiave');
    $ha = fn (string $k) => in_array($k, $chiavi, true);
    // Le sezioni che cominciano su una pagina nuova; le altre proseguono sotto quella prima.
    $saltoPrima = \App\Http\Controllers\Gestionale\Fascicolo\FascicoloBilancioController::SALTO_PRIMA;
    $prima = true;
    $apri = function (string $k) use (&$prima, $saltoPrima) {
        $salto = ! $prima && in_array($k, $saltoPrima, true);
        $prima = false;

        return $salto;
    };
    $nomeCondominio = preg_replace('/^condominio\s+/iu', '', $condominio['nome']);
@endphp
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>{{ $piede }}</title>
    <style>
        body { font-family: inter, dejavusans, sans-serif; font-size: 9.6pt; color: #1f2933; }
        .titolo { background: {{ $c['titolo'] }}; color: #ffffff; font-weight: bold; font-size: 13.5pt; padding: 8px 11px 7px 11px; }
        .sotto { font-size: 10.5pt; font-weight: bold; }
        .spazio { height: 12px; }
        table.t { width: 100%; border-collapse: collapse; font-size: 8.8pt; margin-top: 7px; }
        table.t th { background: {{ $c['titolo'] }}; color: #ffffff; text-align: left; padding: 4px 6px; border: 0.25mm solid {{ $c['bordo'] }}; font-size: 8.3pt; }
        table.t td { padding: 4px 6px; border: 0.25mm solid {{ $c['bordo'] }}; vertical-align: top; }
        table.t tr.z td { background: {{ $c['tenue'] }}; }
        table.t td.n, table.t th.n { text-align: right; }
        table.t tr.tot td { background: {{ $c['totale'] }}; font-weight: bold; }
        table.t tr.cap td { font-weight: bold; }
        table.t tr.sub td { font-weight: bold; background: {{ $c['tenue'] }}; }
        table.stretta { font-size: 7.4pt; }
        table.stretta th { font-size: 6.9pt; padding: 3px 4px; }
        table.stretta td { padding: 3px 4px; }
        h3 { color: {{ $c['scuro'] }}; font-size: 11.5pt; margin: 14px 0 5px 0; }
        p { margin: 0 0 6px 0; text-align: justify; line-height: 1.45; }
        ul, ol { margin: 0 0 6px 0; }
        li { margin-bottom: 3px; line-height: 1.4; }
        .piccolo { font-size: 8pt; color: #5b6770; }
        .neg { color: #1c5d99; font-weight: bold; }
    </style>
</head>
<body>

<htmlpagefooter name="piedeFascicolo">
    <div style="text-align: center; font-size: 7.4pt; color: #8a949c;">{{ $piede }} – pag. {PAGENO}</div>
</htmlpagefooter>

{{-- ══ COPERTINA ══ --}}
<div style="position: absolute; left: 0; top: 0; width: 210mm; height: 11mm; background: {{ $c['primario'] }};">
    <div style="padding: 4.6mm 0 0 14mm; color: #ffffff; font-size: 7.6pt;">{{ $copertina['sopratitolo'] }}</div>
</div>
<div style="position: absolute; left: 0; top: 11mm; width: 210mm; height: 1.3mm; background: {{ $c['fascia'] }};"></div>
<div style="position: absolute; left: 0; top: 12.3mm; width: 210mm; height: {{ $copertina['foto'] ? '112.7mm' : '252.7mm' }}; background: {{ $c['copertina'] }};"></div>
<div style="position: absolute; left: 16mm; top: 26mm; width: 178mm; color: {{ $c['testo_copertina'] }};">
    <div style="font-size: 25pt; font-weight: bold; line-height: 1.15;">CONDOMINIO<br>{{ mb_strtoupper($nomeCondominio) }}</div>
    <div style="font-size: 11.5pt; margin-top: 7mm;">{{ $condominio['indirizzo'] }}@if($condominio['codice_fiscale']) – C.F. {{ $condominio['codice_fiscale'] }}@endif</div>
    <div style="font-size: 15.5pt; font-weight: bold; margin-top: 9mm; line-height: 1.3;">
        @foreach($copertina['titoli'] as $riga){{ mb_strtoupper($riga) }}@if(! $loop->last)<br>@endif @endforeach
    </div>
    <div style="font-size: 11pt; margin-top: 7mm; line-height: 1.5;">
        Esercizio {{ $esercizio['dal'] }} – {{ $esercizio['al'] }}
        @if($copertina['sottotitolo'])<br>{{ $copertina['sottotitolo'] }}@endif
        @if($bozza)<br><b>BOZZA – {{ $stampato_il }}</b>@endif
    </div>
</div>
@if($copertina['foto'])
<div style="position: absolute; left: 0; top: 125mm; width: 210mm; height: 140mm; overflow: hidden;">
    <img src="{{ $copertina['foto'] }}" style="width: 210mm;">
</div>
@endif
<div style="position: absolute; left: 0; top: 265mm; width: 210mm; height: 32mm; background: {{ $c['fascia'] }};">
    <div style="padding: 12.5mm 0 0 16mm; font-weight: bold; font-size: 10.5pt; color: {{ $c['testo_fascia'] }};">{{ $copertina['studio'] }}</div>
</div>

<pagebreak />
<sethtmlpagefooter name="piedeFascicolo" value="on" page="ALL" />

{{-- ══ NOTA SINTETICA ESPLICATIVA ══ --}}
@if($ha('nota'))
    @php $apri('nota'); @endphp
    <bookmark content="{{ $titoloDi['nota'] }}" level="0" />
    <div class="titolo">NOTA SINTETICA ESPLICATIVA AL BILANCIO<br>
        <span class="sotto">(art. 1130-bis c.c.) – esercizio {{ $esercizio['dal'] }} – {{ $esercizio['al'] }}</span>
    </div>

    <table class="t" style="width: 62%; margin-top: 12px;">
        <thead><tr><th>Sommario</th><th style="width: 38%;">Pagine</th></tr></thead>
        <tbody>
        @foreach($sezioni as $s)
            <tr>
                <td>{{ $s['titolo'] }}</td>
                <td>
                    @php $pp = $pagine[$s['chiave']] ?? null; @endphp
                    @if($pp === null) … @elseif($pp[0] === $pp[1]) pag. {{ $pp[0] }} @else da pag. {{ $pp[0] }} a pag. {{ $pp[1] }} @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
    <div class="spazio"></div>

    @if($dati['nota']['testo'] !== '')
        {!! \App\Services\Fascicolo\TestoNota::html($dati['nota']['testo']) !!}
    @else
        <p>Gentili Condòmini,</p>
        <p>la presente nota accompagna
            @if($tipo === 'consuntivo') il rendiconto dell'esercizio {{ $esercizio['anno'] }} del {{ $condominio['nome'] }} ({{ $esercizio['dal'] }} – {{ $esercizio['al'] }})@else il bilancio preventivo {{ $esercizio['anno'] }} del {{ $condominio['nome'] }}@endif.
            Il fascicolo comprende: {{ mb_strtolower(implode(', ', array_map(fn ($s) => $s['titolo'], array_filter($sezioni, fn ($s) => $s['chiave'] !== 'nota')))) }}.</p>
    @endif

    @if($numeri)
        <h3>I numeri dell'esercizio</h3>
        <table class="t" style="width: 62%;">
            <tbody>
            @foreach($numeri as $i => $n)
                <tr class="{{ $i % 2 ? 'z' : '' }}"><td>{{ $n['voce'] }}</td><td class="n" style="width: 32%;">{{ $conSegno($n['importo']) }}</td></tr>
            @endforeach
            </tbody>
        </table>
        <p class="piccolo" style="margin-top: 4px;">Importi in euro. Ogni cifra è ripresa nel dettaglio nelle pagine indicate dal sommario.</p>
    @endif
@endif

{{-- ══ RENDICONTO DELLE SPESE ══ --}}
@if($ha('spese'))
    @if($apri('spese'))<pagebreak />@endif
    <bookmark content="{{ $titoloDi['spese'] }}" level="0" />
    <div class="titolo">{{ mb_strtoupper($titoloDi['spese']) }}</div>
    @foreach($dati['spese']['gestioni'] as $g)
        <table class="t">
            <thead>
            <tr>
                <th>{{ count($dati['spese']['gestioni']) > 1 ? $g['nome'].' – voce di spesa' : 'Voce di spesa' }}</th>
                <th class="n" style="width: 17%;">Preventivo</th>
                <th class="n" style="width: 17%;">Speso</th>
                <th class="n" style="width: 17%;">di cui da pagare</th>
            </tr>
            </thead>
            <tbody>
            @php $riga = 0; @endphp
            @foreach($g['gruppi'] as $gr)
                @if($gr['capitolo'])
                    <tr class="cap"><td colspan="4">{{ $gr['capitolo'] }}</td></tr>
                @endif
                @foreach($gr['voci'] as $v)
                    <tr class="{{ $riga++ % 2 ? 'z' : '' }}">
                        <td style="{{ $gr['capitolo'] ? 'padding-left: 14px;' : '' }}">{{ $v['nome'] }}</td>
                        <td class="n">{{ $eurVuoto($v['preventivo']) }}</td>
                        <td class="n">{{ $conSegno($v['speso']) }}</td>
                        <td class="n">{{ $eurVuoto($v['da_pagare']) }}</td>
                    </tr>
                @endforeach
            @endforeach
            <tr class="tot">
                <td>Totale {{ mb_strtolower($g['nome']) }}</td>
                <td class="n">{{ $eur($g['totale']['preventivo']) }}</td>
                <td class="n">{{ $conSegno($g['totale']['speso']) }}</td>
                <td class="n">{{ $eurVuoto($g['totale']['da_pagare']) }}</td>
            </tr>
            @foreach($g['entrate'] as $v)
                <tr><td>Entrata: {{ $v['nome'] }}</td><td class="n">{{ $eurVuoto($v['preventivo']) }}</td><td class="n">{{ $conSegno($v['speso']) }}</td><td></td></tr>
            @endforeach
            </tbody>
        </table>
    @endforeach
    @if(count($dati['spese']['gestioni']) > 1)
        <table class="t">
            <tr class="tot">
                <td>TOTALE SPESE DELL'ESERCIZIO</td>
                <td class="n" style="width: 17%;">{{ $eur($dati['spese']['totale']['preventivo']) }}</td>
                <td class="n" style="width: 17%;">{{ $conSegno($dati['spese']['totale']['speso']) }}</td>
                <td class="n" style="width: 17%;">{{ $eurVuoto($dati['spese']['totale']['da_pagare']) }}</td>
            </tr>
        </table>
    @endif
    <p class="piccolo" style="margin-top: 4px;">«Speso» sono le spese di competenza dell'esercizio, pagate o ancora da pagare; «di cui da pagare» è la parte non ancora saldata ai fornitori.</p>
@endif

{{-- ══ SITUAZIONE DEI CONDÒMINI ══ --}}
@if($ha('condomini'))
    @if($apri('condomini'))<pagebreak />@else<div class="spazio"></div>@endif
    @php $sc = $dati['condomini']; $nG = count($sc['gestioni']); @endphp
    <bookmark content="{{ $titoloDi['condomini'] }}" level="0" />
    <div class="titolo">SITUAZIONE DEI CONDÒMINI<br><span class="sotto">rate dovute e versamenti al {{ $sc['al'] }}</span></div>
    <p class="piccolo" style="margin-top: 5px;">Saldo = totale dovuto – versato. Saldo positivo = da versare; con il segno meno = a credito.</p>
    <table class="t {{ $nG > 3 ? 'stretta' : '' }}">
        <thead>
        <tr>
            <th style="width: 7%;">U.I.</th>
            <th>Condòmino</th>
            @if($nG > 1)
                @foreach($sc['gestioni'] as $nome)<th class="n">{{ $nome }}</th>@endforeach
            @endif
            <th class="n" style="width: 13%;">Totale dovuto</th>
            <th class="n" style="width: 13%;">Versato</th>
            <th class="n" style="width: 13%;">Saldo</th>
        </tr>
        </thead>
        <tbody>
        @foreach($sc['righe'] as $i => $r)
            <tr class="{{ $i % 2 ? 'z' : '' }}">
                <td>{{ $r['unita'] }}</td>
                <td>{{ $r['persona'] }}</td>
                @if($nG > 1)
                    @foreach($r['per_gestione'] as $v)<td class="n">{{ $eurVuoto($v) }}</td>@endforeach
                @endif
                <td class="n">{{ $eur($r['dovuto']) }}</td>
                <td class="n">{{ $eur($r['versato']) }}</td>
                <td class="n {{ $r['saldo'] < 0 ? 'neg' : '' }}">{{ $conSegno($r['saldo']) }}</td>
            </tr>
        @endforeach
        <tr class="tot">
            <td colspan="2">Totale</td>
            @if($nG > 1)
                @foreach($sc['totale']['per_gestione'] as $v)<td class="n">{{ $eur($v) }}</td>@endforeach
            @endif
            <td class="n">{{ $eur($sc['totale']['dovuto']) }}</td>
            <td class="n">{{ $eur($sc['totale']['versato']) }}</td>
            <td class="n">{{ $conSegno($sc['totale']['saldo']) }}</td>
        </tr>
        </tbody>
    </table>
@endif

{{-- ══ RENDICONTO DI CASSA E STATO PATRIMONIALE ══ --}}
@if($ha('patrimoniale'))
    @if($apri('patrimoniale'))<pagebreak />@else<div class="spazio"></div>@endif
    @php $sp = $dati['patrimoniale']; @endphp
    <bookmark content="{{ $titoloDi['patrimoniale'] }}" level="0" />
    <div class="titolo">RENDICONTO DI CASSA<br><span class="sotto">entrate e uscite di banca e contanti – esercizio {{ $esercizio['dal'] }} – {{ $esercizio['al'] }}</span></div>
    <table class="t">
        <thead>
        <tr><th>Conto o cassa</th><th class="n" style="width: 17%;">Saldo iniziale</th><th class="n" style="width: 17%;">Entrate</th><th class="n" style="width: 17%;">Uscite</th><th class="n" style="width: 17%;">Saldo finale</th></tr>
        </thead>
        <tbody>
        @foreach($sp['casse'] as $i => $r)
            <tr class="{{ $i % 2 ? 'z' : '' }}">
                <td>{{ $r['cassa'] }}</td>
                <td class="n">{{ $conSegno($r['iniziale']) }}</td><td class="n">{{ $eur($r['entrate']) }}</td><td class="n">{{ $eur($r['uscite']) }}</td><td class="n">{{ $conSegno($r['finale']) }}</td>
            </tr>
        @endforeach
        @if($sp['casse_totale'])
            <tr class="tot">
                <td>Totale</td>
                <td class="n">{{ $conSegno($sp['casse_totale']['iniziale']) }}</td><td class="n">{{ $eur($sp['casse_totale']['entrate']) }}</td><td class="n">{{ $eur($sp['casse_totale']['uscite']) }}</td><td class="n">{{ $conSegno($sp['casse_totale']['finale']) }}</td>
            </tr>
        @endif
        </tbody>
    </table>
    <p class="piccolo" style="margin-top: 4px;">I passaggi di denaro fra un conto e l'altro del condominio compaiono come uscita dell'uno ed entrata dell'altro.</p>

    <div class="spazio"></div>
    <div class="titolo">STATO PATRIMONIALE AL {{ $sp['al'] }}</div>
    <table style="width: 100%; border-collapse: collapse; margin-top: 7px;">
        <tr>
            <td style="width: 49.5%; vertical-align: top; padding: 0;">
                <table class="t" style="margin-top: 0;">
                    <thead><tr><th>Attività</th><th class="n" style="width: 32%;">Importo</th></tr></thead>
                    <tbody>
                    @foreach($sp['attivo']['gruppi'] as $gr)
                        <tr class="sub"><td>{{ $gr['gruppo'] }}</td><td class="n">{{ $conSegno($gr['totale']) }}</td></tr>
                        @foreach($gr['voci'] as $v)
                            <tr><td style="padding-left: 14px;">{{ $v['nome'] }}</td><td class="n">{{ $conSegno($v['saldo']) }}</td></tr>
                        @endforeach
                    @endforeach
                    @if($sp['costi'] !== 0)
                        <tr class="sub"><td>Spese dell'esercizio da conguagliare</td><td class="n">{{ $conSegno($sp['costi']) }}</td></tr>
                    @endif
                    <tr class="tot"><td>TOTALE ATTIVITÀ</td><td class="n">{{ $conSegno($sp['attivo']['totale'] + $sp['costi']) }}</td></tr>
                    </tbody>
                </table>
            </td>
            <td style="width: 1%;"></td>
            <td style="width: 49.5%; vertical-align: top; padding: 0;">
                <table class="t" style="margin-top: 0;">
                    <thead><tr><th>Passività</th><th class="n" style="width: 32%;">Importo</th></tr></thead>
                    <tbody>
                    @foreach($sp['passivo']['gruppi'] as $gr)
                        <tr class="sub"><td>{{ $gr['gruppo'] }}</td><td class="n">{{ $conSegno($gr['totale']) }}</td></tr>
                        @foreach($gr['voci'] as $v)
                            <tr><td style="padding-left: 14px;">{{ $v['nome'] }}</td><td class="n">{{ $conSegno($v['saldo']) }}</td></tr>
                        @endforeach
                    @endforeach
                    @if($sp['ricavi'] !== 0)
                        <tr class="sub"><td>Entrate dell'esercizio da conguagliare</td><td class="n">{{ $conSegno($sp['ricavi']) }}</td></tr>
                    @endif
                    <tr class="tot"><td>TOTALE PASSIVITÀ</td><td class="n">{{ $conSegno($sp['passivo']['totale'] + $sp['ricavi']) }}</td></tr>
                    </tbody>
                </table>
            </td>
        </tr>
    </table>
@endif

{{-- ══ FATTURE DA PAGARE ══ --}}
@if($ha('fatture'))
    @if($apri('fatture'))<pagebreak />@else<div class="spazio"></div>@endif
    @php $ft = $dati['fatture']; @endphp
    <bookmark content="{{ $titoloDi['fatture'] }}" level="0" />
    <div class="titolo">FATTURE DA PAGARE AL {{ $ft['al'] }}</div>
    <table class="t">
        <thead><tr><th style="width: 12%;">Data</th><th>Fornitore</th><th style="width: 20%;">Documento</th><th style="width: 12%;">Scadenza</th><th class="n" style="width: 16%;">Da pagare</th></tr></thead>
        <tbody>
        @foreach($ft['righe'] as $i => $r)
            <tr class="{{ $i % 2 ? 'z' : '' }}">
                <td>{{ $r['data'] }}</td><td>{{ $r['fornitore'] }}</td><td>{{ $r['numero'] }}</td><td>{{ $r['scadenza'] }}</td><td class="n">{{ $conSegno($r['residuo']) }}</td>
            </tr>
        @endforeach
        <tr class="tot"><td colspan="4">Totale</td><td class="n">{{ $conSegno($ft['totale']) }}</td></tr>
        @if($ft['erario'] !== 0)
            <tr><td colspan="4">Ritenute d'acconto da versare all'Erario</td><td class="n">{{ $conSegno($ft['erario']) }}</td></tr>
        @endif
        </tbody>
    </table>
@endif

{{-- ══ REGISTRO DI CONTABILITÀ ══ --}}
@if($ha('registro'))
    @if($apri('registro'))<pagebreak />@else<div class="spazio"></div>@endif
    @php $rg = $dati['registro']; @endphp
    <bookmark content="{{ $titoloDi['registro'] }}" level="0" />
    <div class="titolo">REGISTRO DI CONTABILITÀ<br><span class="sotto">movimenti di banca e contanti – {{ $esercizio['dal'] }} – {{ $esercizio['al'] }}</span></div>
    <table class="t">
        <thead>
        <tr><th style="width: 6%;">N.</th><th style="width: 10%;">Data</th><th>Descrizione</th><th class="n" style="width: 13%;">Entrate</th><th class="n" style="width: 13%;">Uscite</th><th class="n" style="width: 14%;">Saldo</th></tr>
        </thead>
        <tbody>
        <tr class="cap"><td></td><td>{{ $esercizio['dal'] ? substr($esercizio['dal'], 0, 6).substr($esercizio['dal'], 8) : '' }}</td><td>Saldo iniziale</td><td></td><td></td><td class="n">{{ $conSegno($rg['iniziale']) }}</td></tr>
        @foreach($rg['righe'] as $i => $r)
            <tr class="{{ $i % 2 ? '' : 'z' }}">
                <td>{{ $r['numero'] }}</td>
                <td>{{ $r['data'] }}</td>
                <td>{{ $r['descrizione'] }}@if($r['controparte'] !== '' && ! str_contains(mb_strtolower($r['descrizione']), mb_strtolower($r['controparte']))) – {{ $r['controparte'] }}@endif @if($r['stornata'])<span class="piccolo">(stornato)</span>@endif</td>
                <td class="n">{{ $eurVuoto($r['entrata']) }}</td>
                <td class="n">{{ $eurVuoto($r['uscita']) }}</td>
                <td class="n">{{ $conSegno($r['saldo']) }}</td>
            </tr>
        @endforeach
        <tr class="tot"><td colspan="3">Totale movimenti e saldo finale</td><td class="n">{{ $eur($rg['entrate']) }}</td><td class="n">{{ $eur($rg['uscite']) }}</td><td class="n">{{ $conSegno($rg['finale']) }}</td></tr>
        </tbody>
    </table>
@endif

{{-- ══ BILANCIO PREVENTIVO ══ --}}
@if($ha('preventivo'))
    @if($apri('preventivo'))<pagebreak />@else<div class="spazio"></div>@endif
    @php $pv = $dati['preventivo']; $ep = $esercizio_preventivo; @endphp
    <bookmark content="{{ $titoloDi['preventivo'] }}" level="0" />
    <div class="titolo">{{ mb_strtoupper($titoloDi['preventivo']) }}<br><span class="sotto">{{ $ep['dal'] }} – {{ $ep['al'] }}</span></div>
    @foreach($pv['gestioni'] as $g)
        <table class="t">
            <thead><tr><th>{{ count($pv['gestioni']) > 1 ? $g['nome'].' – voce' : 'Voce' }}</th><th style="width: 30%;">Tabella di riparto</th><th class="n" style="width: 17%;">Importo</th></tr></thead>
            <tbody>
            @php $riga = 0; @endphp
            @foreach($g['gruppi'] as $gr)
                @if($gr['capitolo'])<tr class="cap"><td colspan="3">{{ $gr['capitolo'] }}</td></tr>@endif
                @foreach($gr['voci'] as $v)
                    <tr class="{{ $riga++ % 2 ? 'z' : '' }}">
                        <td style="{{ $gr['capitolo'] ? 'padding-left: 14px;' : '' }}">{{ $v['nome'] }}</td>
                        <td>{{ $v['tabella'] }}</td>
                        <td class="n">{{ $conSegno($v['importo']) }}</td>
                    </tr>
                @endforeach
                @if($gr['capitolo'])
                    <tr class="sub"><td colspan="2">Totale {{ mb_strtolower($gr['capitolo']) }}</td><td class="n">{{ $conSegno($gr['totale']) }}</td></tr>
                @endif
            @endforeach
            <tr class="tot"><td colspan="2">TOTALE {{ mb_strtoupper($g['nome']) }}</td><td class="n">{{ $conSegno($g['totale']) }}</td></tr>
            </tbody>
        </table>
    @endforeach
    @if(count($pv['gestioni']) > 1)
        <table class="t"><tr class="tot"><td>TOTALE PREVENTIVO {{ $ep['anno'] }}</td><td class="n" style="width: 17%;">{{ $conSegno($pv['totale']) }}</td></tr></table>
    @endif
@endif

{{-- ══ RATE PER CONDÒMINO ══ --}}
@if($ha('rate'))
    @php $ep = $esercizio_preventivo; @endphp
    @foreach($dati['rate']['piani'] as $n => $pr)
        @if($n === 0)
            @if($apri('rate'))<pagebreak />@else<div class="spazio"></div>@endif
            <bookmark content="{{ $titoloDi['rate'] }}" level="0" />
        @else
            <div class="spazio"></div>
        @endif
        <div class="titolo">{{ mb_strtoupper($titoloDi['rate']) }}<br><span class="sotto">{{ $pr['nome'] }}@if($pr['bozza']) – proposta, da approvare in assemblea @endif</span></div>
        <table class="t {{ count($pr['colonne']) > 5 ? 'stretta' : '' }}">
            <thead>
            <tr>
                <th style="width: 6%;">U.I.</th>
                <th>Condòmino</th>
                @foreach($pr['colonne'] as $col)<th class="n">{{ $col['etichetta'] }}<br>{{ $col['scadenza'] }}</th>@endforeach
                <th class="n" style="width: 11%;">Totale</th>
            </tr>
            </thead>
            <tbody>
            @foreach($pr['righe'] as $i => $r)
                <tr class="{{ $i % 2 ? 'z' : '' }}">
                    <td>{{ $r['unita'] }}</td>
                    <td>{{ $r['persona'] }}</td>
                    @foreach($pr['colonne'] as $col)<td class="n">{{ isset($r['rate'][$col['id']]) ? $conSegno($r['rate'][$col['id']]) : '' }}</td>@endforeach
                    <td class="n"><b>{{ $conSegno($r['totale']) }}</b></td>
                </tr>
            @endforeach
            <tr class="tot">
                <td colspan="2">Totale</td>
                @foreach($pr['colonne'] as $col)<td class="n">{{ $conSegno($pr['totali'][$col['id']] ?? 0) }}</td>@endforeach
                <td class="n">{{ $conSegno($pr['totale']) }}</td>
            </tr>
            </tbody>
        </table>
    @endforeach
@endif

</body>
</html>
