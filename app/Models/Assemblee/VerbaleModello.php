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
 *
 * Dal 17/11/2026 la stessa tabella porta anche gli altri testi dell'assemblea, distinti da `tipo`: l'avviso di
 * convocazione, il testo dell'email che lo accompagna e quello con cui si manda il verbale approvato. Stesso motore dei
 * segnaposto, stessa pagina, **un predefinito per tipo**.
 */
class VerbaleModello extends Model
{
    protected $table = 'verbale_modelli';

    public const VERBALE = 'verbale';
    public const CONVOCAZIONE = 'convocazione';
    public const EMAIL_CONVOCAZIONE = 'email_convocazione';
    public const TRASMISSIONE_VERBALE = 'trasmissione_verbale';

    /** I tipi di modello, con il nome che si legge nella pagina dei modelli. */
    public const TIPI = [
        self::VERBALE => 'Verbale',
        self::CONVOCAZIONE => 'Avviso di convocazione',
        self::EMAIL_CONVOCAZIONE => 'Email della convocazione',
        self::TRASMISSIONE_VERBALE => 'Trasmissione del verbale (email e lettera)',
    ];

    protected $fillable = ['tipo', 'nome', 'descrizione', 'corpo', 'predefinito', 'created_by'];

    protected $casts = ['predefinito' => 'boolean'];

    protected $attributes = ['tipo' => self::VERBALE];

    public function autore(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Il modello predefinito di un tipo (di serie quello del verbale); se nessuno lo è, il primo creato di quel tipo. */
    public static function predefinito(string $tipo = self::VERBALE): ?self
    {
        return static::where('tipo', $tipo)->where('predefinito', true)->orderBy('id')->first()
            ?? static::where('tipo', $tipo)->orderBy('id')->first();
    }

    /** Il modello con quel numero, solo se è del tipo chiesto: un modello di verbale non genera una convocazione. */
    public static function delTipo(string $tipo, int|string|null $id): ?self
    {
        return is_numeric($id) ? static::where('tipo', $tipo)->find((int) $id) : null;
    }
}
