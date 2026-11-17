<?php

namespace App\Services\Assemblee;

use App\Enums\RuoloAnagraficaImmobile;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\AssembleaPresenza;
use App\Models\Immobile;
use App\Models\QuotaTabella;
use App\Models\TitolaritaImmobile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Chi ha diritto di intervenire all'assemblea, e con quanti millesimi (Web Pro Italia, 17/11/2026).
 *
 * ## La regola scelta (si può cambiare, ed è scritta nella guida della pagina)
 *
 * 1. **Un'unità, un gruppo di proprietari.** Per ogni unità si guardano i titolari di un diritto reale in corso il
 *    giorno dell'assemblea: proprietari e nudi proprietari. L'inquilino non è fra gli aventi diritto.
 * 2. **Le unità con gli stessi proprietari fanno una testa sola**, con la somma dei millesimi: chi ha l'appartamento e
 *    il box è un condòmino, non due.
 * 3. **I comproprietari di un'unità sono una testa sola**, rappresentata da uno di loro (art. 67 c. 2 disp. att.: i
 *    comproprietari hanno diritto a un solo rappresentante, che designano fra loro). Il programma propone quello con
 *    la quota più alta (a parità, il primo in ordine di nome); si cambia dall'elenco delle presenze.
 * 4. Chi ha un'unità da solo e un'altra in comproprietà conta due volte: una per la sua unità, una come comunione.
 * 5. **L'usufrutto**: se sull'unità c'è un usufruttuario, la testa resta del nudo proprietario e l'usufruttuario si può
 *    scegliere come rappresentante. La legge dà all'usufruttuario il voto sugli affari di ordinaria amministrazione e
 *    sul godimento dei servizi comuni, al nudo proprietario le altre delibere (art. 67 disp. att.): su un'assemblea
 *    con soli punti ordinari si sceglie l'usufruttuario. Un'unità con il solo usufruttuario registrato è sua.
 * 6. Un'unità senza titolari in archivio non fa una testa, ma i suoi millesimi restano nel totale (il valore
 *    dell'edificio non cambia perché manca un nome): la pagina la segnala.
 *
 * Le unità che la tabella esclude, o che non ci sono, portano zero millesimi.
 */
final class AventiDiritto
{
    /**
     * Gli aventi diritto calcolati dai titolari, e il totale della tabella.
     *
     * @return array{gruppi: list<array{chiave:string, rappresentante_id:int, titolari:list<int>, candidati:list<int>, unita:list<int>, millesimi:int}>, totale:int, unita_senza_titolari:list<array{id:int, etichetta:string, millesimi:int}>, giorno:string}
     */
    public function calcola(Assemblea $assemblea): array
    {
        $giorno = CarbonImmutable::parse(($assemblea->giorno() ?? now())->toDateString());

        $quote = $assemblea->tabella_id
            ? QuotaTabella::where('tabella_id', $assemblea->tabella_id)->get(['immobile_id', 'valore', 'escluso'])
            : collect();
        $millesimiPerUnita = [];
        $totale = 0;
        foreach ($quote as $q) {
            $valore = $q->escluso ? 0 : max(0, Millesimi::daValore($q->valore));
            $millesimiPerUnita[(int) $q->immobile_id] = $valore;
            $totale += $valore;
        }

        $unita = Immobile::where('condominio_id', $assemblea->condominio_id)->orderBy('id')->get();
        $ruoliTitolari = array_map(fn ($r) => $r->value, RuoloAnagraficaImmobile::titolariDiDirittoReale());

        $righe = TitolaritaImmobile::query()
            ->whereIn('immobile_id', $unita->pluck('id'))
            ->whereIn('tipologia', $ruoliTitolari)
            ->with('anagrafica:id,nome')
            ->get()
            ->filter(fn (TitolaritaImmobile $r) => $r->inCorsoIl($giorno));

        $perUnita = $righe->groupBy('immobile_id');
        $nomi = $righe->mapWithKeys(fn ($r) => [(int) $r->anagrafica_id => (string) ($r->anagrafica?->nome ?? '')])->all();

        $gruppi = [];
        $senzaTitolari = [];

        foreach ($unita as $u) {
            /** @var Collection<int, TitolaritaImmobile> $suoi */
            $suoi = $perUnita->get($u->id, collect());
            $proprietari = $suoi->whereIn('tipologia', [RuoloAnagraficaImmobile::PROPRIETARIO->value, RuoloAnagraficaImmobile::NUDA_PROPRIETA->value]);
            $usufruttuari = $suoi->where('tipologia', RuoloAnagraficaImmobile::USUFRUTTUARIO->value);
            $chiCista = $proprietari->isNotEmpty() ? $proprietari : $usufruttuari;

            if ($chiCista->isEmpty()) {
                if (array_key_exists($u->id, $millesimiPerUnita) || $assemblea->tabella_id === null) {
                    $senzaTitolari[] = ['id' => (int) $u->id, 'etichetta' => $u->etichetta_estesa, 'millesimi' => $millesimiPerUnita[$u->id] ?? 0];
                }
                continue;
            }

            $titolari = $chiCista->pluck('anagrafica_id')->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
            $chiave = implode('-', $titolari);

            if (! isset($gruppi[$chiave])) {
                $gruppi[$chiave] = ['chiave' => $chiave, 'titolari' => $titolari, 'candidati' => [], 'unita' => [], 'millesimi' => 0, 'quote' => []];
            }

            $gruppi[$chiave]['unita'][] = (int) $u->id;
            $gruppi[$chiave]['millesimi'] += $millesimiPerUnita[$u->id] ?? 0;
            $gruppi[$chiave]['candidati'] = array_values(array_unique(array_merge(
                $gruppi[$chiave]['candidati'],
                $suoi->pluck('anagrafica_id')->map(fn ($id) => (int) $id)->all(),
            )));
            foreach ($chiCista as $r) {
                $gruppi[$chiave]['quote'][(int) $r->anagrafica_id] = ($gruppi[$chiave]['quote'][(int) $r->anagrafica_id] ?? 0) + (float) $r->quota;
            }
        }

        $fuori = [];
        foreach ($gruppi as $g) {
            // Il rappresentante proposto: la quota più alta, poi il nome, poi l'id (un ordine che non cambia fra due letture).
            $titolari = $g['titolari'];
            usort($titolari, fn (int $a, int $b) => [-($g['quote'][$a] ?? 0), mb_strtolower($nomi[$a] ?? ''), $a] <=> [-($g['quote'][$b] ?? 0), mb_strtolower($nomi[$b] ?? ''), $b]);
            unset($g['quote']);
            $g['rappresentante_id'] = $titolari[0];
            sort($g['candidati']);
            $fuori[] = $g;
        }

        // L'elenco in ordine di nome del rappresentante, come lo si scorre in sala.
        usort($fuori, fn ($a, $b) => [mb_strtolower($nomi[$a['rappresentante_id']] ?? ''), $a['chiave']] <=> [mb_strtolower($nomi[$b['rappresentante_id']] ?? ''), $b['chiave']]);

        return ['gruppi' => $fuori, 'totale' => $totale, 'unita_senza_titolari' => $senzaTitolari, 'giorno' => $giorno->toDateString()];
    }

    /**
     * Scrive (o riallinea) l'elenco delle presenze dell'assemblea.
     *
     * - Un gruppo che c'è già (stesse unità) tiene quello che è stato segnato — presente, delega, orari, rappresentante
     *   scelto — e prende i millesimi di adesso.
     * - Un gruppo nuovo entra come assente.
     * - Una riga che non corrisponde più a nessun gruppo si toglie, se non ha voti; con i voti resta e si segnala.
     *
     * @return array{aggiunte:int, aggiornate:int, tolte:int, rimaste:list<int>, totale:int, unita_senza_titolari:list<array{id:int, etichetta:string, millesimi:int}>}
     */
    public function prepara(Assemblea $assemblea): array
    {
        $calcolo = $this->calcola($assemblea);

        return DB::transaction(function () use ($assemblea, $calcolo) {
            $esistenti = AssembleaPresenza::where('assemblea_id', $assemblea->id)->withCount('voti')->get()
                ->keyBy(fn (AssembleaPresenza $p) => self::chiaveUnita($p->idUnita()));

            $aggiunte = $aggiornate = $tolte = 0;
            $viste = [];

            foreach ($calcolo['gruppi'] as $g) {
                $chiave = self::chiaveUnita($g['unita']);
                $viste[$chiave] = true;
                $riga = $esistenti->get($chiave);

                if ($riga) {
                    $dati = ['millesimi' => Millesimi::inDecimale($g['millesimi'])];
                    // Il rappresentante scelto resta, se è ancora fra chi può rappresentare quelle unità.
                    if (! in_array((int) $riga->anagrafica_id, $g['candidati'], true)) {
                        $dati['anagrafica_id'] = $g['rappresentante_id'];
                    }
                    $riga->fill($dati)->save();
                    $aggiornate++;
                    continue;
                }

                AssembleaPresenza::create([
                    'assemblea_id' => $assemblea->id,
                    'anagrafica_id' => $g['rappresentante_id'],
                    'tipo' => 'assente',
                    'millesimi' => Millesimi::inDecimale($g['millesimi']),
                    'unita' => $g['unita'],
                ]);
                $aggiunte++;
            }

            $rimaste = [];
            foreach ($esistenti as $chiave => $riga) {
                if (isset($viste[$chiave])) {
                    continue;
                }
                if ($riga->voti_count > 0) {
                    $rimaste[] = (int) $riga->id;
                    continue;
                }
                $riga->delete();
                $tolte++;
            }

            $assemblea->forceFill(['millesimi_totali' => Millesimi::inDecimale($calcolo['totale'])])->save();

            return [
                'aggiunte' => $aggiunte, 'aggiornate' => $aggiornate, 'tolte' => $tolte, 'rimaste' => $rimaste,
                'totale' => $calcolo['totale'], 'unita_senza_titolari' => $calcolo['unita_senza_titolari'],
            ];
        });
    }

    /**
     * Chi può rappresentare le unità di una presenza: i titolari di un diritto reale in corso il giorno dell'assemblea
     * (proprietari, nudi proprietari, usufruttuari).
     *
     * @param  list<int>  $unita
     * @return list<int>
     */
    public function candidati(Assemblea $assemblea, array $unita): array
    {
        if ($unita === []) {
            return [];
        }
        $giorno = CarbonImmutable::parse(($assemblea->giorno() ?? now())->toDateString());
        $ruoli = array_map(fn ($r) => $r->value, RuoloAnagraficaImmobile::titolariDiDirittoReale());

        return TitolaritaImmobile::whereIn('immobile_id', $unita)->whereIn('tipologia', $ruoli)->get()
            ->filter(fn (TitolaritaImmobile $r) => $r->inCorsoIl($giorno))
            ->pluck('anagrafica_id')->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
    }

    /** @param  list<int>  $unita */
    private static function chiaveUnita(array $unita): string
    {
        $unita = array_map('intval', $unita);
        sort($unita);

        return implode('-', $unita);
    }
}
