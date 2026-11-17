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
     * @return array{gruppi: list<array{chiave:string, rappresentante_id:int, titolari:list<int>, candidati:list<int>, unita:list<int>, millesimi:int}>, totale:int, unita_senza_titolari:list<array{id:int, etichetta:string, millesimi:int}>, giorno:string, millesimi_per_unita: array<int, int>, nomi: array<int, string>}
     */
    public function calcola(Assemblea $assemblea): array
    {
        $giorno = CarbonImmutable::parse(($assemblea->giorno() ?? now())->toDateString());

        [$millesimiPerUnita, $totale] = $this->millesimiDellaTabella($assemblea->tabella_id);

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

        return [
            'gruppi' => $fuori, 'totale' => $totale, 'unita_senza_titolari' => $senzaTitolari, 'giorno' => $giorno->toDateString(),
            'millesimi_per_unita' => $millesimiPerUnita, 'nomi' => $nomi,
        ];
    }

    /**
     * I millesimi di ogni unità nella tabella dei conteggi, in centomillesimi, e il loro totale. Le unità escluse
     * valgono zero; senza tabella non c'è niente.
     *
     * @return array{0: array<int, int>, 1: int}
     */
    private function millesimiDellaTabella(?int $tabellaId): array
    {
        $quote = $tabellaId
            ? QuotaTabella::where('tabella_id', $tabellaId)->get(['immobile_id', 'valore', 'escluso'])
            : collect();
        $perUnita = [];
        $totale = 0;
        foreach ($quote as $q) {
            $valore = $q->escluso ? 0 : max(0, Millesimi::daValore($q->valore));
            $perUnita[(int) $q->immobile_id] = $valore;
            $totale += $valore;
        }

        return [$perUnita, $totale];
    }

    /**
     * Scrive (o riallinea) l'elenco delle presenze dell'assemblea.
     *
     * - Un gruppo che c'è già (stesse unità) tiene quello che è stato segnato — presente, delega, orari, rappresentante
     *   scelto — e prende i millesimi di adesso.
     * - Un gruppo nuovo entra come assente.
     * - Una riga che non corrisponde più a nessun gruppo si toglie, se non ha voti. **Con i voti resta ferma**, con i
     *   millesimi ricalcolati sulle sue unità, e si segnala: i gruppi nuovi che coprono le sue stesse unità non entrano
     *   (e le righe senza voti che quei gruppi avrebbero sostituito restano anche loro), così **le stesse unità non
     *   finiscono mai in due righe**, e non si contano due volte. Per riallineare una riga ferma si azzerano i suoi voti
     *   e si prepara di nuovo l'elenco.
     *
     * @return array{aggiunte:int, aggiornate:int, tolte:int, rimaste:list<int>, doppie:list<int>, totale:int, unita_senza_titolari:list<array{id:int, etichetta:string, millesimi:int}>}
     */
    public function prepara(Assemblea $assemblea): array
    {
        $calcolo = $this->calcola($assemblea);

        return DB::transaction(function () use ($assemblea, $calcolo) {
            $gruppi = [];
            foreach ($calcolo['gruppi'] as $g) {
                $gruppi[self::chiaveUnita($g['unita'])] = $g;
            }
            $perUnita = $calcolo['millesimi_per_unita'];
            $nomi = $calcolo['nomi'];

            // Ogni riga cerca il suo gruppo (stesse unità). Se due righe hanno le stesse unità vale quella con i voti, poi la più vecchia.
            $esistenti = AssembleaPresenza::where('assemblea_id', $assemblea->id)->withCount('voti')->get()
                ->sortBy(fn (AssembleaPresenza $p) => [$p->voti_count > 0 ? 0 : 1, (int) $p->id])->values();
            $corrispondenti = [];
            $ferme = [];
            $daTogliere = [];
            foreach ($esistenti as $riga) {
                $chiave = self::chiaveUnita($riga->idUnita());
                if (isset($gruppi[$chiave]) && ! isset($corrispondenti[$chiave])) {
                    $corrispondenti[$chiave] = $riga;
                } elseif ($riga->voti_count > 0) {
                    $ferme[(int) $riga->id] = $riga;
                } else {
                    $daTogliere[(int) $riga->id] = $riga;
                }
            }

            // Le righe ferme tengono le loro unità: i gruppi nuovi che le toccano non entrano, e le righe senza voti che quei
            // gruppi avrebbero sostituito restano (altrimenti le loro unità sparirebbero dall'elenco). Si ripete finché non cambia più niente.
            $saltati = [];
            do {
                $cambiato = false;
                $occupate = [];
                foreach ($ferme as $riga) {
                    $occupate += array_flip($riga->idUnita());
                }
                foreach ($gruppi as $chiave => $g) {
                    if (! isset($corrispondenti[$chiave]) && ! isset($saltati[$chiave]) && array_intersect_key(array_flip($g['unita']), $occupate) !== []) {
                        $saltati[$chiave] = true;
                        $cambiato = true;
                    }
                }
                $scoperte = [];
                foreach (array_keys($saltati) as $chiave) {
                    $scoperte += array_flip($gruppi[$chiave]['unita']);
                }
                foreach ($daTogliere as $id => $riga) {
                    if (array_intersect_key(array_flip($riga->idUnita()), $scoperte) !== []) {
                        $ferme[$id] = $riga;
                        unset($daTogliere[$id]);
                        $cambiato = true;
                    }
                }
            } while ($cambiato);

            $aggiunte = $aggiornate = 0;
            foreach ($gruppi as $chiave => $g) {
                $riga = $corrispondenti[$chiave] ?? null;

                if ($riga) {
                    $dati = ['millesimi' => Millesimi::inDecimale($g['millesimi'])];
                    // Il rappresentante scelto resta, se è ancora fra chi può rappresentare quelle unità.
                    if (! in_array((int) $riga->anagrafica_id, $g['candidati'], true)) {
                        $dati['anagrafica_id'] = $g['rappresentante_id'];
                    }
                    // Il nome fotografato nella riga: quello di chi la rappresenta adesso.
                    $nome = $nomi[$dati['anagrafica_id'] ?? (int) $riga->anagrafica_id] ?? null;
                    if (filled($nome)) {
                        $dati['nome'] = $nome;
                    }
                    $riga->fill($dati)->save();
                    $aggiornate++;
                    continue;
                }
                if (isset($saltati[$chiave])) {
                    continue;
                }

                AssembleaPresenza::create([
                    'assemblea_id' => $assemblea->id,
                    'anagrafica_id' => $g['rappresentante_id'],
                    'nome' => filled($nomi[$g['rappresentante_id']] ?? null) ? $nomi[$g['rappresentante_id']] : null,
                    'tipo' => 'assente',
                    'millesimi' => Millesimi::inDecimale($g['millesimi']),
                    'unita' => $g['unita'],
                ]);
                $aggiunte++;
            }

            foreach ($daTogliere as $riga) {
                $riga->delete();
            }
            // Una riga ferma non resta con i millesimi di una tabella vecchia: valgono quelli di adesso, sulle sue unità.
            foreach ($ferme as $riga) {
                $riga->forceFill(['millesimi' => Millesimi::inDecimale(self::millesimiDelleUnita($riga->idUnita(), $perUnita))])->save();
            }

            $assemblea->forceFill(['millesimi_totali' => Millesimi::inDecimale($calcolo['totale'])])->save();

            return [
                'aggiunte' => $aggiunte, 'aggiornate' => $aggiornate, 'tolte' => count($daTogliere),
                'rimaste' => array_map('intval', array_keys($ferme)), 'doppie' => $this->unitaInPiuRighe($assemblea),
                'totale' => $calcolo['totale'], 'unita_senza_titolari' => $calcolo['unita_senza_titolari'],
            ];
        });
    }

    /**
     * La tabella dei conteggi è cambiata con l'elenco già preparato: i millesimi di ogni riga si ricalcolano subito
     * dalla tabella nuova, sulle unità della riga (la stessa regola di {@see prepara()}), e il valore dell'edificio
     * diventa il totale della tabella nuova. Le righe restano quelle: presenze, deleghe, orari e voti non si toccano, e
     * nessuna riga nasce o sparisce. Per rileggere anche i titolari c'è «Prepara l'elenco».
     *
     * @return array{righe:int, cambiate:int, totale:int}
     */
    public function ricalcolaMillesimi(Assemblea $assemblea): array
    {
        [$perUnita, $totale] = $this->millesimiDellaTabella($assemblea->tabella_id);

        return DB::transaction(function () use ($assemblea, $perUnita, $totale) {
            $righe = AssembleaPresenza::where('assemblea_id', $assemblea->id)->get();
            $cambiate = 0;
            foreach ($righe as $riga) {
                $nuovi = self::millesimiDelleUnita($riga->idUnita(), $perUnita);
                if (Millesimi::daValore($riga->millesimi) !== $nuovi) {
                    $riga->forceFill(['millesimi' => Millesimi::inDecimale($nuovi)])->save();
                    $cambiate++;
                }
            }
            $assemblea->forceFill(['millesimi_totali' => Millesimi::inDecimale($totale)])->save();

            return ['righe' => $righe->count(), 'cambiate' => $cambiate, 'totale' => $totale];
        });
    }

    /**
     * Le unità che compaiono in più di una riga dell'elenco: non dovrebbe succedere mai (i loro millesimi si
     * conterebbero due volte). Un elenco preparato prima di questa correzione può averne: la pagina lo segnala.
     *
     * @return list<int>
     */
    public function unitaInPiuRighe(Assemblea $assemblea): array
    {
        $volte = [];
        foreach (AssembleaPresenza::where('assemblea_id', $assemblea->id)->get(['id', 'unita']) as $riga) {
            foreach (array_unique($riga->idUnita()) as $unita) {
                $volte[$unita] = ($volte[$unita] ?? 0) + 1;
            }
        }

        return array_values(array_map('intval', array_keys(array_filter($volte, fn (int $n) => $n > 1))));
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

    /**
     * La somma dei millesimi di un insieme di unità (quelle che la tabella non ha, o esclude, valgono zero).
     *
     * @param  list<int>  $unita
     * @param  array<int, int>  $perUnita
     */
    private static function millesimiDelleUnita(array $unita, array $perUnita): int
    {
        return array_sum(array_map(fn (int $id) => $perUnita[$id] ?? 0, array_unique(array_map('intval', $unita))));
    }

    /** @param  list<int>  $unita */
    private static function chiaveUnita(array $unita): string
    {
        $unita = array_map('intval', $unita);
        sort($unita);

        return implode('-', $unita);
    }
}
