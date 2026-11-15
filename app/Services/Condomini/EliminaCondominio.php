<?php

namespace App\Services\Condomini;

use App\Models\Condominio;
use App\Support\Agenti\DiscoInProva;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Elimina un condominio CON TUTTO IL SUO CONTENUTO (Web Pro Italia, 15/11/2026).
 *
 * Perché esiste. Il tasto «elimina» normale si ferma, ed è giusto, davanti a un condominio che ha casse, pagamenti
 * o deleghe: la contabilità non deve sparire per un clic. Ma chi carica i condomìni (a mano, da file, o con un
 * assistente AI) sbaglia dei caricamenti, e l'unica strada era cancellare unità, persone e voci una per una: uno
 * studio ne ha cancellate 199 in una sera. Questa è la strada dichiarata per buttare un caricamento e rifarlo.
 *
 * Non è il tasto normale con un vincolo in meno. Chiede di riscrivere il nome del condominio (lo controlla chi
 * chiama: `EliminaCondominioRequest`), a un agente serve un permesso che accende l'amministratore, e prima di
 * cancellare salva una copia: un file con tutte le righe del condominio, più i file dei suoi documenti, in
 * `condomini-eliminati/` sul disco privato, tenuta `GIORNI_COPIA` giorni.
 *
 * L'ordine delle cancellazioni è quello di `CreaCondominioDimostrativoAction::rimuovi()`, derivato dai vincoli
 * del database (dalle foglie alla radice); qui in più ci sono i documenti con i loro file e le comunicazioni,
 * che un condominio dimostrativo non ha. Quel metodo resta com'è: è codice del progetto a monte, coperto dai
 * suoi test, e riguarda solo il condominio dimostrativo.
 */
final class EliminaCondominio
{
    public const CARTELLA = 'condomini-eliminati';

    public const GIORNI_COPIA = 30;

    /** Rubriche condivise fra i condomìni: non si esportano né si cancellano «a cascata», si trattano una per una. */
    private const CONDIVISE = ['users', 'anagrafiche', 'fornitori', 'eventi', 'documenti', 'comunicazioni'];

    /** I numeri del condominio, per dire che cosa sparirebbe prima di farlo. */
    public function riepilogo(Condominio $condominio): array
    {
        $id = $condominio->id;
        $conta = fn (string $tabella) => Schema::hasTable($tabella) ? DB::table($tabella)->where('condominio_id', $id)->count() : 0;

        return [
            'unita'      => $conta('immobili'),
            'persone'    => $conta('anagrafica_condominio'),
            'tabelle'    => $conta('tabelle'),
            'esercizi'   => $conta('esercizi'),
            'piani_rate' => $conta('piani_rate'),
            'fatture'    => $conta('fatture_passive'),
            'pagamenti'  => $conta('pagamenti_fornitori'),
            'movimenti'  => $conta('scritture_contabili'),
            'casse'      => $conta('casse'),
            'segnalazioni' => $conta('segnalazioni'),
            'documenti'  => count($this->documentiSuoi($id, [])),
        ];
    }

    /**
     * @return array{riepilogo: array<string,int>, anteprima: bool, copia: ?string, persone_tolte: int, documenti_tolti: int}
     */
    public function esegui(Condominio $condominio): array
    {
        $id = $condominio->id;
        $riepilogo = $this->riepilogo($condominio);

        // Tutto ciò che pende dal condominio, letto PRIMA di toccare qualcosa: serve alla copia e a sapere
        // quali persone, eventi, comunicazioni e documenti restano senza padrone dopo.
        $righe = $this->raccogli($id);
        $anagrafiche = $this->personeSoloSue($id);
        $eventi = $this->soloSuoi('condominio_evento', 'evento_id', $id);
        $comunicazioni = $this->soloSuoi('comunicazione_condominio', 'comunicazione_id', $id);
        $documenti = $this->documentiSuoi($id, $righe, $comunicazioni);

        $righe['anagrafiche'] = $this->leggi('anagrafiche', $anagrafiche);
        $righe['eventi'] = $this->leggi('eventi', $eventi);
        $righe['comunicazioni'] = $this->leggi('comunicazioni', $comunicazioni);
        $righe['documenti'] = $this->leggi('documenti', $documenti);

        $disco = Storage::disk('local');
        $inAnteprima = $disco instanceof DiscoInProva;
        $copia = $inAnteprima ? null : $this->salvaCopia($condominio, $righe);

        DB::transaction(function () use ($condominio, $id, $anagrafiche, $eventi, $comunicazioni, $documenti) {
            $contiContabili = DB::table('conti_contabili')->where('condominio_id', $id)->pluck('id');
            $scritture = DB::table('scritture_contabili')->where('condominio_id', $id)->pluck('id');
            $fatture = DB::table('fatture_passive')->where('condominio_id', $id)->pluck('id');
            $pagamenti = DB::table('pagamenti_fornitori')->where('condominio_id', $id)->pluck('id');

            // 1. Pagamenti e deleghe F24: bloccano condominio, casse, conti, esercizi e scritture. Una delega può
            //    puntare a un'altra delega dello stesso condominio (RESTRICT): prima si scioglie quel legame.
            $this->togli('riga_f24_pagamento', 'pagamento_fornitore_id', $pagamenti);
            DB::table('pagamenti_fornitori')->where('condominio_id', $id)->delete();
            DB::table('deleghe_f24')->where('condominio_id', $id)->update(['delega_padre_id' => null]);
            DB::table('deleghe_f24')->where('condominio_id', $id)->delete();

            // 2. Le fatture con ciò che ci pende: bloccano esercizi, saldi e voci di spesa.
            $this->togli('fattura_scrittura', 'fattura_passiva_id', $fatture);
            $this->togli('fattura_coperture', 'fattura_passiva_id', $fatture);
            $this->togli('righe_fattura', 'fattura_passiva_id', $fatture);
            DB::table('fatture_passive')->where('condominio_id', $id)->delete();

            // 3. Le scritture contabili e le loro righe.
            $this->togli('righe_scritture', 'scrittura_id', $scritture);
            DB::table('scritture_contabili')->where('condominio_id', $id)->delete();

            // 4. I saldi (bloccano gestioni e passaggi), poi le casse (bloccano i conti contabili).
            DB::table('saldi')->where('condominio_id', $id)->delete();
            DB::table('casse')->where('condominio_id', $id)->delete();

            // 5. I documenti che erano solo suoi: le righe adesso, i file dopo, a transazione chiusa.
            $this->togli('documenti', 'id', $documenti);

            // 6. Il condominio: da qui scendono a cascata unità, tabelle, esercizi, gestioni, piani dei conti,
            //    voci, piani rate, rate, quote, segnalazioni e i collegamenti con persone, eventi e comunicazioni.
            $condominio->delete();

            // 7. Quello che la cascata non porta via: i conti contabili, e ciò che è rimasto senza condominio.
            $this->togli('conti_contabili', 'id', $contiContabili);
            $this->togli('comunicazioni', 'id', $comunicazioni);
            $this->togli('eventi', 'id', $eventi);
            $this->togli('anagrafiche', 'id', $anagrafiche);
        });

        if (! $inAnteprima) {
            $this->spostaFile($righe['documenti'], $copia);
            $this->scartaCopieVecchie();
        }

        return [
            'riepilogo'       => $riepilogo,
            'anteprima'       => $inAnteprima,
            'copia'           => $copia,
            'persone_tolte'   => count($anagrafiche),
            'documenti_tolti' => count($documenti),
        ];
    }

    /**
     * Tutte le righe che appartengono al condominio, tabella per tabella, seguendo i vincoli del database: dal
     * condominio alle tabelle che lo citano, e da quelle alle loro figlie. Si seguono solo i legami «appartiene a»
     * (CASCADE, RESTRICT, NO ACTION); quelli SET NULL sono riferimenti di passaggio (le preferenze di un utente,
     * un lotto di importazione) e la riga resta dov'è.
     *
     * @return array<string, list<array<string,mixed>>>
     */
    private function raccogli(int $id): array
    {
        $legami = $this->legami();
        $visti = ['condomini' => [$id => true]];
        $righe = ['condomini' => $this->leggi('condomini', [$id])];
        $coda = [['condomini', [$id]]];

        while ($coda) {
            [$padre, $nuovi] = array_shift($coda);

            foreach ($legami[$padre] ?? [] as [$figlia, $colonna, $haId]) {
                foreach (array_chunk($nuovi, 500) as $pezzo) {
                    $trovate = DB::table($figlia)->whereIn($colonna, $pezzo)->get()->map(fn ($r) => (array) $r)->all();
                    $aggiunti = [];

                    foreach ($trovate as $riga) {
                        $chiave = $haId ? $riga['id'] : md5(json_encode($riga));

                        if (isset($visti[$figlia][$chiave])) {
                            continue;
                        }

                        $visti[$figlia][$chiave] = true;
                        $righe[$figlia][] = $riga;

                        if ($haId) {
                            $aggiunti[] = $riga['id'];
                        }
                    }

                    if ($aggiunti) {
                        $coda[] = [$figlia, $aggiunti];
                    }
                }
            }
        }

        return $righe;
    }

    /**
     * Per ogni tabella, le tabelle che la citano con un vincolo che non sia SET NULL.
     *
     * @return array<string, list<array{0:string,1:string,2:bool}>>
     */
    private function legami(): array
    {
        $legami = [];

        foreach (Schema::getTables() as $tabella) {
            $nome = $tabella['name'];

            if (in_array($nome, self::CONDIVISE, true)) {
                continue;
            }

            $haId = Schema::hasColumn($nome, 'id');

            foreach (Schema::getForeignKeys($nome) as $vincolo) {
                if (count($vincolo['columns']) !== 1 || ($vincolo['foreign_columns'][0] ?? null) !== 'id') {
                    continue;
                }

                if (strtolower((string) ($vincolo['on_delete'] ?? '')) === 'set null') {
                    continue;
                }

                $legami[$vincolo['foreign_table']][] = [$nome, $vincolo['columns'][0], $haId];
            }
        }

        return $legami;
    }

    /** Le voci di una rubrica condivisa collegate a questo condominio e a nessun altro. */
    private function soloSuoi(string $pivot, string $colonna, int $id): array
    {
        if (! Schema::hasTable($pivot)) {
            return [];
        }

        $suoi = DB::table($pivot)->where('condominio_id', $id)->pluck($colonna)->unique();
        $altrui = DB::table($pivot)->where('condominio_id', '!=', $id)->whereIn($colonna, $suoi)->pluck($colonna)->unique();

        return $suoi->diff($altrui)->values()->all();
    }

    /**
     * Le persone della rubrica che dopo l'eliminazione non apparterrebbero più a nessun condominio. Restano
     * comunque: chi è un utente del programma e chi è intestatario di un'unità di un altro condominio.
     */
    private function personeSoloSue(int $id): array
    {
        $candidate = $this->soloSuoi('anagrafica_condominio', 'anagrafica_id', $id);

        if (! $candidate) {
            return [];
        }

        $altrove = DB::table('anagrafica_immobile')
            ->join('immobili', 'immobili.id', '=', 'anagrafica_immobile.immobile_id')
            ->where('immobili.condominio_id', '!=', $id)
            ->whereIn('anagrafica_immobile.anagrafica_id', $candidate)
            ->pluck('anagrafica_immobile.anagrafica_id')
            ->unique();

        return DB::table('anagrafiche')
            ->whereIn('id', $candidate)
            ->whereNotIn('id', $altrove->isEmpty() ? [0] : $altrove)
            ->whereNull('user_id')
            ->pluck('id')
            ->all();
    }

    /**
     * I documenti che sono solo di questo condominio: quelli dell'archivio collegati a lui e a nessun altro, e
     * quelli allegati a una sua riga (una fattura, una segnalazione, una comunicazione che se ne va con lui).
     */
    private function documentiSuoi(int $id, array $righe, array $comunicazioni = []): array
    {
        if (! Schema::hasTable('documenti')) {
            return [];
        }

        $suoi = collect($this->soloSuoi('condominio_documento', 'documento_id', $id));

        if (! $righe) {
            $righe = ['fatture_passive' => DB::table('fatture_passive')->where('condominio_id', $id)->get(['id'])->map(fn ($r) => (array) $r)->all()];
        }

        $tipi = DB::table('documenti')->whereNotNull('documentable_type')->distinct()->pluck('documentable_type');

        foreach ($tipi as $tipo) {
            $classe = Relation::getMorphedModel($tipo) ?? $tipo;

            if (! class_exists($classe)) {
                continue;
            }

            $tabella = (new $classe)->getTable();
            $ids = $tabella === 'comunicazioni' ? $comunicazioni : array_column($righe[$tabella] ?? [], 'id');

            foreach (array_chunk($ids, 500) as $pezzo) {
                $suoi = $suoi->merge(DB::table('documenti')->where('documentable_type', $tipo)->whereIn('documentable_id', $pezzo)->pluck('id'));
            }
        }

        return $suoi->unique()->values()->all();
    }

    private function leggi(string $tabella, array $ids): array
    {
        $righe = [];

        foreach (array_chunk($ids, 500) as $pezzo) {
            foreach (DB::table($tabella)->whereIn('id', $pezzo)->get() as $riga) {
                $righe[] = (array) $riga;
            }
        }

        return $righe;
    }

    private function togli(string $tabella, string $colonna, iterable $valori): void
    {
        foreach (array_chunk(collect($valori)->all(), 500) as $pezzo) {
            DB::table($tabella)->whereIn($colonna, $pezzo)->delete();
        }
    }

    /** La copia: una cartella con `dati.json.gz` (tutte le righe, tabella per tabella). Restituisce la cartella. */
    private function salvaCopia(Condominio $condominio, array $righe): string
    {
        $cartella = self::CARTELLA.'/'.now()->format('Ymd-His').'-'.$condominio->id.'-'.(Str::slug((string) $condominio->nome) ?: 'condominio');

        $contenuto = gzencode(json_encode([
            'condominio'   => ['id' => $condominio->id, 'nome' => $condominio->nome],
            'eliminato_il' => now()->toIso8601String(),
            'eliminato_da' => auth()->id(),
            'tabelle'      => $righe,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR), 6);

        if (! Storage::disk('local')->put($cartella.'/dati.json.gz', $contenuto)) {
            // Senza copia non si elimina: è l'unica strada per tornare indietro.
            throw new \RuntimeException('Non è stato possibile salvare la copia di sicurezza: il condominio non è stato eliminato.');
        }

        return $cartella;
    }

    /** I file dei documenti eliminati non si buttano subito: vanno nella cartella della copia. */
    private function spostaFile(array $documenti, ?string $cartella): void
    {
        $archivio = app(\App\Services\Documenti\ArchivioDocumenti::class);

        foreach ($documenti as $documento) {
            $path = $documento['path'] ?? null;

            try {
                if (! $archivio->esiste($path)) {
                    continue;
                }

                if ($cartella && $archivio->nomeDisco() === 'local') {
                    $archivio->disco()->move($path, $cartella.'/file/'.basename($path));
                } else {
                    $archivio->elimina($path);
                }
            } catch (Throwable $e) {
                Log::warning('Elimina condominio: file del documento non spostato', ['path' => $path, 'errore' => $e->getMessage()]);
            }
        }
    }

    /** Le copie più vecchie di `GIORNI_COPIA` giorni si buttano: una cancellazione che conserva per sempre non lo è. */
    private function scartaCopieVecchie(): void
    {
        try {
            $disco = Storage::disk('local');
            $limite = now()->subDays(self::GIORNI_COPIA)->getTimestamp();

            foreach ($disco->directories(self::CARTELLA) as $cartella) {
                $file = $cartella.'/dati.json.gz';

                if ($disco->exists($file) && $disco->lastModified($file) < $limite) {
                    $disco->deleteDirectory($cartella);
                }
            }
        } catch (Throwable $e) {
            Log::warning('Elimina condominio: pulizia delle copie vecchie non riuscita', ['errore' => $e->getMessage()]);
        }
    }
}
