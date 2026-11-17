<?php

namespace App\Services\Registrazioni;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Deepgram SENTE, Gemini CAPISCE (come in Follow Call): dalla trascrizione con le voci, il riassunto della riunione e, per un'assemblea con
 * l'ordine del giorno, una bozza per punto. Tutto è un di più: se Gemini non risponde la trascrizione esce lo stesso, e qui si torna `null`.
 *
 * Due trappole già trovate in Follow Call: senza `thinkingConfig.thinkingBudget = 0` il modello «pensa» e consuma i token della risposta, e
 * con un `maxOutputTokens` basso la risposta esce tagliata a metà. La chiave viaggia nell'intestazione `x-goog-api-key`, non nell'indirizzo:
 * così non finisce nei log.
 */
class Gemini
{
    public const URL = 'https://generativelanguage.googleapis.com/v1beta/models/';

    /** Quanta trascrizione si manda al massimo: un'ora e mezza di riunione sta intorno ai 100.000 caratteri. */
    private const MAX_CARATTERI = 400000;

    public function disponibile(): bool
    {
        return trim((string) config('services.gemini.key')) !== '';
    }

    /**
     * Il riassunto, a sezioni, e un titolo proposto.
     *
     * @return array{titolo: ?string, testo: string}|null
     */
    public function riassunto(string $trascrizione, string $condominio, ?array $punti = null): ?array
    {
        $trascrizione = trim($trascrizione);

        if (! $this->disponibile() || mb_strlen($trascrizione) < 80) {
            return null;
        }

        $odg = $punti ? "\n\nL'ordine del giorno dell'assemblea era:\n".implode("\n", array_map(fn ($p) => $p['numero'].'. '.$p['titolo'], $punti))."\n" : '';

        $istruzioni = <<<TESTO
Sei l'assistente di uno studio che amministra condomini. Ti do la trascrizione di una riunione del condominio «{$condominio}», spesso un'assemblea. Scrivi in italiano la scheda della riunione, che servirà a chi scrive il verbale.
Le voci sono separate come «Persona 1», «Persona 2» e simili: non sai chi sono. Usa un nome solo se nella trascrizione qualcuno si presenta o viene chiamato per nome. Possono esserci errori di trascrizione: interpretali col buon senso, senza segnalarli.{$odg}

Come PRIMA riga scrivi esattamente «TITOLO: » seguito da un titolo di 3-8 parole che dica di che cosa si è parlato, senza virgolette e senza punto finale. Poi una riga vuota.

Dopo il titolo scrivi SOLO queste sezioni, e SOLTANTO quelle che hanno davvero contenuto (salta le vuote, non scrivere «nessuno»):

**In breve**
Che cosa è successo nella riunione, in due o tre righe.

**Argomenti trattati**
- Gli argomenti affrontati, uno per riga, concreti.

**Decisioni**
- Solo ciò che è stato deciso davvero. L'esito di una votazione (approvato, respinto, con quali voti o millesimi) scrivilo solo se è stato detto chiaramente; se si è votato senza un esito chiaro, scrivi che l'esito non risulta dalla registrazione.

**Da fare**
- Chi fa che cosa, ed entro quando se è stato detto.

**Rimasto in sospeso**
- Domande aperte, dubbi, cose da verificare o da rimandare.

Regole: frasi brevi. Nomi, cifre, importi, millesimi, date e nomi di ditte riportali ESATTI come li hanno detti. Non inventare niente: se una cosa non è stata detta, non c'è. Niente introduzioni né commenti tuoi: parti dal titolo.

TRASCRIZIONE:
TESTO;

        $testo = $this->chiedi($istruzioni.mb_substr($trascrizione, 0, self::MAX_CARATTERI), 8192);

        if ($testo === null || trim($testo) === '') {
            return null;
        }

        $titolo = null;
        $righe = explode("\n", trim($testo));
        foreach (array_slice($righe, 0, 3, true) as $i => $riga) {
            if (preg_match('/^\s*\**\s*TITOLO:\s*(.+?)\s*\**\s*$/u', $riga, $m)) {
                $titolo = mb_substr(trim(preg_replace('/^[«"\']+|[»"\'.]+$/u', '', $m[1])), 0, 140) ?: null;
                unset($righe[$i]);
                break;
            }
        }

        $corpo = self::togliSezioniVuote(implode("\n", $righe));

        return $corpo === '' ? null : ['titolo' => $titolo, 'testo' => $corpo];
    }

    /**
     * La bozza per punto dell'ordine del giorno: `[{numero, discussione, delibera}]`, un elemento per ogni punto dato, nello stesso ordine.
     * `discussione` è la sintesi di quello che si è detto sul punto (vuota se non se n'è parlato), `delibera` la decisione solo se è stata
     * detta chiaramente, altrimenti `null`. Niente esiti di voto inventati: lo dice la richiesta, e qui si scarta ciò che non è dei punti dati.
     *
     * @param  list<array{numero: int, titolo: string}>  $punti
     * @return list<array{numero: int, discussione: string, delibera: ?string}>|null
     */
    public function bozzaPunti(string $trascrizione, string $condominio, array $punti): ?array
    {
        $trascrizione = trim($trascrizione);

        if (! $this->disponibile() || $punti === [] || mb_strlen($trascrizione) < 80) {
            return null;
        }

        $odg = implode("\n", array_map(fn ($p) => $p['numero'].'. '.$p['titolo'], $punti));

        $istruzioni = <<<TESTO
Sei l'assistente di uno studio che amministra condomini. Ti do l'ordine del giorno di un'assemblea del condominio «{$condominio}» e la trascrizione della riunione (le voci sono «Persona 1», «Persona 2»…; possono esserci errori di trascrizione, interpretali col buon senso).

Per OGNI punto dell'ordine del giorno scrivi:
- "discussione": la sintesi fedele di quello che si è detto su quel punto, in italiano, da 2 a 8 frasi, con nomi, cifre, importi e millesimi ESATTI come sono stati detti. Scrivila come un verbale, in forma impersonale e al presente: «Viene illustrato…», «Un condòmino chiede…», «L'amministratore risponde…», «Il presidente mette ai voti…». Non scrivere mai «Persona 1», «Persona 2» o simili: usa il nome solo se nella trascrizione qualcuno si presenta o viene chiamato per nome, il ruolo (presidente, amministratore) solo se si capisce, altrimenti «un condòmino», «un altro condòmino». Gli importi scrivili in cifre (12.480 euro). Se del punto non si è parlato, stringa vuota.
- "delibera": la decisione presa su quel punto SOLO se nella trascrizione è detta chiaramente (per esempio «si approva all'unanimità», «l'assemblea decide di…»), con l'esito del voto solo se è stato detto. Se la decisione non è chiara o non c'è stata, null. Non inventare esiti di voto, maggioranze, millesimi, nomi o importi.

Rispondi SOLO con un array JSON, un oggetto per punto, con gli stessi numeri dell'ordine del giorno: [{"numero": 1, "discussione": "…", "delibera": "…" oppure null}].

ORDINE DEL GIORNO:
{$odg}

TRASCRIZIONE:
TESTO;

        $schema = [
            'type' => 'ARRAY',
            'items' => [
                'type' => 'OBJECT',
                'properties' => [
                    'numero' => ['type' => 'INTEGER'],
                    'discussione' => ['type' => 'STRING'],
                    'delibera' => ['type' => 'STRING', 'nullable' => true],
                ],
                'required' => ['numero', 'discussione'],
            ],
        ];

        $testo = $this->chiedi($istruzioni.mb_substr($trascrizione, 0, self::MAX_CARATTERI), 16384, $schema);

        if ($testo === null) {
            return null;
        }

        $dati = json_decode(trim(preg_replace('/^```(?:json)?|```$/m', '', trim($testo))), true);

        if (! is_array($dati)) {
            Log::warning('Registrazioni: la bozza per punti di Gemini non è un JSON leggibile', ['inizio' => mb_substr($testo, 0, 200)]);

            return null;
        }

        $perNumero = [];
        foreach ($dati as $voce) {
            if (! is_array($voce) || ! is_numeric($voce['numero'] ?? null)) {
                continue;
            }
            $perNumero[(int) $voce['numero']] = $voce;
        }

        $bozza = [];
        foreach ($punti as $punto) {
            $voce = $perNumero[(int) $punto['numero']] ?? [];
            $delibera = is_string($voce['delibera'] ?? null) ? trim($voce['delibera']) : '';

            $bozza[] = [
                'numero' => (int) $punto['numero'],
                'discussione' => is_string($voce['discussione'] ?? null) ? trim($voce['discussione']) : '',
                'delibera' => $delibera === '' || preg_match('/^(null|nessuna|n\/a|-|—)\.?$/iu', $delibera) ? null : $delibera,
            ];
        }

        return $bozza;
    }

    /**
     * Le sezioni senza contenuto si tolgono invece di riempirle con «Nessuno»: il modello a volte le scrive lo stesso, e una scheda piena di
     * «nessuno» si legge peggio di una corta.
     */
    public static function togliSezioniVuote(string $testo): string
    {
        $righe = explode("\n", $testo);
        $fuori = [];
        $titolo = fn (string $r) => preg_match('/^\*\*[^*]+\*\*\s*$/u', trim($r)) === 1;

        for ($i = 0; $i < count($righe); $i++) {
            if (! $titolo($righe[$i])) {
                $fuori[] = $righe[$i];

                continue;
            }

            $corpo = [];
            $j = $i + 1;
            while ($j < count($righe) && ! $titolo($righe[$j])) {
                $corpo[] = $righe[$j];
                $j++;
            }

            $utile = trim(preg_replace('/^[\s\-•*]+/mu', '', implode("\n", $corpo)));
            if ($utile !== '' && ! preg_match('/^(nessun[oaie]?|n\/a|-|—)\.?$/iu', $utile)) {
                array_push($fuori, $righe[$i], ...$corpo);
            }
            $i = $j - 1;
        }

        return trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $fuori)));
    }

    private function chiedi(string $testo, int $maxToken, ?array $schema = null): ?string
    {
        $modello = (string) config('services.gemini.model', 'gemini-2.5-flash') ?: 'gemini-2.5-flash';
        $configurazione = ['temperature' => 0.2, 'maxOutputTokens' => $maxToken, 'thinkingConfig' => ['thinkingBudget' => 0]];

        if ($schema !== null) {
            $configurazione['responseMimeType'] = 'application/json';
            $configurazione['responseSchema'] = $schema;
        }

        try {
            $risposta = Http::withHeaders(['x-goog-api-key' => trim((string) config('services.gemini.key'))])
                ->connectTimeout(15)
                ->timeout(300)
                ->post(self::URL.rawurlencode($modello).':generateContent', [
                    'contents' => [['role' => 'user', 'parts' => [['text' => $testo]]]],
                    'generationConfig' => $configurazione,
                ]);
        } catch (Throwable $e) {
            Log::warning('Registrazioni: Gemini non risponde', ['errore' => mb_substr($e->getMessage(), 0, 300)]);

            return null;
        }

        if (! $risposta->successful()) {
            Log::warning('Registrazioni: Gemini ha risposto '.$risposta->status(), ['risposta' => mb_substr($risposta->body(), 0, 300)]);

            return null;
        }

        $candidato = $risposta->json('candidates.0') ?? [];
        $testo = implode('', array_map(fn ($p) => (string) ($p['text'] ?? ''), $candidato['content']['parts'] ?? []));

        if (($candidato['finishReason'] ?? 'STOP') !== 'STOP') {
            Log::warning('Registrazioni: risposta di Gemini chiusa in anticipo', ['motivo' => $candidato['finishReason']]);
        }

        return trim($testo) === '' ? null : $testo;
    }
}
