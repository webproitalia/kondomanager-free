<?php

namespace App\Services\Assemblee;

use App\Enums\MaggioranzaAssemblea;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\AssembleaPresenza;
use App\Models\Assemblee\AssembleaPunto;
use App\Models\User;
use App\Settings\PrintSettings;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

/**
 * Teste e millesimi dell'assemblea: la costituzione, i voti di ogni punto, gli avvisi (Web Pro Italia, 17/11/2026).
 *
 * Il programma CALCOLA e PROPONE; l'esito di un punto lo conferma la persona. Tutti i confronti sono fra interi
 * (`Millesimi`): «almeno due terzi del valore» è `3 × presenti ≥ 2 × totale`, senza arrotondamenti.
 *
 * ## Le regole (art. 1136 c.c.)
 *
 * Costituzione:
 * - prima convocazione: la maggioranza dei partecipanti al condominio (più della metà delle teste) e almeno due terzi
 *   del valore dell'edificio;
 * - seconda convocazione: almeno un terzo dei partecipanti e almeno un terzo del valore.
 *
 * Voti: «la maggioranza degli intervenuti» è più della metà delle teste che hanno preso parte alla votazione di quel
 * punto, astenuti compresi (un astenuto non è un favorevole). Il valore si conta sull'intero edificio, non sui
 * presenti. Le frazioni del valore di ogni maggioranza stanno in `MaggioranzaAssemblea::quotaDelValore()`.
 *
 * ## Le scelte (scritte anche nella guida, e modificabili)
 *
 * - Gli intervenuti di un punto sono chi ha un voto registrato su quel punto. Chi è in sala e non ha un voto è
 *   segnalato, non contato: il pulsante «tutti i presenti favorevoli» li registra tutti in un colpo.
 * - La costituzione si conta su chi è in sala adesso (presente o per delega, e non uscito); il verbale la conta su chi
 *   c'era all'ora d'inizio.
 */
final class ConteggiAssemblea
{
    /** @var array<string, bool>|null */
    private ?array $nomiAmministratore = null;

    /** Il valore dell'edificio: la fotografia presa preparando l'elenco, o il totale delle presenze se manca. */
    public function totale(Assemblea $assemblea): int
    {
        if ($assemblea->millesimi_totali !== null) {
            return Millesimi::daValore($assemblea->millesimi_totali);
        }

        return $assemblea->presenze->sum(fn (AssembleaPresenza $p) => Millesimi::daValore($p->millesimi));
    }

    /**
     * La costituzione dell'assemblea per un insieme di presenze (di serie: chi è in sala adesso).
     *
     * @param  Collection<int, AssembleaPresenza>|null  $presenti
     */
    public function costituzione(Assemblea $assemblea, ?Collection $presenti = null): array
    {
        $tutte = $assemblea->presenze;
        $presenti ??= $tutte->filter(fn (AssembleaPresenza $p) => $p->inSala());
        $totale = $this->totale($assemblea);
        $testeTotali = $tutte->count();
        $teste = $presenti->count();
        $millesimi = $presenti->sum(fn (AssembleaPresenza $p) => Millesimi::daValore($p->millesimi));
        $convocazione = $assemblea->convocazione();

        $perConvocazione = [];
        foreach (Assemblea::CONVOCAZIONI as $c) {
            $perConvocazione[$c] = self::valida($c, $teste, $testeTotali, $millesimi, $totale);
        }

        return [
            'convocazione'       => $convocazione,
            'convocazione_scelta'=> $assemblea->convocazione_tenuta !== null,
            'teste_totali'       => $testeTotali,
            'teste_presenti'     => $teste,
            'teste_di_persona'   => $presenti->where('tipo', 'presente')->count(),
            'teste_per_delega'   => $presenti->where('tipo', 'delega')->count(),
            'millesimi_presenti' => $millesimi,
            'millesimi_totali'   => $totale,
            'millesimi_presenti_testo' => Millesimi::testoSuMille($millesimi, $totale),
            'millesimi_valore_testo'   => Millesimi::testoValore($millesimi),
            'millesimi_totali_testo'   => Millesimi::testoValore($totale),
            'valida'             => $perConvocazione[$convocazione],
            'per_convocazione'   => $perConvocazione,
            'requisiti'          => self::requisitiCostituzione($convocazione),
            'mancano'            => $perConvocazione[$convocazione] ? null : self::mancanoPerCostituzione($convocazione, $teste, $testeTotali, $millesimi, $totale),
        ];
    }

    /** Le presenze all'ora d'inizio: chi era presente o per delega e non è arrivato dopo. Senza ora d'inizio, tutti gli intervenuti. */
    public function presentiAllApertura(Assemblea $assemblea): Collection
    {
        $inizio = self::ora($assemblea->ora_inizio);

        return $assemblea->presenze->filter(function (AssembleaPresenza $p) use ($inizio) {
            if (! $p->intervenuto()) {
                return false;
            }
            $arrivo = self::ora($p->ora_arrivo);
            $uscita = self::ora($p->ora_uscita);

            return ($inizio === null || $arrivo === null || $arrivo <= $inizio)
                && ($inizio === null || $uscita === null || $uscita > $inizio);
        })->values();
    }

    public static function valida(string $convocazione, int $teste, int $testeTotali, int $millesimi, int $totale): bool
    {
        if ($testeTotali <= 0 || $totale <= 0) {
            return false;
        }

        return $convocazione === 'seconda'
            ? Millesimi::almeno($teste, $testeTotali, 1, 3) && Millesimi::almeno($millesimi, $totale, 1, 3)
            : Millesimi::oltre($teste, $testeTotali, 1, 2) && Millesimi::almeno($millesimi, $totale, 2, 3);
    }

    public static function requisitiCostituzione(string $convocazione): string
    {
        return $convocazione === 'seconda'
            ? 'almeno un terzo dei condòmini e un terzo del valore dell\'edificio (333,33 millesimi), art. 1136 c. 3 c.c.'
            : 'più della metà dei condòmini e almeno due terzi del valore dell\'edificio (666,67 millesimi), art. 1136 c. 1 c.c.';
    }

    private static function mancanoPerCostituzione(string $convocazione, int $teste, int $testeTotali, int $millesimi, int $totale): ?string
    {
        if ($testeTotali <= 0) {
            return 'Manca l\'elenco degli aventi diritto: preparalo dalle presenze.';
        }
        if ($totale <= 0) {
            return 'La tabella scelta non ha millesimi: scegli la tabella giusta e prepara di nuovo l\'elenco.';
        }

        [$tn, $td, $mn, $md, $testeStrette] = $convocazione === 'seconda' ? [1, 3, 1, 3, false] : [1, 2, 2, 3, true];
        // Le teste che servono: più della metà (prima) o almeno un terzo (seconda), in interi.
        $testeServono = $testeStrette ? intdiv($testeTotali * $tn, $td) + 1 : intdiv($testeTotali * $tn + $td - 1, $td);
        $millesimiServono = intdiv($totale * $mn + $md - 1, $md);

        $parti = [];
        if ($teste < $testeServono) {
            $n = $testeServono - $teste;
            $parti[] = $n === 1 ? '1 condòmino' : "{$n} condòmini";
        }
        if ($millesimi < $millesimiServono) {
            $parti[] = Millesimi::testoCentesimi(max(1, Millesimi::suMilleInCentesimi($millesimiServono - $millesimi, $totale))).' millesimi';
        }

        return $parti === [] ? null : 'Mancano '.implode(' e ', $parti).'.';
    }

    /**
     * I voti di un punto: favorevoli, contrari e astenuti in teste e millesimi, con i nomi; se la maggioranza è
     * raggiunta e l'esito che il programma propone.
     */
    public function votazione(Assemblea $assemblea, AssembleaPunto $punto): array
    {
        $maggioranza = $punto->maggioranzaEnum();
        $convocazione = $assemblea->convocazione();
        $totale = $this->totale($assemblea);
        $testeTotali = $assemblea->presenze->count();
        $presenze = $assemblea->presenze->keyBy('id');
        $voti = $punto->relationLoaded('voti') ? $punto->voti : $punto->voti()->get();

        $gruppi = ['favorevole' => [], 'contrario' => [], 'astenuto' => []];
        foreach ($voti as $v) {
            $p = $presenze->get($v->assemblea_presenza_id);
            if ($p && isset($gruppi[$v->voto])) {
                $gruppi[$v->voto][] = $p;
            }
        }

        $riassunto = function (array $lista) use ($totale) {
            $millesimi = array_sum(array_map(fn (AssembleaPresenza $p) => Millesimi::daValore($p->millesimi), $lista));

            return [
                'teste' => count($lista),
                'millesimi' => $millesimi,
                'millesimi_testo' => Millesimi::testoSuMille($millesimi, $totale),
                'nomi' => array_map(fn (AssembleaPresenza $p) => [
                    'presenza_id' => (int) $p->id,
                    'nome' => self::nomeDi($p),
                    'delegato' => $p->nomeDelegato(),
                    'millesimi_testo' => Millesimi::testoSuMille(Millesimi::daValore($p->millesimi), $totale),
                ], $lista),
            ];
        };

        $favorevoli = $riassunto($gruppi['favorevole']);
        $contrari = $riassunto($gruppi['contrario']);
        $astenuti = $riassunto($gruppi['astenuto']);
        $intervenutiTeste = $favorevoli['teste'] + $contrari['teste'] + $astenuti['teste'];
        $intervenutiMillesimi = $favorevoli['millesimi'] + $contrari['millesimi'] + $astenuti['millesimi'];

        $conVoto = $voti->pluck('assemblea_presenza_id')->map(fn ($id) => (int) $id)->all();
        $senzaVoto = $assemblea->presenze->filter(fn (AssembleaPresenza $p) => $p->inSala() && ! in_array((int) $p->id, $conVoto, true))
            ->map(fn (AssembleaPresenza $p) => (int) $p->id)->values()->all();

        $quota = $maggioranza->quotaDelValore($convocazione);
        $testeOk = $valoreOk = false;
        if ($quota !== null && $intervenutiTeste > 0) {
            $testeOk = $maggioranza === MaggioranzaAssemblea::UNANIMITA
                ? $favorevoli['teste'] >= $testeTotali && $testeTotali > 0
                : Millesimi::oltre($favorevoli['teste'], $intervenutiTeste, 1, 2);
            $valoreOk = $totale > 0 && Millesimi::almeno($favorevoli['millesimi'], $totale, $quota[0], $quota[1]);
        }
        $raggiunta = $testeOk && $valoreOk;

        $proposto = match (true) {
            $quota === null => 'informativa',
            $intervenutiTeste === 0 => null,
            $raggiunta => 'approvato',
            default => 'respinto',
        };

        return [
            'punto_id'      => (int) $punto->id,
            'maggioranza'   => $maggioranza->value,
            'informativa'   => $quota === null,
            'regola'        => $maggioranza->regola($convocazione),
            'favorevoli'    => $favorevoli,
            'contrari'      => $contrari,
            'astenuti'      => $astenuti,
            'intervenuti'   => ['teste' => $intervenutiTeste, 'millesimi' => $intervenutiMillesimi, 'millesimi_testo' => Millesimi::testoSuMille($intervenutiMillesimi, $totale)],
            'senza_voto'    => $senzaVoto,
            'teste_ok'      => $testeOk,
            'valore_ok'     => $valoreOk,
            'raggiunta'     => $raggiunta,
            'esito_proposto'=> $proposto,
            'esito_diverso' => $punto->esito !== null && $proposto !== null && $punto->esito !== $proposto && $punto->esito !== 'rinviato',
            'spiegazione'   => self::spiegazione($maggioranza, $convocazione, $favorevoli, $intervenutiTeste, $testeTotali, $totale, $testeOk, $valoreOk),
        ];
    }

    private static function spiegazione(MaggioranzaAssemblea $m, string $convocazione, array $favorevoli, int $intervenuti, int $testeTotali, int $totale, bool $testeOk, bool $valoreOk): string
    {
        if ($m === MaggioranzaAssemblea::INFORMATIVA) {
            return 'Punto informativo: non si vota.';
        }
        if ($intervenuti === 0) {
            return 'Nessun voto registrato.';
        }

        $quota = $m->quotaDelValore($convocazione);
        $soglia = Millesimi::testoCentesimi(intdiv(2 * 100000 * $quota[0] + $quota[1], 2 * $quota[1]));
        $teste = $m === MaggioranzaAssemblea::UNANIMITA
            ? "favorevoli {$favorevoli['teste']} condòmini su {$testeTotali} (servono tutti)"
            : "favorevoli {$favorevoli['teste']} su {$intervenuti} intervenuti (ne servono più della metà)";
        $valore = "{$favorevoli['millesimi_testo']} millesimi su 1.000 (ne servono almeno {$soglia})";

        return ucfirst($teste).' '.($testeOk ? '✓' : '✗').'; '.$valore.' '.($valoreOk ? '✓' : '✗').'.';
    }

    /**
     * Gli avvisi della pagina: non fermano niente, dicono che cosa guardare.
     *
     * @return list<array{tipo:string, testo:string}>
     */
    public function avvisi(Assemblea $assemblea): array
    {
        $avvisi = [];
        $presenze = $assemblea->presenze;
        $totale = $this->totale($assemblea);

        if ($assemblea->tabella_id === null) {
            $avvisi[] = ['tipo' => 'warning', 'testo' => 'Scegli la tabella dei millesimi su cui contare costituzione e voti (di norma la generale di proprietà).'];
        }
        if ($presenze->isEmpty()) {
            $avvisi[] = ['tipo' => 'info', 'testo' => 'L\'elenco degli aventi diritto non è ancora pronto: preparalo dai titolari delle unità.'];

            return $avvisi;
        }
        if ($assemblea->convocazione_tenuta === null && $assemblea->stato === 'in_corso') {
            $avvisi[] = ['tipo' => 'warning', 'testo' => 'Scegli se l\'assemblea si tiene in prima o in seconda convocazione: finché non lo scegli i conti seguono la prima, che chiede di più.'];
        }

        $somma = $presenze->sum(fn (AssembleaPresenza $p) => Millesimi::daValore($p->millesimi));
        if ($totale > 0 && $somma < $totale) {
            $avvisi[] = ['tipo' => 'info', 'testo' => 'Le unità senza un titolare in archivio valgono '.Millesimi::testoSuMille($totale - $somma, $totale).' millesimi: non hanno una testa nell\'elenco, ma il loro valore resta nel totale dell\'edificio.'];
        }
        if ($totale > 0 && $totale !== 1000 * Millesimi::SCALA) {
            $avvisi[] = ['tipo' => 'info', 'testo' => 'La tabella somma '.Millesimi::testoValore($totale).' e non 1.000: i conteggi la riportano a mille.'];
        }

        foreach ($presenze as $p) {
            if ($p->tipo === 'delega' && $p->nomeDelegato() === null) {
                $avvisi[] = ['tipo' => 'warning', 'testo' => self::nomeDi($p).' è segnato per delega, ma manca il nome del delegato.'];
            }
            if ($p->tipo === 'delega' && $this->eAmministratore($p)) {
                $avvisi[] = ['tipo' => 'error', 'testo' => 'Il delegato di '.self::nomeDi($p).' sembra essere l\'amministratore: all\'amministratore non si possono conferire deleghe (art. 67 disp. att. c.c.).'];
            }
        }

        // Più di venti condòmini: un delegato non può rappresentarne più di un quinto, né più di un quinto del valore.
        $testeTotali = $presenze->count();
        if ($testeTotali > 20 && $totale > 0) {
            $perDelegato = $presenze->filter(fn (AssembleaPresenza $p) => $p->tipo === 'delega' && $p->nomeDelegato() !== null)
                ->groupBy(fn (AssembleaPresenza $p) => $p->delegato_anagrafica_id ? 'id:'.$p->delegato_anagrafica_id : 'nome:'.mb_strtolower(Str::squish((string) $p->delegato_nome)));
            foreach ($perDelegato as $deleghe) {
                $teste = $deleghe->count();
                $millesimi = $deleghe->sum(fn (AssembleaPresenza $p) => Millesimi::daValore($p->millesimi));
                if (Millesimi::oltre($teste, $testeTotali, 1, 5) || Millesimi::oltre($millesimi, $totale, 1, 5)) {
                    $avvisi[] = ['tipo' => 'warning', 'testo' => $deleghe->first()->nomeDelegato().' rappresenta per delega '.$teste.' condòmini e '.Millesimi::testoSuMille($millesimi, $totale).' millesimi: con più di venti condòmini un delegato non può rappresentarne più di un quinto, né più di un quinto del valore (art. 67 disp. att. c.c.).'];
                }
            }
        }

        foreach ($assemblea->punti as $punto) {
            if ($punto->informativo()) {
                continue;
            }
            $v = $this->votazione($assemblea, $punto);
            if ($v['esito_diverso']) {
                $avvisi[] = ['tipo' => 'warning', 'testo' => "Punto {$punto->numero}: l'esito confermato («{$punto->esito}») non è quello che i voti danno adesso («{$v['esito_proposto']}»)."];
            }
        }

        return $avvisi;
    }

    /**
     * Il delegato è l'amministratore? Lo è se in rubrica ha l'accesso di un amministratore, o se il nome è quello dello
     * studio nelle impostazioni di stampa o di un utente amministratore. È un avviso, non un blocco: il nome può
     * coincidere per caso.
     */
    public function eAmministratore(AssembleaPresenza $p): bool
    {
        $delegato = $p->delegato;
        try {
            if ($delegato?->user_id && User::find($delegato->user_id)?->hasRole(\App\Enums\Role::AMMINISTRATORE->value)) {
                return true;
            }
        } catch (Throwable) {
            // Un ruolo che a database non c'è: il controllo sul nome basta.
        }

        $nome = self::normalizza($p->nomeDelegato());

        return $nome !== '' && isset($this->nomiAmministratore()[$nome]);
    }

    /** @return array<string, bool> */
    private function nomiAmministratore(): array
    {
        if ($this->nomiAmministratore !== null) {
            return $this->nomiAmministratore;
        }

        $nomi = [];
        try {
            $studio = self::normalizza(app(PrintSettings::class)->studio_nome);
            if ($studio !== '') {
                $nomi[$studio] = true;
            }
        } catch (Throwable) {
        }
        try {
            foreach (User::role(\App\Enums\Role::AMMINISTRATORE->value)->pluck('name') as $nome) {
                $n = self::normalizza($nome);
                if ($n !== '') {
                    $nomi[$n] = true;
                }
            }
        } catch (Throwable) {
        }

        return $this->nomiAmministratore = $nomi;
    }

    private static function normalizza(?string $nome): string
    {
        return mb_strtolower(Str::squish(Str::ascii((string) $nome)));
    }

    /** Il nome di chi rappresenta la presenza. */
    public static function nomeDi(AssembleaPresenza $p): string
    {
        return trim((string) ($p->anagrafica?->nome ?? 'Persona #'.$p->anagrafica_id));
    }

    /** «18:30» da un orario del database («18:30:00»), o null. */
    public static function ora(?string $ora): ?string
    {
        return $ora !== null && $ora !== '' ? substr($ora, 0, 5) : null;
    }
}
