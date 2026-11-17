<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una registrazione di una riunione del condominio (Web Pro Italia, 17/11/2026): spesso un'assemblea, non sempre. Il browser la registra a
 * pezzi (`segmenti`), ognuno trascritto subito (`trascrizione_live`); a fine riunione il lavoro in coda `ElaboraRegistrazione` unisce
 * l'audio, riconosce le voci (`trascrizione`), scrive il `riassunto` e, se c'è l'assemblea con il suo ordine del giorno, la `bozza_punti`.
 *
 * Gli stati: `in_corso` (si registra, o il browser si è chiuso e la registrazione aspetta di essere chiusa dall'elenco), `in_elaborazione`
 * (il lavoro in coda è partito), `pronta`, `errore` (il motivo in `errore`; i pezzi di audio restano e si può riprovare).
 * L'audio sta sul disco privato, in {@see cartella()}; vedi `App\Services\Registrazioni\ArchivioAudio`.
 */
class Registrazione extends Model
{
    public const IN_CORSO = 'in_corso';

    public const IN_ELABORAZIONE = 'in_elaborazione';

    public const PRONTA = 'pronta';

    public const ERRORE = 'errore';

    /** I formati dei pezzi: webm (Chrome, Firefox, Android), mp4 (Safari sull'iPhone), ogg. */
    public const FORMATI = ['webm', 'mp4', 'ogg'];

    protected $table = 'registrazioni';

    protected $fillable = [
        'condominio_id', 'assemblea_id', 'titolo', 'stato', 'avviso_presenti_at', 'iniziata_at', 'finita_at', 'durata_secondi', 'formato',
        'segmenti', 'trascrizione_live', 'trascrizione', 'riassunto', 'bozza_punti', 'audio_cancellato_at', 'errore', 'created_by',
    ];

    protected $casts = [
        'assemblea_id' => 'integer',
        'durata_secondi' => 'integer',
        'segmenti' => 'array',
        'bozza_punti' => 'array',
        'avviso_presenti_at' => 'datetime',
        'iniziata_at' => 'datetime',
        'finita_at' => 'datetime',
        'audio_cancellato_at' => 'datetime',
    ];

    public function condominio(): BelongsTo
    {
        return $this->belongsTo(Condominio::class);
    }

    public function autore(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** La cartella dell'audio sul disco privato (`local`), relativa alla sua radice. */
    public function cartella(): string
    {
        return 'registrazioni/'.$this->id;
    }

    /** I secondi registrati: quelli della passata finale se c'è, se no la somma dei pezzi. */
    public function secondi(): int
    {
        if ($this->durata_secondi) {
            return (int) $this->durata_secondi;
        }

        return (int) round(collect($this->segmenti ?? [])->sum(fn ($s) => (float) ($s['secondi'] ?? 0)));
    }
}
