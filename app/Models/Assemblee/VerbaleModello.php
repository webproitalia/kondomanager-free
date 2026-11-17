<?php

namespace App\Models\Assemblee;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un modello di verbale dello studio (Web Pro Italia, 17/11/2026): un testo con i segnaposto (`{{presidente}}`,
 * `{{#punti}} … {{/punti}}`…) da cui il programma genera il verbale di un'assemblea. Vale per tutti i condomini.
 *
 * Uno solo è il predefinito, quello che la pagina del verbale propone. Chi genera un verbale ne copia il risultato:
 * cambiare o cancellare il modello dopo non tocca i verbali già scritti.
 */
class VerbaleModello extends Model
{
    protected $table = 'verbale_modelli';

    protected $fillable = ['nome', 'descrizione', 'corpo', 'predefinito', 'created_by'];

    protected $casts = ['predefinito' => 'boolean'];

    public function autore(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Il modello predefinito; se nessuno lo è, il primo creato. */
    public static function predefinito(): ?self
    {
        return static::where('predefinito', true)->orderBy('id')->first() ?? static::orderBy('id')->first();
    }
}
