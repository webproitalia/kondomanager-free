<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Una scrittura fatta da un agente AI (Web Pro Italia, 09/11/2026): metodo, percorso, rotta, esito. Mai i dati inviati. */
class AzioneAgente extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'agenti_azioni';

    protected $fillable = ['chiave_id', 'metodo', 'percorso', 'rotta', 'stato', 'prova', 'durata_ms'];

    protected $casts = ['prova' => 'boolean'];

    public function chiave(): BelongsTo
    {
        return $this->belongsTo(ChiaveAgente::class, 'chiave_id');
    }
}
