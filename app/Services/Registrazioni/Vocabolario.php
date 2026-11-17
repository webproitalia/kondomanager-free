<?php

namespace App\Services\Registrazioni;

use App\Models\Condominio;

/**
 * Le parole che Deepgram deve riconoscere meglio (`keyterm`): quelle di un'assemblea di condominio e i nomi delle persone del condominio.
 * Deepgram ne accetta qualche decina; se le parole sono troppe le prime contano di più, quindi prima quelle del condominio (il suo nome,
 * le parole delle assemblee), poi i nomi, in ordine alfabetico, fino al tetto.
 */
class Vocabolario
{
    public const TETTO = 50;

    public const PAROLE = [
        'condominio', 'condòmini', 'assemblea', 'ordine del giorno', 'verbale', 'delega', 'deleghe', 'millesimi', 'tabella millesimale',
        'quorum', 'maggioranza', 'unanimità', 'delibera', 'amministratore', 'consiglieri', 'preventivo', 'consuntivo', 'rendiconto',
        'riparto', 'conguaglio', 'fondo di riserva', 'lavori straordinari', 'morosità',
    ];

    /** @return list<string> */
    public function per(Condominio $condominio): array
    {
        $parole = [];
        $aggiungi = function (?string $parola) use (&$parole) {
            $parola = trim(preg_replace('/\s+/u', ' ', (string) $parola));
            if ($parola === '' || mb_strlen($parola) > 60) {
                return;
            }
            $parole[mb_strtolower($parola)] ??= $parola;
        };

        $aggiungi($condominio->nome);
        foreach (self::PAROLE as $parola) {
            $aggiungi($parola);
        }

        $nomi = $condominio->anagrafiche()->orderBy('anagrafiche.nome')->limit(self::TETTO * 2)->pluck('anagrafiche.nome');
        foreach ($nomi as $nome) {
            $aggiungi($nome);
        }

        return array_slice(array_values($parole), 0, self::TETTO);
    }
}
