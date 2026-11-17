<?php

namespace App\Services\Assemblee;

/**
 * Il sostituto dei segnaposto dei modelli di verbale (Web Pro Italia, 17/11/2026). Volutamente piccolo, senza un
 * motore di template esterno:
 *
 * - `{{chiave}}` diventa il valore della chiave;
 * - `{{#elenco}} … {{/elenco}}` si ripete per ogni voce di un elenco (i punti), con le chiavi della voce;
 * - `{{#chiave}} … {{/chiave}}` con una chiave semplice compare solo se il valore non è vuoto (una riga
 *   «Discussione» senza discussione non si stampa);
 * - un segnaposto o un blocco che non conosce **resta com'è**, visibile, e la generazione non si ferma: chi rilegge
 *   lo vede e lo corregge.
 *
 * I valori non sono codice: un valore che contiene `{{…}}` (una delibera che lo cita) resta testo e non viene
 * sostituito a sua volta. Il testo che esce è testo semplice: l'HTML lo fa la stampa, che lo mostra com'è.
 */
final class CompilatoreModello
{
    /** Un carattere privato al posto delle graffe dei valori, finché la sostituzione non è finita. */
    private const GRAFFA = "\u{E000}";

    /**
     * @param  array<string, string>  $valori
     * @param  array<string, list<array<string, string>>>  $elenchi
     */
    public static function compila(string $corpo, array $valori, array $elenchi = []): string
    {
        $valori = array_map([self::class, 'proteggi'], $valori);
        $elenchi = array_map(fn ($voci) => array_map(fn ($voce) => array_map([self::class, 'proteggi'], $voce), $voci), $elenchi);

        $testo = self::sostituisci(self::sezioni($corpo, $valori, $elenchi), $valori);

        return self::ripulisci(str_replace(self::GRAFFA, '{{', $testo));
    }

    /** I segnaposto e i blocchi che il corpo usa e che non esistono: la pagina del modello li segnala. */
    public static function sconosciuti(string $corpo, array $chiavi, array $elenchi): array
    {
        preg_match_all('/\{\{\s*[#\/]?\s*([A-Za-z0-9_.]+)\s*\}\}/u', $corpo, $m);

        return array_values(array_unique(array_filter($m[1], fn ($k) => ! in_array($k, $chiavi, true) && ! array_key_exists($k, $elenchi))));
    }

    private static function sezioni(string $testo, array $valori, array $elenchi): string
    {
        return preg_replace_callback('/\{\{#\s*([A-Za-z0-9_.]+)\s*\}\}(.*?)\{\{\/\s*\1\s*\}\}/su', function (array $m) use ($valori, $elenchi) {
            [$intero, $nome, $dentro] = $m;

            if (array_key_exists($nome, $elenchi)) {
                $fuori = '';
                foreach ($elenchi[$nome] as $voce) {
                    $contesto = $voce + $valori;
                    $fuori .= self::sostituisci(self::sezioni($dentro, $contesto, []), $contesto);
                }

                return $fuori;
            }

            if (array_key_exists($nome, $valori)) {
                return trim((string) $valori[$nome]) !== '' ? self::sezioni($dentro, $valori, $elenchi) : '';
            }

            return $intero;
        }, $testo) ?? $testo;
    }

    private static function sostituisci(string $testo, array $valori): string
    {
        return preg_replace_callback('/\{\{\s*([A-Za-z0-9_.]+)\s*\}\}/u', fn (array $m) => array_key_exists($m[1], $valori) ? (string) $valori[$m[1]] : $m[0], $testo) ?? $testo;
    }

    private static function proteggi(mixed $valore): string
    {
        return str_replace('{{', self::GRAFFA, (string) $valore);
    }

    /** Righe senza spazi in coda, al massimo una riga vuota di fila. */
    private static function ripulisci(string $testo): string
    {
        $testo = preg_replace('/\r\n|\r/', "\n", $testo) ?? $testo;
        $testo = preg_replace('/[ \t]+\n/', "\n", $testo) ?? $testo;
        $testo = preg_replace("/\n{3,}/", "\n\n", $testo) ?? $testo;

        return trim($testo)."\n";
    }
}
