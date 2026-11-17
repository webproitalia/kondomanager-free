<?php

namespace App\Models\Assemblee;

use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Tabella;
use App\Models\User;
use App\Traits\RisolveIFigliDelleRotte;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Un'assemblea del condominio (Web Pro Italia, 17/11/2026): le date delle due convocazioni, il luogo, la tabella dei
 * millesimi su cui si contano costituzione e voti, l'ordine del giorno, le presenze, i voti e il verbale.
 *
 * Si compila un pezzo alla volta: prima (date e ordine del giorno), durante (presenze, voti, presidente e segretario,
 * ore) e dopo (il verbale). I conteggi non si salvano: li rifà `App\Services\Assemblee\ConteggiAssemblea` dalle
 * presenze e dai voti, ogni volta.
 */
class Assemblea extends Model
{
    use RisolveIFigliDelleRotte;

    protected $table = 'assemblee';

    public const TIPI = ['ordinaria', 'straordinaria'];
    public const CONVOCAZIONI = ['prima', 'seconda'];
    public const MODALITA = ['presenza', 'videoconferenza', 'mista'];
    public const STATI = ['bozza', 'convocata', 'in_corso', 'chiusa'];

    protected $fillable = [
        'condominio_id', 'esercizio_id', 'tipo', 'titolo', 'data_prima_convocazione', 'data_seconda_convocazione',
        'convocazione_tenuta', 'data_avviso', 'luogo', 'modalita', 'tabella_id', 'millesimi_totali', 'presidente',
        'segretario', 'ora_inizio', 'ora_fine', 'stato', 'registrata', 'note', 'created_by',
        // L'avviso di convocazione (17/11/2026): il testo generato da un modello e poi libero, il modello usato, il collegamento della videoconferenza.
        'convocazione_testo', 'convocazione_modello_id', 'collegamento_videoconferenza',
    ];

    protected $casts = [
        'data_prima_convocazione' => 'datetime:Y-m-d H:i',
        'data_seconda_convocazione' => 'datetime:Y-m-d H:i',
        'data_avviso' => 'date:Y-m-d',
        'registrata' => 'boolean',
        'millesimi_totali' => 'decimal:5',
    ];

    public function condominio(): BelongsTo
    {
        return $this->belongsTo(Condominio::class);
    }

    public function esercizio(): BelongsTo
    {
        return $this->belongsTo(Esercizio::class);
    }

    public function tabella(): BelongsTo
    {
        return $this->belongsTo(Tabella::class);
    }

    public function creatore(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** L'ordine del giorno, nell'ordine dei numeri. */
    public function punti(): HasMany
    {
        return $this->hasMany(AssembleaPunto::class)->orderBy('numero')->orderBy('id');
    }

    public function presenze(): HasMany
    {
        return $this->hasMany(AssembleaPresenza::class)->orderBy('id');
    }

    public function verbale(): HasOne
    {
        return $this->hasOne(Verbale::class);
    }

    /** Il modello da cui è stato generato l'ultima volta il testo della convocazione (può non esserci più). */
    public function modelloConvocazione(): BelongsTo
    {
        return $this->belongsTo(VerbaleModello::class, 'convocazione_modello_id');
    }

    /**
     * La convocazione su cui si contano costituzione e maggioranze. Finché la persona non la sceglie vale la prima, che
     * per la maggioranza ordinaria chiede di più (metà del valore invece di un terzo): un conto prudente, mai uno
     * che dichiara approvato quello che non lo è.
     */
    public function convocazione(): string
    {
        return in_array($this->convocazione_tenuta, self::CONVOCAZIONI, true) ? $this->convocazione_tenuta : 'prima';
    }

    /** Il giorno dell'assemblea: quello della convocazione tenuta, o il primo che c'è. */
    public function giorno(): ?\Carbon\Carbon
    {
        $data = $this->convocazione_tenuta === 'seconda'
            ? ($this->data_seconda_convocazione ?? $this->data_prima_convocazione)
            : ($this->data_prima_convocazione ?? $this->data_seconda_convocazione);

        return $data;
    }

    /** Il verbale è approvato: da qui in poi niente dell'assemblea si modifica, finché non si apre una nuova versione. */
    public function bloccata(): bool
    {
        return $this->verbale?->stato === Verbale::APPROVATO;
    }

    protected function relazioniDeiFigliNelleRotte(): array
    {
        return [
            'punto' => 'punti',
            'presenza' => 'presenze',
        ];
    }
}
