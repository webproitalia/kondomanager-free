<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Chiave di un agente AI (Web Pro Italia, 09/11/2026). Vedi la migrazione `create_agenti_tables`.
 *
 * Il testo della chiave è `kag_` seguito da 48 caratteri casuali: il prefisso permette al middleware di riconoscerla
 * senza confonderla con altri token Bearer, e di non toccare le richieste che non la portano.
 */
class ChiaveAgente extends Model
{
    public const PREFISSO = 'kag_';

    protected $table = 'agenti_chiavi';

    protected $fillable = ['nome', 'user_id', 'creata_da', 'impronta', 'inizio', 'sola_lettura', 'scade_at'];

    protected $casts = [
        'sola_lettura'  => 'boolean',
        'ultimo_uso_at' => 'datetime',
        'scade_at'      => 'datetime',
        'revocata_at'   => 'datetime',
    ];

    protected $hidden = ['impronta'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function azioni(): HasMany
    {
        return $this->hasMany(AzioneAgente::class, 'chiave_id');
    }

    public static function impronta(string $testo): string
    {
        return hash('sha256', $testo);
    }

    /** Crea la chiave e restituisce il testo in chiaro, che non si potrà più rileggere. */
    public static function genera(string $nome, User $utente, bool $solaLettura, ?User $autore = null, ?\DateTimeInterface $scade = null): array
    {
        $testo = self::PREFISSO.Str::random(48);

        $chiave = self::create([
            'nome'         => $nome,
            'user_id'      => $utente->id,
            'creata_da'    => $autore?->id,
            'impronta'     => self::impronta($testo),
            'inizio'       => substr($testo, 0, 10),
            'sola_lettura' => $solaLettura,
            'scade_at'     => $scade,
        ]);

        return [$chiave, $testo];
    }

    public static function trova(string $testo): ?self
    {
        if (! str_starts_with($testo, self::PREFISSO) || strlen($testo) < 20) {
            return null;
        }

        return self::where('impronta', self::impronta($testo))->first();
    }

    public function attiva(): bool
    {
        return is_null($this->revocata_at) && (is_null($this->scade_at) || $this->scade_at->isFuture());
    }
}
