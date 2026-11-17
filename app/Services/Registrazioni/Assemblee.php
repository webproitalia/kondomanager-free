<?php

namespace App\Services\Registrazioni;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Il legame con le assemblee, che nascono in un altro lavoro (Web Pro Italia, 17/11/2026): qui si leggono le tabelle `assemblee` e
 * `assemblea_punti` solo se ci sono, e solo per le colonne che servono. Finché `assemblee` non c'è, un `assemblea_id` si accetta così com'è
 * (non c'è niente con cui confrontarlo); quando c'è, deve essere un'assemblea di questo condominio.
 */
class Assemblee
{
    public function ciSono(): bool
    {
        return Schema::hasTable('assemblee');
    }

    /** L'assemblea esiste ed è di questo condominio? Senza la tabella non si può dire di no. */
    public function delCondominio(int $assembleaId, int $condominioId): bool
    {
        if (! $this->ciSono()) {
            return true;
        }

        $query = DB::table('assemblee')->where('id', $assembleaId);

        if (Schema::hasColumn('assemblee', 'condominio_id')) {
            $query->where('condominio_id', $condominioId);
        }

        return $query->exists();
    }

    /**
     * I punti dell'ordine del giorno, in ordine: `[{numero, titolo}]`. Vuoto senza la tabella o senza punti.
     *
     * @return list<array{numero: int, titolo: string}>
     */
    public function punti(?int $assembleaId): array
    {
        if (! $assembleaId || ! Schema::hasTable('assemblea_punti')) {
            return [];
        }

        return DB::table('assemblea_punti')
            ->where('assemblea_id', $assembleaId)
            ->orderBy('numero')
            ->get(['numero', 'titolo'])
            ->map(fn ($p) => ['numero' => (int) $p->numero, 'titolo' => trim((string) $p->titolo)])
            ->values()
            ->all();
    }

    /**
     * Le assemblee del condominio, per il menu «Collega a un'assemblea»: `[{id, nome}]`, le più recenti prima. Il nome si compone con le
     * colonne che la tabella ha (titolo o tipo, e la data); senza, «Assemblea n. …».
     *
     * @return list<array{id: int, nome: string}>
     */
    public function elenco(int $condominioId): array
    {
        if (! $this->ciSono() || ! Schema::hasColumn('assemblee', 'condominio_id')) {
            return [];
        }

        $colonne = Schema::getColumnListing('assemblee');
        $titolo = collect(['titolo', 'nome', 'oggetto', 'tipo'])->first(fn ($c) => in_array($c, $colonne, true));
        $data = collect(['data_prima_convocazione', 'data_seconda_convocazione', 'data', 'data_ora', 'convocata_il', 'created_at'])->first(fn ($c) => in_array($c, $colonne, true));

        return DB::table('assemblee')
            ->where('condominio_id', $condominioId)
            ->orderByDesc($data ?? 'id')
            ->limit(100)
            ->get(array_values(array_filter(['id', $titolo, $data])))
            ->map(function ($a) use ($titolo, $data) {
                $nome = $titolo && trim((string) $a->{$titolo}) !== '' ? ucfirst(trim((string) $a->{$titolo})) : 'Assemblea n. '.$a->id;
                if ($data && $a->{$data}) {
                    $nome .= ' del '.\Carbon\Carbon::parse($a->{$data})->format('d/m/Y');
                }

                return ['id' => (int) $a->id, 'nome' => $nome];
            })
            ->values()
            ->all();
    }

    /**
     * L'assemblea è stata registrata: lo si segna sull'assemblea, così il verbale scrive la frase dell'avviso ai presenti (che chi registra
     * ha confermato prima di partire). Senza la tabella o senza la colonna non fa niente.
     *
     * Con il verbale approvato o in attesa di approvazione il segno non si tocca ({@see verbaleFermo()}): collegare una registrazione non
     * cambia i dati di un'assemblea che qualcuno sta approvando o ha già approvato.
     */
    public function segnaRegistrata(?int $assembleaId): void
    {
        if (! $assembleaId || ! $this->ciSono() || ! Schema::hasColumn('assemblee', 'registrata') || $this->verbaleFermo($assembleaId)) {
            return;
        }

        DB::table('assemblee')->where('id', $assembleaId)->where('registrata', false)->update(['registrata' => true, 'updated_at' => now()]);
    }

    /**
     * Il verbale dell'assemblea è approvato o in attesa di approvazione: da lì niente dell'assemblea si modifica (né il segno «registrata»,
     * né i testi dei punti, dove la bozza della registrazione non entra). Senza la tabella dei verbali non c'è niente di fermo.
     */
    public function verbaleFermo(?int $assembleaId): bool
    {
        if (! $assembleaId || ! Schema::hasTable('verbali') || ! Schema::hasColumn('verbali', 'stato')) {
            return false;
        }

        return DB::table('verbali')->where('assemblea_id', $assembleaId)->whereIn('stato', ['da_approvare', 'approvato'])->exists();
    }

    /**
     * Le registrazioni di un'assemblea, per la sua pagina: `[{id, titolo, stato, iniziata, durata_secondi, bozza}]`, le più recenti prima.
     *
     * @return list<array<string, mixed>>
     */
    public function registrazioniDi(int $assembleaId): array
    {
        return \App\Models\Registrazione::where('assemblea_id', $assembleaId)->orderByDesc('id')->get()
            ->map(fn (\App\Models\Registrazione $r) => [
                'id' => (int) $r->id,
                'titolo' => (string) $r->titolo,
                'stato' => (string) $r->stato,
                'iniziata' => $r->iniziata_at?->toIso8601String(),
                'durata_secondi' => $r->durata_secondi !== null ? (int) $r->durata_secondi : null,
                'bozza' => is_array($r->bozza_punti) && $r->bozza_punti !== [],
                'errore' => $r->errore,
            ])->values()->all();
    }
}
