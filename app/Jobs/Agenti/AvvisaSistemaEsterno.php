<?php

namespace App\Jobs\Agenti;

use App\Models\CollegamentoEsterno;
use App\Models\Comunicazione;
use App\Models\Segnalazione;
use App\Services\Agenti\SegnalazioniEsterne;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * L'avviso in uscita (Web Pro Italia, 10/11/2026) verso il sistema esterno collegato, per esempio l'app dei condòmini.
 * Parte dalla coda, così un sistema esterno spento non rallenta nessuno, e riprova da solo per circa un'ora. Il corpo
 * è firmato (X-Kondo-Firma: sha256=HMAC del corpo con KONDO_WEBHOOK_SEGRETO). Vedi config/agenti.php.
 *
 * Eventi:
 *  - segnalazione.cambiata / segnalazione.eliminata: una segnalazione già collegata è cambiata qui. Solo l'avviso:
 *    il sistema esterno si rimette in pari da sé (PUT /agenti/esterni/{sorgente}/segnalazioni/{riferimento}).
 *  - segnalazione.nata: una segnalazione creata QUI, non ancora collegata: porta i suoi dati, il sistema esterno la
 *    crea da sé e poi la «adotta» con la stessa chiamata (campo kondo_id).
 *  - comunicazione.cambiata / comunicazione.eliminata: una comunicazione della bacheca, con i suoi dati.
 *  - rubrica.cambiata: le anagrafiche sono cambiate; il sistema esterno rilegge le persone dei suoi condomini.
 *
 * I dati si leggono al momento dell'invio, non della messa in coda: chi crea una segnalazione o una comunicazione
 * collega condomini e persone un attimo dopo averla salvata.
 */
class AvvisaSistemaEsterno implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 6;

    public int $timeout = 20;

    /** @param  array<int, array{sorgente: string, riferimento: string}>  $collegamenti  per gli oggetti cancellati */
    public function __construct(
        public string $evento,
        public ?string $sorgente = null,
        public ?string $riferimento = null,
        public int $id = 0,
        public array $collegamenti = [],
    ) {}

    public function backoff(): array
    {
        return [5, 30, 120, 600, 1800];
    }

    public function handle(): void
    {
        $url = config('agenti.webhook_url');
        if (! $url) {
            return;
        }

        $dati = match ($this->evento) {
            'segnalazione.nata'      => $this->segnalazioneNata(),
            'comunicazione.cambiata' => $this->comunicazione(),
            default                  => [],
        };

        if ($dati === null) {
            return;
        }

        $corpo = json_encode([
            'evento' => $this->evento, 'sorgente' => $this->sorgente, 'riferimento' => $this->riferimento,
            'id' => $this->id, 'quando' => now()->toIso8601String(),
            ...($this->collegamenti ? ['collegamenti' => $this->collegamenti] : []),
            ...$dati,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $risposta = Http::timeout(15)->withBody($corpo, 'application/json')->withHeaders([
            'X-Kondo-Evento' => $this->evento,
            'X-Kondo-Firma'  => 'sha256='.hash_hmac('sha256', $corpo, (string) config('agenti.webhook_segreto')),
        ])->post($url);

        if (! $risposta->successful()) {
            throw new RuntimeException('Avviso al sistema esterno non riuscito: HTTP '.$risposta->status());
        }
    }

    /** Una segnalazione nata qui. Null (niente da mandare) se non c'è più o se nel frattempo è già collegata. */
    private function segnalazioneNata(): ?array
    {
        $s = Segnalazione::with(['anagrafiche:id,nome,email,telefono,cellulare', 'createdBy:id,name'])->find($this->id);

        if (! $s || CollegamentoEsterno::di($s)->isNotEmpty()) {
            return null;
        }

        return ['segnalazione' => [
            'id'            => $s->id,
            'condominio_id' => $s->condominio_id,
            'titolo'        => $s->subject,
            'testo'         => $s->description,
            'stato'         => SegnalazioniEsterne::statoDi($s),
            'urgente'       => SegnalazioniEsterne::urgente($s),
            'creata'        => $s->created_at?->toIso8601String(),
            'autore'        => $s->createdBy?->name,
            'url'           => route('admin.segnalazioni.show', $s->id),
            'persone'       => $s->anagrafiche->map(fn ($a) => ['id' => $a->id, 'nome' => $a->nome, 'email' => $a->email, 'telefono' => $a->cellulare ?: $a->telefono])->all(),
        ]];
    }

    /** Una comunicazione della bacheca com'è adesso. Se non c'è più diventa un'eliminazione. */
    private function comunicazione(): ?array
    {
        $c = Comunicazione::with('condomini:id')->find($this->id);

        if (! $c) {
            $this->evento = 'comunicazione.eliminata';

            return [];
        }

        return [
            'collegamenti' => CollegamentoEsterno::di($c)->map(fn ($l) => ['sorgente' => $l->sorgente, 'riferimento' => $l->riferimento])->all(),
            'comunicazione' => [
                'id'         => $c->id,
                'titolo'     => $c->subject,
                'testo'      => $c->description,
                'importante' => $c->is_featured || in_array($c->priority, ['alta', 'urgente'], true),
                // In bacheca per tutti solo se è pubblicata, approvata, non privata e senza destinatari scelti uno per uno.
                'pubblicata' => $c->is_published && $c->is_approved && ! $c->is_private && ! $c->anagrafiche()->exists(),
                'condomini'  => $c->condomini->pluck('id')->all(),
                'creata'     => $c->created_at?->toIso8601String(),
                'url'        => route('admin.comunicazioni.show', $c->id),
            ],
        ];
    }
}
