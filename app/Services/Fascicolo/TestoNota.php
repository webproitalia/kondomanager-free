<?php

namespace App\Services\Fascicolo;

/**
 * Il testo della nota sintetica esplicativa, da come lo scrive lo studio (o la sua AI) a HTML per la stampa.
 *
 * Poche regole, quelle che servono a una lettera ai condòmini e niente che si possa sbagliare:
 * una riga che comincia con «# » è un titolo di paragrafo («# I. Il conto corrente»); righe che cominciano con «- » o con
 * «1. » sono un elenco; `**testo**` è in grassetto; una riga vuota separa i paragrafi. Tutto il resto è testo, e
 * qualunque HTML scritto dentro viene mostrato com'è, non eseguito.
 *
 * Con `$aCapo` (il verbale dell'assemblea, Web Pro Italia 17/11/2026) le righe di un paragrafo restano su righe
 * diverse invece di unirsi: nel verbale «Favorevoli…», «Contrari…» e «Astenuti…» stanno una sotto l'altra. Senza, la
 * nota del fascicolo esce come sempre.
 *
 * **Negli elenchi numerati il numero stampato è quello scritto** (17/11/2026): una riga «2. …» esce «2.» anche se
 * prima c'è una riga vuota o un paragrafo, e un elenco che comincia da «3.» comincia da 3. In una delibera a più capi
 * un «2.» stampato «1.» è un errore. La stampa (mPDF) numera da sola le voci di un elenco e conosce solo il numero di
 * partenza (`start`): per questo un numero che non è il successivo del precedente apre un elenco nuovo che parte da lì.
 */
class TestoNota
{
    public static function html(string $testo, bool $aCapo = false): string
    {
        $righe = preg_split('/\r\n|\r|\n/', trim($testo)) ?: [];
        $html = '';
        $paragrafo = [];
        $elenco = null; // 'ul' | 'ol'
        $numero = null; // l'ultimo numero stampato dell'elenco numerato aperto

        $chiudiParagrafo = function () use (&$paragrafo, &$html, $aCapo) {
            if ($paragrafo) {
                $html .= '<p>'.implode($aCapo ? '<br>' : ' ', $paragrafo).'</p>';
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

            // Una riga numerata vale come voce d'elenco da «1.» in su e fino a sei cifre: «0.» e un numero lunghissimo restano testo, com'è scritto.
            if (preg_match('/^(?:[-•*]\s+|(\d{1,6})[.)]\s+)(.+)$/u', $riga, $m) && (($m[1] ?? '') === '' || (int) $m[1] > 0)) {
                $chiudiParagrafo();
                $tipo = ($m[1] ?? '') !== '' ? 'ol' : 'ul';
                $scritto = $tipo === 'ol' ? (int) $m[1] : null;
                // Il numero scritto non è il successivo di quello appena stampato: l'elenco si chiude e ne parte uno nuovo da lì.
                if ($elenco !== $tipo || ($tipo === 'ol' && $scritto !== $numero + 1)) {
                    $chiudiElenco();
                    $html .= $tipo === 'ol' && $scritto !== 1 ? "<ol start=\"{$scritto}\">" : "<{$tipo}>";
                    $elenco = $tipo;
                }
                $numero = $scritto;
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
