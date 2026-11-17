<?php

namespace App\Models\Assemblee;

use App\Enums\MaggioranzaAssemblea;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un punto dell'ordine del giorno, con la maggioranza che chiede e, dopo l'assemblea, il suo testo nel verbale: la
 * discussione e la delibera. L'esito lo propone il programma dai voti e lo conferma la persona.
 */
class AssembleaPunto extends Model
{
    protected $table = 'assemblea_punti';

    public const ESITI = ['approvato', 'respinto', 'rinviato', 'informativa'];

    protected $fillable = ['assemblea_id', 'numero', 'titolo', 'descrizione', 'maggioranza', 'discussione', 'delibera', 'esito'];

    protected $casts = ['numero' => 'integer'];

    public function assemblea(): BelongsTo
    {
        return $this->belongsTo(Assemblea::class);
    }

    public function voti(): HasMany
    {
        return $this->hasMany(AssembleaVoto::class, 'assemblea_punto_id');
    }

    public function maggioranzaEnum(): MaggioranzaAssemblea
    {
        return MaggioranzaAssemblea::tryFrom((string) $this->maggioranza) ?? MaggioranzaAssemblea::ORDINARIA;
    }

    public function informativo(): bool
    {
        return $this->maggioranzaEnum() === MaggioranzaAssemblea::INFORMATIVA;
    }
}
