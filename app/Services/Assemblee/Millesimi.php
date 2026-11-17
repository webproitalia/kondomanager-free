<?php

namespace App\Services\Assemblee;

/**
 * I millesimi dell'assemblea in numeri interi (Web Pro Italia, 17/11/2026).
 *
 * Le quote delle tabelle sono decimali a cinque cifre (`quote_tabella.valore`, `decimal(12,5)`). Qui diventano interi in
 * centomillesimi di unità — 333,33333 ‰ è 33333333 — e si sommano senza perdere niente. I confronti con le frazioni di
 * legge (metà, un terzo, due terzi del valore) si fanno moltiplicando in croce: «almeno due terzi» è `3 × parte ≥ 2 ×
 * totale`, esatto. Nessun numero con la virgola entra in un confronto; l'arrotondamento a due cifre serve solo a
 * scrivere il numero sulla pagina e sul verbale.
 *
 * Il «valore dell'edificio» è il totale della tabella scelta: se la tabella somma 1.000, i millesimi sono quelli; se
 * somma un altro numero (999,99 per arrotondamenti, o 100 se è in percentuali), i conti si fanno su quel totale e la
 * pagina riporta ogni valore a mille.
 */
final class Millesimi
{
    public const SCALA = 100000;

    /** Un valore della tabella (stringa decimale, numero o null) in centomillesimi. */
    public static function daValore(string|int|float|null $valore): int
    {
        if ($valore === null || $valore === '') {
            return 0;
        }

        $testo = is_string($valore) ? trim($valore) : sprintf('%.5F', (float) $valore);
        $testo = str_replace(',', '.', $testo);

        if (! preg_match('/^(-?)(\d*)(?:\.(\d*))?$/', $testo, $m)) {
            return (int) round((float) $testo * self::SCALA);
        }

        $intero = (int) ($m[2] === '' ? '0' : $m[2]);
        $decimali = substr(str_pad($m[3] ?? '', 6, '0'), 0, 6);
        // La sesta cifra arrotonda la quinta: un valore scritto con più cifre del campo non si tronca in silenzio.
        $frazione = intdiv((int) $decimali + 5, 10);
        $risultato = $intero * self::SCALA + $frazione;

        return $m[1] === '-' ? -$risultato : $risultato;
    }

    /** Centomillesimi in un decimale a cinque cifre, per il database. */
    public static function inDecimale(int $unita): string
    {
        $segno = $unita < 0 ? '-' : '';
        $unita = abs($unita);

        return $segno.intdiv($unita, self::SCALA).'.'.str_pad((string) ($unita % self::SCALA), 5, '0', STR_PAD_LEFT);
    }

    /**
     * La parte riportata a mille, in centesimi di millesimo (66667 = 666,67 ‰), arrotondata al centesimo più vicino.
     * Solo per scrivere il numero: i confronti si fanno con {@see almeno()}.
     */
    public static function suMilleInCentesimi(int $parte, int $totale): int
    {
        if ($totale <= 0) {
            return 0;
        }

        // parte × 1000 × 100 / totale, arrotondato a metà in su; i prodotti restano dentro i 64 bit fino a 10^12 ‰.
        return intdiv(2 * $parte * 100000 + $totale, 2 * $totale);
    }

    /** «666,67»: la parte riportata a mille, con la virgola. */
    public static function testoSuMille(int $parte, int $totale): string
    {
        return self::testoCentesimi(self::suMilleInCentesimi($parte, $totale));
    }

    /** «1.234,56» da centesimi. */
    public static function testoCentesimi(int $centesimi): string
    {
        return number_format($centesimi / 100, 2, ',', '.');
    }

    /** Il valore della tabella come si legge, con le cifre che servono (da due a cinque decimali). */
    public static function testoValore(int $unita): string
    {
        $decimali = 2;
        while ($decimali < 5 && $unita % (10 ** (5 - $decimali)) !== 0) {
            $decimali++;
        }

        return number_format($unita / self::SCALA, $decimali, ',', '.');
    }

    /** `parte / totale ≥ numeratore / denominatore`, in interi. */
    public static function almeno(int $parte, int $totale, int $numeratore, int $denominatore): bool
    {
        return $parte * $denominatore >= $totale * $numeratore;
    }

    /** `parte / totale > numeratore / denominatore`, in interi: la «maggioranza» è più della metà. */
    public static function oltre(int $parte, int $totale, int $numeratore, int $denominatore): bool
    {
        return $parte * $denominatore > $totale * $numeratore;
    }
}
