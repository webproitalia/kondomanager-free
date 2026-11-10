<?php

namespace App\Jobs\Agenti;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * L'avviso in uscita (Web Pro Italia, 10/11/2026): «la segnalazione N è cambiata qui». Parte dalla coda, così un
 * sistema esterno spento non rallenta nessuno, e riprova da solo per circa un'ora. Il corpo è firmato
 * (X-Kondo-Firma: sha256=HMAC del corpo con KONDO_WEBHOOK_SEGRETO). Vedi config/agenti.php.
 */
class AvvisaSistemaEsterno implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 6;

    public int $timeout = 20;

    public function __construct(public string $evento, public string $sorgente, public string $riferimento, public int $id) {}

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

        $corpo = json_encode([
            'evento' => $this->evento, 'sorgente' => $this->sorgente, 'riferimento' => $this->riferimento,
            'id' => $this->id, 'quando' => now()->toIso8601String(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $risposta = Http::timeout(15)->withBody($corpo, 'application/json')->withHeaders([
            'X-Kondo-Evento' => $this->evento,
            'X-Kondo-Firma'  => 'sha256='.hash_hmac('sha256', $corpo, (string) config('agenti.webhook_segreto')),
        ])->post($url);

        if (! $risposta->successful()) {
            throw new RuntimeException('Avviso al sistema esterno non riuscito: HTTP '.$risposta->status());
        }
    }
}
