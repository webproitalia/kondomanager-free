<?php

namespace App\Models\Assemblee;

use App\Models\Documento;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Il verbale di un'assemblea. Il **testo** (`testo`) è quello che si stampa: lo genera il programma da un modello dello
 * studio (`modello_id`, l'ultimo usato) e poi è libero, a mano o da un assistente. Intestazione, apertura e chiusura
 * sono testi facoltativi che un modello può richiamare con i suoi segnaposto; discussione e delibera di ogni punto
 * stanno in `assemblea_punti`.
 *
 * Stati: `bozza` → `da_approvare` (chi l'ha preparato lo manda) → `approvato` (lo approva una persona, mai un agente
 * AI). Un verbale approvato non si modifica: si riapre una nuova versione, con il numero che sale.
 */
class Verbale extends Model
{
    protected $table = 'verbali';

    public const BOZZA = 'bozza';
    public const DA_APPROVARE = 'da_approvare';
    public const APPROVATO = 'approvato';
    public const STATI = [self::BOZZA, self::DA_APPROVARE, self::APPROVATO];

    protected $fillable = [
        'assemblea_id', 'stato', 'intestazione', 'apertura', 'chiusura', 'testo', 'modello_id', 'versione', 'documento_id',
        'inviato_in_approvazione_at', 'approvato_at', 'approvato_by',
    ];

    protected $casts = [
        'versione' => 'integer',
        'inviato_in_approvazione_at' => 'datetime',
        'approvato_at' => 'datetime',
    ];

    protected $attributes = [
        'stato' => self::BOZZA,
        'versione' => 1,
    ];

    public function assemblea(): BelongsTo
    {
        return $this->belongsTo(Assemblea::class);
    }

    public function documento(): BelongsTo
    {
        return $this->belongsTo(Documento::class);
    }

    public function modello(): BelongsTo
    {
        return $this->belongsTo(VerbaleModello::class, 'modello_id');
    }

    public function approvatore(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approvato_by');
    }

    public function approvato(): bool
    {
        return $this->stato === self::APPROVATO;
    }
}
