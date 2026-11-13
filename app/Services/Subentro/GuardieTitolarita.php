<?php

namespace App\Services\Subentro;

use App\Enums\RuoloAnagraficaImmobile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Le due guardie sulle righe di `anagrafica_immobile`, riscritte per il tempo (B2, S5; invarianti 11 e 12
 * del progetto `docs/subentro_e_competenza_temporale.md`). Funzioni pure sulle righe già caricate: le
 * chiamano le FormRequest di «Associa»/«Modifica» (via `ValidatesImmobileAnagraficaPivot`) **e**
 * `RegistraSubentroAction`, che prima di S5 non attraversava nessuna guardia.
 *
 * - **Guardia 1** (inv. 12): la stessa persona può avere due periodi sulla stessa unità — vende e
 *   ricompra — purché **non sovrapposti** con lo stesso ruolo. Prima vietava qualunque seconda riga.
 * - **Guardia 2** (inv. 11): la somma delle quote per ruolo è ≤ 100 **in ogni giorno**, non su tutte le
 *   righe di sempre: un venditore chiuso al 30/04 e un acquirente al 100 % dal 01/05 sommano 200 su una
 *   query piatta e 100 in ogni giorno reale. La somma è una funzione a gradini che sale solo a una
 *   `data_inizio`: basta valutarla a ogni `data_inizio` delle righe della coppia e della riga nuova.
 *
 * «In corso il giorno d» qui è `data_inizio ≤ d ≤ data_fine` — la regola di `TitolaritaImmobile::inCorsoIl()`,
 * **più severa** di D7 (dove `data_inizio` filtra solo con un predecessore chiuso). È voluto: la guardia
 * protegge il dato che si sta scrivendo, e una `data_inizio` scritta oggi è una decorrenza. Non
 * «correggerla» a D7: riammetterebbe il 200 %.
 *
 * **La modifica di una riga che esiste già rifiuta solo ciò che peggiora** (Web Pro Italia, 13/11/2026): vedi
 * `sforoQuoteInModifica()`. Una riga **nuova** — «Associa», un passaggio, l'annullamento di un passaggio — resta
 * alla regola di sempre (`sforoQuotePerGiorno()`).
 *
 * Le righe si leggono come oggetti con `id`, `anagrafica_id`, `tipologia`, `quota`, `data_inizio`,
 * `data_fine`, `attivo` (pivot Eloquent o `stdClass`); quelle con `attivo` falso non contano.
 */
final class GuardieTitolarita
{
    private const TOLLERANZA = 0.005;

    /**
     * Guardia 1: la riga nuova si sovrappone a una riga della **stessa persona con lo stesso ruolo**?
     * Restituisce la riga che si sovrappone, o `null`.
     *
     * @param Collection<int, object> $righe le righe dell'unità (tutte)
     * @param array{anagrafica_id: int, tipologia: string, data_inizio: string|CarbonImmutable, data_fine?: string|CarbonImmutable|null} $nuova
     */
    public static function sovrapposizioneStessaPersona(Collection $righe, array $nuova, ?int $escludiRigaId = null): ?object
    {
        [$dal, $al] = self::estremi($nuova);

        foreach ($righe as $r) {
            if ($escludiRigaId !== null && (int) ($r->id ?? 0) === $escludiRigaId) {
                continue;
            }
            if (! self::attiva($r) || (int) $r->anagrafica_id !== (int) $nuova['anagrafica_id'] || (string) $r->tipologia !== (string) $nuova['tipologia']) {
                continue;
            }
            [$rDal, $rAl] = self::estremi(['data_inizio' => $r->data_inizio ?? null, 'data_fine' => $r->data_fine ?? null]);
            if (self::siSovrappongono($dal, $al, $rDal, $rAl)) {
                return $r;
            }
        }

        return null;
    }

    /**
     * Guardia 2: con la riga nuova, in quale giorno la somma delle quote del ruolo supera 100?
     * Restituisce `['giorno' => 'Y-m-d', 'somma' => float]` per il primo giorno che sfora, o `null`.
     *
     * @param Collection<int, object> $righe le righe dell'unità (tutte)
     * @param array{tipologia: string, quota: float|string, data_inizio: string|CarbonImmutable, data_fine?: string|CarbonImmutable|null} $nuova
     */
    public static function sforoQuotePerGiorno(Collection $righe, array $nuova, ?int $escludiRigaId = null): ?array
    {
        [$dal, $al] = self::estremi($nuova);
        $coppia = $righe
            ->filter(fn ($r) => self::attiva($r) && (string) $r->tipologia === (string) $nuova['tipologia'])
            ->reject(fn ($r) => $escludiRigaId !== null && (int) ($r->id ?? 0) === $escludiRigaId)
            ->values();

        // I giorni da controllare: la decorrenza della riga nuova e ogni data_inizio della coppia che
        // cade nel periodo della riga nuova (fuori dal suo periodo la riga nuova non somma).
        $giorni = [$dal];
        foreach ($coppia as $r) {
            [$rDal] = self::estremi(['data_inizio' => $r->data_inizio ?? null, 'data_fine' => null]);
            if ($rDal->gte($dal) && ($al === null || $rDal->lte($al))) {
                $giorni[] = $rDal;
            }
        }

        foreach ($giorni as $g) {
            $somma = (float) $nuova['quota'];
            foreach ($coppia as $r) {
                [$rDal, $rAl] = self::estremi(['data_inizio' => $r->data_inizio ?? null, 'data_fine' => $r->data_fine ?? null]);
                if ($rDal->lte($g) && ($rAl === null || $rAl->gte($g))) {
                    $somma += (float) $r->quota;
                }
            }
            if ($somma > 100 + self::TOLLERANZA) {
                return ['giorno' => $g->toDateString(), 'somma' => round($somma, 2)];
            }
        }

        return null;
    }

    /**
     * Guardia 2 per la **modifica di una riga che esiste già** (Web Pro Italia, 13/11/2026): rifiuta solo ciò che peggiora.
     *
     * Il caso: l'importazione, o i dati di prima delle guardie, lasciano su un'unità due proprietari al 100 ciascuno
     * (somma 200). Con la regola della riga nuova non se ne esce: correggere una riga a 50 fa 150 e si rifiuta, correggere
     * l'altra pure — e «Modifica associazione» è l'unico posto in cui quelle quote si correggono. Qui si guarda la somma
     * **prima** della modifica (con la riga com'è adesso) e **dopo**: un giorno ferma la modifica solo se dopo supera 100
     * **e** supera anche quanto c'era prima. Così 200 → 150 → 100 è una strada che si percorre una riga alla volta; una
     * modifica che non tocca la quota (una nota, una data che accorcia il periodo) passa anche sopra 100; una modifica
     * che alza la somma, o che la porta sopra 100 partendo da sotto, si rifiuta come sempre.
     *
     * Il confronto è **giorno per giorno**, come tutta la guardia 2, e non fra il giorno peggiore di prima e quello di
     * dopo: un'unità a 200 nel 2020 e a 100 oggi non autorizza a portare oggi a 200. «Prima», in un giorno, è la somma
     * delle righe attive del ruolo **nuovo** con la riga in modifica contata solo se quel giorno c'era già con quel ruolo:
     * spostarla su un altro ruolo, o allungarne il periodo, su quei giorni è una riga nuova.
     *
     * I giorni da controllare sono quelli di `sforoQuotePerGiorno()` più i due in cui «prima» cambia dentro il periodo
     * nuovo: la decorrenza attuale della riga e il giorno dopo la sua fine attuale.
     *
     * Se la riga in modifica non è fra `$righe` vale la regola della riga nuova.
     *
     * @param Collection<int, object> $righe le righe dell'unità (tutte, compresa quella in modifica com'è adesso)
     * @param array{tipologia: string, quota: float|string, data_inizio: string|CarbonImmutable, data_fine?: string|CarbonImmutable|null} $nuova
     * @return array{giorno: string, somma: float, prima?: float}|null il primo giorno che la modifica peggiora sopra 100, o `null`
     */
    public static function sforoQuoteInModifica(Collection $righe, array $nuova, int $rigaId): ?array
    {
        $attuale = $righe->first(fn ($r) => (int) ($r->id ?? 0) === $rigaId);
        if ($attuale === null) {
            return self::sforoQuotePerGiorno($righe, $nuova, $rigaId);
        }

        foreach (self::sommeInModifica($righe, $nuova, $rigaId, $attuale) as $giorno => [$prima, $dopo]) {
            if ($dopo > 100 + self::TOLLERANZA && $dopo > $prima + self::TOLLERANZA) {
                return ['giorno' => $giorno, 'somma' => round($dopo, 2), 'prima' => round($prima, 2)];
            }
        }

        return null;
    }

    /**
     * La modifica è passata, ma la somma del ruolo resta sopra 100 (Web Pro Italia, 13/11/2026): la frase che lo dice a chi
     * ha appena salvato, con la somma del giorno peggiore dopo la modifica. `null` se in nessun giorno del periodo della
     * riga la somma supera 100. Non è un rifiuto: `sforoQuoteInModifica()` ha già lasciato passare la modifica perché non
     * peggiora; qui si ricorda che il lavoro non è finito.
     *
     * @param Collection<int, object> $righe le righe dell'unità (tutte, compresa quella in modifica)
     */
    public static function avvisoSommaAncoraAlta(Collection $righe, array $nuova, int $rigaId): ?string
    {
        $peggiore = 0.0;
        foreach (self::sommeInModifica($righe, $nuova, $rigaId, null) as [, $dopo]) {
            $peggiore = max($peggiore, $dopo);
        }
        if ($peggiore <= 100 + self::TOLLERANZA) {
            return null;
        }
        $ruolo = mb_strtolower(RuoloAnagraficaImmobile::tryFrom((string) $nuova['tipologia'])?->label() ?? (string) $nuova['tipologia']);

        return "La somma delle quote per {$ruolo} è ancora " . self::cifra($peggiore) . ': correggi anche le altre righe.';
    }

    /**
     * Le due guardie insieme, come errori per campo: le usano la FormRequest di «Associa» e «Modifica» e, sotto lock, il
     * controller che scrive la riga (giro di verifica della 1.11.0-beta.37, C-R4: la FormRequest legge senza lock).
     *
     * Con `$escludiRigaId` è la **modifica** di quella riga, e la guardia 2 rifiuta solo ciò che peggiora
     * (`sforoQuoteInModifica()`, Web Pro Italia, 13/11/2026); senza, è una riga nuova e vale la regola di sempre. Di qui
     * passano solo «Associa», «Modifica associazione» e l'anteprima di un passaggio (che apre righe nuove): i passaggi e il
     * loro annullamento chiamano `sforoQuotePerGiorno()` direttamente, e non cambiano.
     *
     * @return array<string, string>
     */
    public static function errori(Collection $righe, array $nuova, ?int $escludiRigaId = null, string $campoQuota = 'quota'): array
    {
        $errori = [];
        if ($sovrapposta = self::sovrapposizioneStessaPersona($righe, $nuova, $escludiRigaId)) {
            $errori['anagrafica_id'] = self::messaggioSovrapposizione($sovrapposta);
        }
        $sforo = $escludiRigaId !== null
            ? self::sforoQuoteInModifica($righe, $nuova, $escludiRigaId)
            : self::sforoQuotePerGiorno($righe, $nuova);
        if ($sforo) {
            $errori[$campoQuota] = self::messaggioSforo((string) $nuova['tipologia'], $sforo);
        }

        return $errori;
    }

    /** Il messaggio della guardia 2, con il ruolo come lo legge l'amministratore e il giorno dello sforo. */
    public static function messaggioSforo(string $tipologia, array $sforo): string
    {
        $ruolo = mb_strtolower(RuoloAnagraficaImmobile::tryFrom($tipologia)?->label() ?? $tipologia);
        $giorno = CarbonImmutable::parse($sforo['giorno'])->locale('it')->translatedFormat('j F Y');
        $somma = self::cifra((float) $sforo['somma']);

        $frase = "La somma delle quote per {$ruolo} non può superare 100: il {$giorno} farebbe {$somma}.";

        // La modifica rifiutata su un'unità che sopra 100 c'è già (Web Pro Italia, 13/11/2026): senza questa coda la frase
        // sembra dire che il 100 era rispettato, e chi legge non capisce perché abbassare a 150 passa e alzare no.
        if (isset($sforo['prima']) && (float) $sforo['prima'] > 100 + self::TOLLERANZA) {
            $frase .= ' Quel giorno è già ' . self::cifra((float) $sforo['prima']) . ': una modifica può abbassarla, non alzarla.';
        }

        return $frase;
    }

    /** Il messaggio della guardia 1. */
    public static function messaggioSovrapposizione(object $riga): string
    {
        $ruolo = mb_strtolower(RuoloAnagraficaImmobile::tryFrom((string) $riga->tipologia)?->label() ?? (string) $riga->tipologia);
        [$dal, $al] = self::estremi(['data_inizio' => $riga->data_inizio ?? null, 'data_fine' => $riga->data_fine ?? null]);
        $periodo = $al === null
            ? 'dal ' . $dal->locale('it')->translatedFormat('j F Y') . ', in corso'
            : 'dal ' . $dal->locale('it')->translatedFormat('j F Y') . ' al ' . $al->locale('it')->translatedFormat('j F Y');

        return "Questa persona è già {$ruolo} di questa unità {$periodo}: i due periodi si sovrappongono. Chiudi quello esistente, o registra un passaggio.";
    }

    /**
     * Per ogni giorno da controllare nella modifica della riga `$rigaId`, la somma del ruolo nuovo **prima** (con la riga
     * `$attuale` com'è adesso, se quel giorno c'era già con quel ruolo) e **dopo** (con i valori nuovi). Con `$attuale`
     * nullo «prima» conta solo le altre righe, e serve a chi vuole la sola somma di dopo.
     *
     * @return array<string, array{0: float, 1: float}> giorno `Y-m-d` → [prima, dopo], in ordine di giorno
     */
    private static function sommeInModifica(Collection $righe, array $nuova, int $rigaId, ?object $attuale): array
    {
        [$dal, $al] = self::estremi($nuova);
        $coppia = $righe
            ->filter(fn ($r) => self::attiva($r) && (string) $r->tipologia === (string) $nuova['tipologia'])
            ->reject(fn ($r) => (int) ($r->id ?? 0) === $rigaId)
            ->values();
        $nelPeriodo = fn (CarbonImmutable $g) => $g->gte($dal) && ($al === null || $g->lte($al));

        // La riga in modifica conta nel «prima» solo se è attiva e ha già il ruolo nuovo.
        $contaPrima = $attuale !== null && self::attiva($attuale) && (string) $attuale->tipologia === (string) $nuova['tipologia'];
        [$aDal, $aAl] = $contaPrima
            ? self::estremi(['data_inizio' => $attuale->data_inizio ?? null, 'data_fine' => $attuale->data_fine ?? null])
            : [null, null];

        $giorni = [$dal->toDateString() => $dal];
        foreach ($coppia as $r) {
            [$rDal] = self::estremi(['data_inizio' => $r->data_inizio ?? null, 'data_fine' => null]);
            if ($nelPeriodo($rDal)) {
                $giorni[$rDal->toDateString()] = $rDal;
            }
        }
        if ($contaPrima) {
            // I due giorni in cui «prima» cambia: da lì la differenza fra dopo e prima è un'altra.
            foreach (array_filter([$aDal, $aAl?->addDay()]) as $g) {
                if ($nelPeriodo($g)) {
                    $giorni[$g->toDateString()] = $g;
                }
            }
        }
        ksort($giorni);

        $somme = [];
        foreach ($giorni as $chiave => $g) {
            $altre = 0.0;
            foreach ($coppia as $r) {
                [$rDal, $rAl] = self::estremi(['data_inizio' => $r->data_inizio ?? null, 'data_fine' => $r->data_fine ?? null]);
                if ($rDal->lte($g) && ($rAl === null || $rAl->gte($g))) {
                    $altre += (float) $r->quota;
                }
            }
            $cEra = $contaPrima && $aDal->lte($g) && ($aAl === null || $aAl->gte($g));
            $somme[$chiave] = [$altre + ($cEra ? (float) $attuale->quota : 0.0), $altre + (float) $nuova['quota']];
        }

        return $somme;
    }

    /** Una somma di quote come la legge l'amministratore: «150», «133,34». */
    private static function cifra(float $somma): string
    {
        return rtrim(rtrim(number_format($somma, 2, ',', '.'), '0'), ',');
    }

    /** @return array{0: CarbonImmutable, 1: ?CarbonImmutable} */
    private static function estremi(array $riga): array
    {
        $dal = self::giorno($riga['data_inizio'] ?? null) ?? CarbonImmutable::parse('1900-01-01', 'UTC');
        $al = self::giorno($riga['data_fine'] ?? null);

        return [$dal, $al];
    }

    private static function siSovrappongono(CarbonImmutable $aDal, ?CarbonImmutable $aAl, CarbonImmutable $bDal, ?CarbonImmutable $bAl): bool
    {
        $aFine = $aAl ?? CarbonImmutable::parse('9999-12-31', 'UTC');
        $bFine = $bAl ?? CarbonImmutable::parse('9999-12-31', 'UTC');

        return $aDal->lte($bFine) && $bDal->lte($aFine);
    }

    private static function attiva(object $r): bool
    {
        return ! isset($r->attivo) || (bool) $r->attivo;
    }

    private static function giorno(mixed $valore): ?CarbonImmutable
    {
        if ($valore === null || $valore === '') {
            return null;
        }
        $data = $valore instanceof \DateTimeInterface ? $valore->format('Y-m-d') : substr((string) $valore, 0, 10);

        return CarbonImmutable::parse($data, 'UTC')->startOfDay();
    }
}
