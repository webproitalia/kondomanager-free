<?php

namespace App\Models\Gestionale;

use App\Models\Esercizio;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * La parte SCRITTA del fascicolo di bilancio di un esercizio: nota sintetica esplicativa, titolo e sezioni scelte.
 * I numeri del fascicolo non si salvano mai: `FascicoloBilancioService` li rilegge dai registri a ogni stampa.
 */
class FascicoloBilancio extends Model
{
    protected $table = 'fascicoli_bilancio';

    protected $fillable = ['esercizio_id', 'tipo', 'titolo', 'sottotitolo', 'nota', 'sezioni', 'aggiornato_da'];

    protected $casts = ['sezioni' => 'array'];

    public function esercizio(): BelongsTo
    {
        return $this->belongsTo(Esercizio::class);
    }
}
