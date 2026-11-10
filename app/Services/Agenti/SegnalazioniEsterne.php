<?php

namespace App\Services\Agenti;

use App\Models\Anagrafica;
use App\Models\CollegamentoEsterno;
use App\Models\Commento;
use App\Models\Condominio;
use App\Models\Segnalazione;
use App\Models\User;
use App\Services\CommentoEventoService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Le segnalazioni che arrivano da un sistema esterno (Web Pro Italia, 10/11/2026), per esempio l'app dei condòmini.
 *
 * Il sistema esterno manda OGNI VOLTA lo stato intero della sua segnalazione (testo, stato, urgenza, tutte le
 * risposte) e riceve indietro lo stato del gestionale: una chiamata sola, ripetibile quante volte si vuole, che
 * rimette in pari le due parti. Niente doppioni: ogni oggetto è legato dal suo riferimento (CollegamentoEsterno).
 *
 * Chi vince quando le due parti non coincidono:
 *  - testo e titolo: il sistema esterno, ma solo quando LÀ sono cambiati (qui una correzione a mano resta);
 *  - stato e urgenza: chi li ha cambiati dopo l'ultima sincronizzazione. Il sistema esterno manda il valore di
 *    adesso e quello dell'ultima volta (`*_base`): se sono diversi li ha cambiati lui e si applicano qui;
 *    se sono uguali vale quello del gestionale, che torna nella risposta;
 *  - le risposte si sommano: quelle nuove di là diventano commenti qui, quelle scritte qui tornano nella risposta.
 *
 * Vocabolario della chiamata: stato `aperta` · `in_lavorazione` · `chiusa`; urgenza sì/no.
 * Nel gestionale: stato `aperta` · `in lavorazione` · `chiusa`; priorità bassa/media/alta/urgente.
 */
class SegnalazioniEsterne
{
    /** Vero mentre si scrive per conto del sistema esterno: l'avviso in uscita non parte (sarebbe un'eco). */
    public static bool $inCorso = false;

    public function __construct(private CommentoEventoService $inbox) {}

    public function sincronizza(string $sorgente, string $riferimento, array $d, User $utente): array
    {
        return DB::transaction(function () use ($sorgente, $riferimento, $d, $utente) {
            self::$inCorso = true;

            try {
                return $this->esegui($sorgente, $riferimento, $d, $utente);
            } finally {
                self::$inCorso = false;
            }
        });
    }

    private function esegui(string $sorgente, string $riferimento, array $d, User $utente): array
    {
        $tipo = (new Segnalazione)->getMorphClass();
        $legame = CollegamentoEsterno::where('sorgente', $sorgente)->where('collegabile_type', $tipo)
            ->where('riferimento', $riferimento)->lockForUpdate()->first();

        if ($legame?->eliminato_at) {
            return ['scollegata' => true, 'motivo' => 'La segnalazione è stata eliminata nel gestionale.'];
        }

        $segnalazione = $legame ? Segnalazione::find($legame->collegabile_id) : null;

        if ($legame && ! $segnalazione) {
            $legame->update(['eliminato_at' => now()]);

            return ['scollegata' => true, 'motivo' => 'La segnalazione è stata eliminata nel gestionale.'];
        }

        $testo = $this->componi($d);
        $impronta = md5(($d['titolo'] ?? '')."\n".$testo);
        $creata = false;
        $persona = null;

        // ADOZIONE: la segnalazione è nata qui e il sistema esterno, che l'ha ricevuta con l'avviso «segnalazione.nata»,
        // ora la lega al suo riferimento. Il testo resta quello scritto qui (l'impronta si registra, non si applica).
        if (! $segnalazione && ! empty($d['kondo_id'])) {
            $nata = Segnalazione::find((int) $d['kondo_id']);
            $giaLegata = $nata && CollegamentoEsterno::where('sorgente', $sorgente)->where('collegabile_type', $tipo)
                ->where('collegabile_id', $nata->id)->whereNull('eliminato_at')->exists();

            if (! $nata || $giaLegata) {
                return ['scollegata' => true, 'motivo' => $nata ? 'La segnalazione è già collegata a un altro riferimento.' : 'La segnalazione non c\'è più nel gestionale.'];
            }

            $segnalazione = $nata;
            $legame = CollegamentoEsterno::create([
                'sorgente' => $sorgente, 'riferimento' => $riferimento, 'collegabile_type' => $tipo,
                'collegabile_id' => $segnalazione->id, 'dati' => ['testo' => $impronta, 'link' => $d['link'] ?? null, 'nata_qui' => true],
            ]);
        }

        if (! $segnalazione) {
            if (! empty($d['eliminata'])) {
                return ['scollegata' => true, 'motivo' => 'Mai arrivata nel gestionale.'];
            }

            $condominio = $this->condominio($d['condominio'] ?? []);
            $persona = $this->persona($condominio, $d['persona'] ?? []);

            $segnalazione = new Segnalazione([
                'subject'       => Str::limit((string) $d['titolo'], 250, '…'),
                'description'   => $testo,
                'created_by'    => $utente->id,
                'updated_by'    => $utente->id,
                'condominio_id' => $condominio->id,
                'priority'      => ! empty($d['urgente']) ? 'urgente' : 'media',
                'is_published'  => false,
                'is_approved'   => true,
                'is_private'    => false,
                'is_locked'     => false,
                'is_featured'   => false,
                'can_comment'   => true,
            ]);
            $this->applicaStato($segnalazione, $d['stato'] ?? 'aperta');
            if (! empty($d['creata'])) {
                $segnalazione->created_at = Carbon::parse($d['creata'])->setTimezone(config('app.timezone'));
            }
            $segnalazione->save();

            if ($persona) {
                $segnalazione->anagrafiche()->syncWithoutDetaching([$persona->id]);
            }

            $legame = CollegamentoEsterno::create([
                'sorgente' => $sorgente, 'riferimento' => $riferimento, 'collegabile_type' => $tipo,
                'collegabile_id' => $segnalazione->id, 'dati' => ['testo' => $impronta, 'link' => $d['link'] ?? null],
            ]);
            $creata = true;
        } else {
            $cambi = [];

            // Testo e titolo: solo se sono cambiati nel sistema esterno dall'ultima volta.
            if (($legame->dati['testo'] ?? null) !== $impronta) {
                $cambi['subject'] = Str::limit((string) $d['titolo'], 250, '…');
                $cambi['description'] = $testo;
            }

            // Stato: lo ha cambiato il sistema esterno dopo l'ultima sincronizzazione?
            $stato = $d['stato'] ?? null;
            if ($stato !== null && $stato !== ($d['stato_base'] ?? null) && $stato !== self::statoDi($segnalazione)) {
                $this->applicaStato($segnalazione, $stato);
            }

            if (! empty($d['eliminata']) && self::statoDi($segnalazione) !== 'chiusa') {
                $this->applicaStato($segnalazione, 'chiusa');
            }

            // Urgenza: stessa regola.
            if (array_key_exists('urgente', $d) && $d['urgente'] !== null) {
                $urgente = (bool) $d['urgente'];
                $base = array_key_exists('urgente_base', $d) && $d['urgente_base'] !== null ? (bool) $d['urgente_base'] : null;
                if ($urgente !== $base && $urgente !== self::urgente($segnalazione)) {
                    $cambi['priority'] = $urgente ? 'urgente' : 'media';
                }
            }

            $segnalazione->fill($cambi);
            if ($segnalazione->isDirty()) {
                $segnalazione->updated_by = $utente->id;
                $segnalazione->save();
            }

            $legame->update(['dati' => array_merge($legame->dati ?? [], ['testo' => $impronta, 'link' => $d['link'] ?? ($legame->dati['link'] ?? null)])]);
        }

        $commenti = $this->commenti($sorgente, $segnalazione, $d['commenti'] ?? [], $utente);

        return [
            'id'              => $segnalazione->id,
            'creata'          => $creata,
            'stato'           => self::statoDi($segnalazione),
            'urgente'         => self::urgente($segnalazione),
            'priorita'        => $segnalazione->priority,
            'condominio'      => ['id' => $segnalazione->condominio_id],
            'persona_trovata' => $persona ? ['id' => $persona->id, 'nome' => $persona->nome] : null,
            'url'             => route('admin.segnalazioni.show', $segnalazione->id),
            'commenti'        => $commenti,
        ];
    }

    /** Lo stato della segnalazione nel vocabolario della chiamata. */
    public static function statoDi(Segnalazione $s): string
    {
        // Conta solo `stato`: `is_resolved` nel gestionale non lo usa nessuna schermata («risolvi» lì blocca la
        // segnalazione, `is_locked`), e seguirlo impedirebbe di riaprire dal modulo una segnalazione chiusa.
        return match ($s->stato) {
            'chiusa'         => 'chiusa',
            'in lavorazione' => 'in_lavorazione',
            default          => 'aperta',
        };
    }

    public static function urgente(Segnalazione $s): bool
    {
        return in_array($s->priority, ['alta', 'urgente'], true);
    }

    private function applicaStato(Segnalazione $s, string $stato): void
    {
        $s->stato = match ($stato) {
            'chiusa'         => 'chiusa',
            'in_lavorazione' => 'in lavorazione',
            default          => 'aperta',
        };
    }

    /** Il testo della segnalazione nel gestionale: quello scritto dalla persona, poi chi è e da dove arriva. */
    private function componi(array $d): string
    {
        $p = $d['persona'] ?? [];
        $righe = [trim((string) ($d['testo'] ?? '')), '', '—'];

        $chi = trim((string) ($p['nome'] ?? ''));
        if ($chi !== '') {
            $righe[] = 'Da: '.$chi.(! empty($p['interno']) ? ' · int. '.$p['interno'] : '');
        }
        $contatti = array_filter([! empty($p['telefono']) ? 'Telefono: '.$p['telefono'] : null, ! empty($p['email']) ? 'Email: '.$p['email'] : null]);
        if ($contatti) {
            $righe[] = implode(' · ', $contatti);
        }
        foreach ((array) ($d['dettagli'] ?? []) as $etichetta => $valore) {
            if (is_string($etichetta) && is_scalar($valore) && trim((string) $valore) !== '') {
                $righe[] = Str::limit($etichetta, 40, '').': '.Str::limit(trim((string) $valore), 300);
            }
        }
        if (! empty($d['link'])) {
            $righe[] = 'Apri nell\'app: '.$d['link'];
        }

        return trim(strip_tags(implode("\n", $righe)));
    }

    private function condominio(array $c): Condominio
    {
        $trovato = null;

        if (! empty($c['id'])) {
            $trovato = Condominio::find((int) $c['id']);
        }
        if (! $trovato && ! empty($c['codice_fiscale'])) {
            $trovato = Condominio::where('codice_fiscale', trim((string) $c['codice_fiscale']))->first();
        }
        if (! $trovato && ! empty($c['nome'])) {
            $uguali = Condominio::whereRaw('LOWER(TRIM(nome)) = ?', [mb_strtolower(trim((string) $c['nome']))])->get();
            $trovato = $uguali->count() === 1 ? $uguali->first() : null;
        }

        if (! $trovato) {
            throw ValidationException::withMessages([
                'condominio' => ['Condominio non collegato: «'.($c['nome'] ?? '?').'». Va indicato a quale condominio del gestionale corrisponde.'],
            ]);
        }

        return $trovato;
    }

    /** La persona nell'anagrafica del condominio: per email, altrimenti per nome (anche «Cognome Nome»). Solo se è una sola. */
    private function persona(Condominio $condominio, array $p): ?Anagrafica
    {
        $anagrafiche = $condominio->anagrafiche()->get();
        $email = mb_strtolower(trim((string) ($p['email'] ?? '')));

        if ($email !== '') {
            $perEmail = $anagrafiche->filter(fn (Anagrafica $a) => in_array($email, array_map(
                fn ($e) => mb_strtolower(trim((string) $e)), [$a->email, $a->email_secondaria, $a->pec]), true));
            if ($perEmail->count() === 1) {
                return $perEmail->first();
            }
        }

        $nome = self::parole((string) ($p['nome'] ?? ''));
        if ($nome === []) {
            return null;
        }
        $perNome = $anagrafiche->filter(fn (Anagrafica $a) => self::parole((string) $a->nome) === $nome);

        return $perNome->count() === 1 ? $perNome->first() : null;
    }

    /** Le parole di un nome, minuscole, senza accenti e in ordine: «Rossi Mario» e «MARIO ROSSI» sono la stessa persona. */
    private static function parole(string $nome): array
    {
        $parole = preg_split('/[^a-z0-9]+/', Str::ascii(mb_strtolower($nome)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        sort($parole);

        return $parole;
    }

    /**
     * Le risposte: quelle del sistema esterno non ancora arrivate diventano commenti; quelle scritte nel gestionale
     * tornano indietro, e il sistema esterno le riconosce dal loro id.
     */
    private function commenti(string $sorgente, Segnalazione $segnalazione, array $inArrivo, User $utente): array
    {
        $tipo = (new Commento)->getMorphClass();
        $gia = CollegamentoEsterno::where('sorgente', $sorgente)->where('collegabile_type', $tipo)
            ->whereIn('riferimento', array_map(fn ($c) => (string) ($c['rif'] ?? ''), $inArrivo) ?: [''])
            ->pluck('collegabile_id', 'riferimento');

        foreach ($inArrivo as $c) {
            $rif = (string) ($c['rif'] ?? '');
            $corpo = trim(strip_tags((string) ($c['testo'] ?? '')));
            if ($rif === '' || $gia->has($rif) || ! empty($c['kondo_id'])) {
                continue;
            }

            $autore = trim((string) ($c['autore'] ?? '')) ?: 'Condòmino';
            $daStudio = ($c['da'] ?? '') === 'studio';
            $interna = ! empty($c['interna']);
            $intestazione = $interna ? "[Nota interna dello studio] {$autore}" : ($daStudio ? "{$autore} (studio, dall'app)" : "{$autore} (dall'app)");
            if (! empty($c['foto'])) {
                $corpo = trim($corpo.' ['.(int) $c['foto'].' foto, si vedono nell\'app]');
            }

            $commento = new Commento([
                'user_id'       => $utente->id,
                'condominio_id' => $segnalazione->condominio_id,
                'corpo'         => $intestazione.': '.$corpo,
                'stato'         => 'pubblicato',
            ]);
            $commento->commentable()->associate($segnalazione);
            if (! empty($c['quando'])) {
                $commento->created_at = Carbon::parse($c['quando'])->setTimezone(config('app.timezone'));
                $commento->updated_at = $commento->created_at;
            }
            $commento->save();

            CollegamentoEsterno::create(['sorgente' => $sorgente, 'riferimento' => $rif, 'collegabile_type' => $tipo, 'collegabile_id' => $commento->id]);

            // Una risposta del condòmino è una cosa da guardare: entra nella Inbox come ogni commento nuovo.
            if (! $daStudio && ! $interna) {
                $this->inbox->sincronizza($commento);
            }
        }

        // I commenti nati qui (dall'amministratore, da un collaboratore, da un agente): tutti quelli senza legame.
        $arrivati = CollegamentoEsterno::where('sorgente', $sorgente)->where('collegabile_type', $tipo)->pluck('collegabile_id')->all();

        return $segnalazione->tuttiICommenti()->where('stato', 'pubblicato')->whereNotIn('id', $arrivati ?: [0])
            ->with('autore:id,name')->oldest()->get()
            ->map(fn (Commento $c) => [
                'id'     => $c->id,
                'testo'  => $c->corpo,
                'autore' => $c->autore?->name ?? 'Studio',
                'quando' => $c->created_at?->toIso8601String(),
            ])->values()->all();
    }
}
