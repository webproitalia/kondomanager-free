<?php

namespace App\Observers\Agenti;

use App\Enums\EventoTipo;
use App\Jobs\Agenti\AvvisaSistemaEsterno;
use App\Models\CollegamentoEsterno;
use App\Models\Commento;
use App\Models\Evento;
use App\Models\Segnalazione;
use App\Services\Agenti\SegnalazioniEsterne;
use App\Services\Gestionale\InboxService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Due cose che seguono la vita di una segnalazione (Web Pro Italia, 10/11/2026):
 *
 *  1. LA INBOX. Il riquadro «Ticket & manutenzione» conta i compiti di tipo `segnalazione_guasto`, ma nessuno li
 *     creava: restava sempre a zero. Ora ogni segnalazione aperta ha il suo compito, con «Risolvi» che la apre;
 *     il compito si chiude quando la segnalazione è chiusa o risolta, e torna se viene riaperta.
 *  2. L'AVVISO IN USCITA. Se la segnalazione è legata a un sistema esterno (CollegamentoEsterno) e la modifica
 *     nasce qui (non è l'eco di una sincronizzazione), parte l'avviso «è cambiata»: il sistema esterno si
 *     rimette in pari. Vale per stato, priorità, testo, cancellazione e per ogni commento nuovo.
 *
 * Niente di tutto questo deve poter far fallire il salvataggio di una segnalazione: gli errori si registrano.
 */
class SegnalazioneCollegataObserver
{
    public function saved(Segnalazione $segnalazione): void
    {
        $this->senzaRompere(fn () => $this->inbox($segnalazione));

        if ($segnalazione->wasRecentlyCreated || $segnalazione->wasChanged(['stato', 'priority', 'subject', 'description'])) {
            $this->senzaRompere(fn () => $this->avvisa($segnalazione, 'segnalazione.cambiata'));
        }
    }

    public function deleted(Segnalazione $segnalazione): void
    {
        $this->senzaRompere(function () use ($segnalazione) {
            Evento::where('tipo', EventoTipo::SEGNALAZIONE_GUASTO->value)
                ->where('eventable_type', $segnalazione->getMorphClass())->where('eventable_id', $segnalazione->id)
                ->where('is_completed', false)->update(['is_completed' => true, 'completed_at' => now()]);
            InboxService::clearAdminCache();

            $this->avvisa($segnalazione, 'segnalazione.eliminata');
            CollegamentoEsterno::where('collegabile_type', $segnalazione->getMorphClass())
                ->where('collegabile_id', $segnalazione->id)->whereNull('eliminato_at')->update(['eliminato_at' => now()]);
        });
    }

    /** Un commento nuovo su una segnalazione collegata. Registrato su Commento::created. */
    public function commentoCreato(Commento $commento): void
    {
        $this->senzaRompere(function () use ($commento) {
            $segnalazione = $commento->commentable;
            if ($segnalazione instanceof Segnalazione && $commento->stato === 'pubblicato') {
                $this->avvisa($segnalazione, 'segnalazione.cambiata');
            }
        });
    }

    private function inbox(Segnalazione $s): void
    {
        $aperti = Evento::where('tipo', EventoTipo::SEGNALAZIONE_GUASTO->value)
            ->where('eventable_type', $s->getMorphClass())->where('eventable_id', $s->id)->where('is_completed', false);

        if (SegnalazioniEsterne::statoDi($s) === 'chiusa') {
            if ($aperti->exists()) {
                $aperti->update(['is_completed' => true, 'completed_at' => now()]);
                InboxService::clearAdminCache();
            }

            return;
        }

        $urgente = SegnalazioniEsterne::urgente($s);

        if ($compito = $aperti->first()) {
            // La priorità del compito segue quella della segnalazione.
            if ($s->wasChanged('priority') || $s->wasChanged('subject')) {
                $compito->update(['priorita' => $urgente ? 'alta' : 'normale', 'title' => $this->titolo($s)]);
                InboxService::clearAdminCache();
            }

            return;
        }

        // Si crea alla nascita della segnalazione, o quando una chiusa viene riaperta.
        if (! $s->wasRecentlyCreated && ! $s->wasChanged('stato')) {
            return;
        }

        InboxService::createTask(
            tipo: EventoTipo::SEGNALAZIONE_GUASTO,
            title: $this->titolo($s),
            description: Str::limit(trim((string) preg_replace('/\s+/', ' ', Str::before((string) $s->description, "\n—"))), 180),
            scadenza: $urgente ? now() : now()->addDays(2),
            createdByUserId: (int) $s->created_by,
            condominioId: $s->condominio_id,
            context: ['segnalazione_id' => $s->id],
            actionUrl: route('admin.segnalazioni.show', $s->id, false),
            eventableType: $s->getMorphClass(),
            eventableId: $s->id,
            priorita: $urgente ? 'alta' : 'normale',
        );
    }

    private function titolo(Segnalazione $s): string
    {
        return Str::limit('Segnalazione — '.$s->subject.($s->condominio ? ' ('.$s->condominio->nome.')' : ''), 250);
    }

    private function avvisa(Segnalazione $s, string $evento): void
    {
        if (SegnalazioniEsterne::$inCorso || ! config('agenti.webhook_url')) {
            return;
        }

        foreach (CollegamentoEsterno::di($s) as $legame) {
            $lavoro = new AvvisaSistemaEsterno($evento, $legame->sorgente, $legame->riferimento, (int) $s->id);
            DB::afterCommit(fn () => dispatch($lavoro));
        }
    }

    private function senzaRompere(callable $cosa): void
    {
        try {
            $cosa();
        } catch (Throwable $e) {
            Log::warning('Segnalazione collegata: '.$e->getMessage(), ['file' => $e->getFile().':'.$e->getLine()]);
        }
    }
}
