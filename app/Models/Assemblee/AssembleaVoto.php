<?php

namespace App\Models\Assemblee;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Il voto di un avente diritto su un punto. Una riga c'è solo per chi ha preso parte alla votazione: chi non ha la riga
 * non era fra gli intervenuti di quel punto (arrivato dopo, uscito prima). L'astenuto ha la sua riga e conta fra gli
 * intervenuti.
 */
class AssembleaVoto extends Model
{
    protected $table = 'assemblea_voti';

    public const VOTI = ['favorevole', 'contrario', 'astenuto'];

    protected $fillable = ['assemblea_punto_id', 'assemblea_presenza_id', 'voto'];

    public function punto(): BelongsTo
    {
        return $this->belongsTo(AssembleaPunto::class, 'assemblea_punto_id');
    }

    public function presenza(): BelongsTo
    {
        return $this->belongsTo(AssembleaPresenza::class, 'assemblea_presenza_id');
    }
}
