<?php

namespace App\Models\Assemblee;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Il voto di un avente diritto su un punto. Una riga c'è solo per chi ha espresso un voto: l'astenuto ha la sua riga.
 * Chi è in sala e non ha la riga è un non votante: conta lo stesso fra gli intervenuti del punto, e per la maggioranza
 * pesa come un astenuto (`ConteggiAssemblea`). Chi è uscito tiene i voti dati prima; chi è segnato assente non ne ha.
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
