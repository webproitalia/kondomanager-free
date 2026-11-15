<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una richiesta di un agente AI (Web Pro Italia, 09/11/2026): ogni scrittura e ogni rifiuto, con metodo, percorso,
 * rotta, esito e, per i rifiuti, il motivo e i nomi dei campi sbagliati. Mai i dati inviati.
 */
class AzioneAgente extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'agenti_azioni';

    protected $fillable = ['chiave_id', 'metodo', 'percorso', 'rotta', 'stato', 'motivo', 'campi', 'prova', 'durata_ms'];

    protected $casts = ['prova' => 'boolean'];

    public function chiave(): BelongsTo
    {
        return $this->belongsTo(ChiaveAgente::class, 'chiave_id');
    }
}
