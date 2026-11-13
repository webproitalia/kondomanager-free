<?php

namespace App\Services\Fascicolo;

/**
 * Il testo della nota sintetica esplicativa, da come lo scrive lo studio (o la sua AI) a HTML per la stampa.
 *
 * Poche regole, quelle che servono a una lettera ai condòmini e niente che si possa sbagliare:
 * una riga che comincia con «# » è un titolo di paragrafo («# I. Il conto corrente»); righe che cominciano con «- » o con
 * «1. » sono un elenco; `**testo**` è in grassetto; una riga vuota separa i paragrafi. Tutto il resto è testo, e
 * qualunque HTML scritto dentro viene mostrato com'è, non eseguito.
 */
class TestoNota
{
    public static function html(string $testo): string
    {
        $righe = preg_split('/\r\n|\r|\n/', trim($testo)) ?: [];
        $html = '';
        $paragrafo = [];
        $elenco = null; // 'ul' | 'ol'

        $chiudiParagrafo = function () use (&$paragrafo, &$html) {
            if ($paragrafo) {
                $html .= '<p>'.implode(' ', $paragrafo).'</p>';
                $paragrafo = [];
            }
        };
        $chiudiElenco = function () use (&$elenco, &$html) {
            if ($elenco) {
                $html .= "</{$elenco}>";
                $elenco = null;
            }
        };

        foreach ($righe as $riga) {
            $riga = trim($riga);

            if ($riga === '') {
                $chiudiParagrafo();
                $chiudiElenco();

                continue;
            }

            if (preg_match('/^#{1,3}\s+(.+)$/u', $riga, $m)) {
                $chiudiParagrafo();
                $chiudiElenco();
                $html .= '<h3>'.self::riga($m[1]).'</h3>';

                continue;
            }

            if (preg_match('/^(?:[-•*]\s+|(\d+)[.)]\s+)(.+)$/u', $riga, $m)) {
                $chiudiParagrafo();
                $tipo = ($m[1] ?? '') !== '' ? 'ol' : 'ul';
                if ($elenco !== $tipo) {
                    $chiudiElenco();
                    $html .= "<{$tipo}>";
                    $elenco = $tipo;
                }
                $html .= '<li>'.self::riga($m[2]).'</li>';

                continue;
            }

            $chiudiElenco();
            $paragrafo[] = self::riga($riga);
        }

        $chiudiParagrafo();
        $chiudiElenco();

        return $html;
    }

    private static function riga(string $testo): string
    {
        $sicuro = e($testo);

        return preg_replace('/\*\*(.+?)\*\*/u', '<b>$1</b>', $sicuro) ?? $sicuro;
    }
}
