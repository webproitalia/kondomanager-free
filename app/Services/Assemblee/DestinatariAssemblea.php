<?php

namespace App\Services\Assemblee;

use App\Enums\RuoloAnagraficaImmobile;
use App\Models\Anagrafica;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\AssembleaPresenza;
use App\Models\Immobile;
use App\Models\TitolaritaImmobile;
use Carbon\CarbonImmutable;

/**
 * A chi vanno le comunicazioni dell'assemblea, e chi firma il foglio delle firme (Web Pro Italia, 17/11/2026).
 *
 * ## Le teste
 *
 * Sono gli aventi diritto di {@see AventiDiritto}: se l'elenco delle presenze è già stato preparato si leggono da lì
 * (con il rappresentante scelto e i millesimi fotografati); se no si calcolano adesso dai titolari delle unità, **senza
 * salvare niente**. Il foglio delle firme ha una riga per testa.
 *
 * ## Le persone che ricevono (scelta del programma, scritta nella guida)
 *
 * La convocazione e il verbale non vanno solo al rappresentante della testa: vanno a **ogni titolare di un diritto
 * reale** sulle sue unità, in corso il giorno dell'assemblea (proprietari, comproprietari, nudi proprietari e
 * usufruttuari). È la scelta prudente: nessuno che abbia titolo resta senza avviso; chi non deve riceverlo si toglie
 * dall'elenco prima dell'invio. Una persona che compare in più teste (l'appartamento da sola, il box in comproprietà)
 * riceve una comunicazione sola, con tutte le sue unità.
 *
 * Per il verbale «agli assenti» contano le teste segnate assenti nell'elenco delle presenze: chi è intervenuto per
 * delega non è assente. Senza l'elenco delle presenze il programma non sa chi era assente, e non lo indovina.
 */
final class DestinatariAssemblea
{
    public const TUTTI = 'tutti';
    public const ASSENTI = 'assenti';

    public function __construct(
        private readonly AventiDiritto $aventi,
        private readonly ConteggiAssemblea $conteggi,
    ) {}

    /**
     * Le teste dell'assemblea, in ordine di nome del rappresentante.
     *
     * @return array{
     *     teste: list<array{rappresentante_id:int, nome:string, contitolari:list<string>, candidati:list<int>, unita:list<int>, etichette:list<string>, millesimi:int, tipo:?string, delegato:?string}>,
     *     totale:int, preparato:bool, unita_senza_titolari:list<array{id:int, etichetta:string, millesimi:int}>
     * }
     */
    public function teste(Assemblea $assemblea): array
    {
        $assemblea->loadMissing(['presenze.anagrafica', 'presenze.delegato']);
        $calcolo = $this->aventi->calcola($assemblea);
        $preparato = $assemblea->presenze->isNotEmpty();

        if ($preparato) {
            $grezze = $assemblea->presenze->map(fn (AssembleaPresenza $p) => [
                'rappresentante_id' => (int) $p->anagrafica_id,
                'unita' => $p->idUnita(),
                'millesimi' => Millesimi::daValore($p->millesimi),
                'tipo' => (string) $p->tipo,
                'delegato' => $p->nomeDelegato(),
                'candidati' => null,
            ])->all();
            $totale = $this->conteggi->totale($assemblea);
        } else {
            $grezze = array_map(fn (array $g) => [
                'rappresentante_id' => (int) $g['rappresentante_id'],
                'unita' => array_map('intval', $g['unita']),
                'millesimi' => (int) $g['millesimi'],
                'tipo' => null,
                'delegato' => null,
                'candidati' => array_map('intval', $g['candidati']),
            ], $calcolo['gruppi']);
            $totale = (int) $calcolo['totale'];
        }

        $idUnita = array_values(array_unique(array_merge([], ...array_map(fn ($g) => $g['unita'], $grezze))));
        $perUnita = $this->titolariPerUnita($assemblea, $idUnita);
        $etichette = Immobile::whereIn('id', $idUnita ?: [0])->get()->mapWithKeys(fn (Immobile $u) => [(int) $u->id => (string) $u->etichetta])->all();

        $idPersone = [];
        foreach ($grezze as $i => $g) {
            if ($g['candidati'] === null) {
                $candidati = [];
                foreach ($g['unita'] as $u) {
                    $candidati = array_merge($candidati, $perUnita[$u] ?? []);
                }
                $grezze[$i]['candidati'] = array_values(array_unique($candidati));
            }
            // Il rappresentante è sempre fra chi riceve, anche se oggi non risulta più fra i titolari (l'elenco è una fotografia).
            if (! in_array($g['rappresentante_id'], $grezze[$i]['candidati'], true)) {
                $grezze[$i]['candidati'][] = $g['rappresentante_id'];
            }
            sort($grezze[$i]['candidati']);
            $idPersone = array_merge($idPersone, $grezze[$i]['candidati']);
        }
        $nomi = Anagrafica::whereIn('id', array_values(array_unique($idPersone)) ?: [0])->pluck('nome', 'id')->map(fn ($n) => trim((string) $n))->all();

        $teste = array_map(function (array $g) use ($nomi, $etichette) {
            $altri = array_values(array_filter($g['candidati'], fn (int $id) => $id !== $g['rappresentante_id']));

            return [
                'rappresentante_id' => $g['rappresentante_id'],
                'nome' => $nomi[$g['rappresentante_id']] ?? 'Persona #'.$g['rappresentante_id'],
                'contitolari' => array_values(array_map(fn (int $id) => $nomi[$id] ?? 'Persona #'.$id, $altri)),
                'candidati' => $g['candidati'],
                'unita' => $g['unita'],
                'etichette' => array_values(array_map(fn (int $id) => $etichette[$id] ?? 'unità '.$id, $g['unita'])),
                'millesimi' => $g['millesimi'],
                'tipo' => $g['tipo'],
                'delegato' => $g['delegato'],
            ];
        }, $grezze);

        usort($teste, fn ($a, $b) => [mb_strtolower($a['nome']), $a['rappresentante_id'], $a['unita']] <=> [mb_strtolower($b['nome']), $b['rappresentante_id'], $b['unita']]);

        return ['teste' => $teste, 'totale' => $totale, 'preparato' => $preparato, 'unita_senza_titolari' => $calcolo['unita_senza_titolari']];
    }

    /**
     * Le persone che ricevono, in ordine di nome: tutte ({@see TUTTI}) o solo quelle delle teste assenti
     * ({@see ASSENTI}; senza l'elenco delle presenze, nessuna).
     *
     * @return list<array{id:int, nome:string, indirizzo:?string, unita:list<string>, millesimi:string, rappresentante:bool, assente:?bool, persona:Anagrafica}>
     */
    public function persone(Assemblea $assemblea, string $chi = self::TUTTI): array
    {
        $letto = $this->teste($assemblea);
        $totale = $letto['totale'];

        $raccolte = [];
        foreach ($letto['teste'] as $t) {
            $assente = $t['tipo'] === null ? null : $t['tipo'] === 'assente';
            foreach ($t['candidati'] as $id) {
                $raccolte[$id] ??= ['unita' => [], 'millesimi' => 0, 'rappresentante' => false, 'assente' => null];
                $raccolte[$id]['unita'] = array_values(array_unique(array_merge($raccolte[$id]['unita'], $t['etichette'])));
                $raccolte[$id]['millesimi'] += $t['millesimi'];
                $raccolte[$id]['rappresentante'] = $raccolte[$id]['rappresentante'] || $id === $t['rappresentante_id'];
                // Assente se lo è almeno una delle sue teste: per quella il verbale gli va comunicato.
                if ($assente !== null) {
                    $raccolte[$id]['assente'] = ($raccolte[$id]['assente'] ?? false) || $assente;
                }
            }
        }

        $anagrafiche = Anagrafica::whereIn('id', array_keys($raccolte) ?: [0])->get()->keyBy('id');
        $persone = [];
        foreach ($raccolte as $id => $r) {
            $persona = $anagrafiche->get($id);
            if ($persona === null || ($chi === self::ASSENTI && $r['assente'] !== true)) {
                continue;
            }
            $persone[] = [
                'id' => (int) $id,
                'nome' => trim((string) $persona->nome),
                'indirizzo' => filled($persona->indirizzo) ? trim((string) $persona->indirizzo) : null,
                'unita' => $r['unita'],
                'millesimi' => Millesimi::testoSuMille($r['millesimi'], $totale),
                'rappresentante' => $r['rappresentante'],
                'assente' => $r['assente'],
                'persona' => $persona,
            ];
        }
        usort($persone, fn ($a, $b) => [mb_strtolower($a['nome']), $a['id']] <=> [mb_strtolower($b['nome']), $b['id']]);

        return $persone;
    }

    /**
     * Per ogni unità, chi ha un diritto reale in corso il giorno dell'assemblea (proprietari, nudi proprietari,
     * usufruttuari): una lettura sola per tutte le unità.
     *
     * @param  list<int>  $idUnita
     * @return array<int, list<int>>
     */
    private function titolariPerUnita(Assemblea $assemblea, array $idUnita): array
    {
        if ($idUnita === []) {
            return [];
        }
        $giorno = CarbonImmutable::parse(($assemblea->giorno() ?? now())->toDateString());
        $ruoli = array_map(fn ($r) => $r->value, RuoloAnagraficaImmobile::titolariDiDirittoReale());

        $perUnita = [];
        foreach (TitolaritaImmobile::whereIn('immobile_id', $idUnita)->whereIn('tipologia', $ruoli)->get() as $riga) {
            if ($riga->inCorsoIl($giorno)) {
                $perUnita[(int) $riga->immobile_id][] = (int) $riga->anagrafica_id;
            }
        }

        return $perUnita;
    }
}
