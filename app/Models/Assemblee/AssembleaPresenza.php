<?php

namespace App\Models\Assemblee;

use App\Models\Anagrafica;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un avente diritto all'assemblea: una «testa». È la persona (o il rappresentante dei comproprietari) con le unità che
 * rappresenta e la somma dei loro millesimi, fotografati quando si prepara l'elenco. Durante l'assemblea si segna se è
 * presente, se ha delegato qualcuno (in rubrica o no) o se è assente, e l'ora in cui arriva o esce.
 *
 * `millesimi` è il valore com'è nella tabella (decimale a cinque cifre), non riportato a mille: il riporto lo fanno i
 * conteggi, sul totale della tabella.
 *
 * `nome` è il nome della persona fotografato nella riga, e `delegato_nome` quello del delegato (scritto a mano, o
 * copiato dalla rubrica): se l'anagrafica viene eliminata la riga resta, con il suo nome e i suoi voti
 * (`anagrafica_id` e `delegato_anagrafica_id` diventano vuoti).
 */
class AssembleaPresenza extends Model
{
    protected $table = 'assemblea_presenze';

    public const TIPI = ['presente', 'delega', 'assente'];

    protected $fillable = [
        'assemblea_id', 'anagrafica_id', 'nome', 'tipo', 'delegato_anagrafica_id', 'delegato_nome', 'millesimi', 'unita',
        'ora_arrivo', 'ora_uscita',
    ];

    protected $casts = ['unita' => 'array', 'millesimi' => 'decimal:5'];

    public function assemblea(): BelongsTo
    {
        return $this->belongsTo(Assemblea::class);
    }

    public function anagrafica(): BelongsTo
    {
        return $this->belongsTo(Anagrafica::class);
    }

    public function delegato(): BelongsTo
    {
        return $this->belongsTo(Anagrafica::class, 'delegato_anagrafica_id');
    }

    public function voti(): HasMany
    {
        return $this->hasMany(AssembleaVoto::class, 'assemblea_presenza_id');
    }

    /** Il nome di chi rappresenta la testa: quello di adesso in rubrica, o quello fotografato se l'anagrafica non c'è più. */
    public function nomePersona(): string
    {
        $nome = $this->anagrafica?->nome ?? $this->nome;

        return filled($nome) ? trim((string) $nome) : 'Persona senza nome (riga '.$this->id.')';
    }

    /** Ha partecipato: di persona o per delega (anche se poi è uscito). */
    public function intervenuto(): bool
    {
        return in_array($this->tipo, ['presente', 'delega'], true);
    }

    /** È in sala adesso: ha partecipato e non è uscito. */
    public function inSala(): bool
    {
        return $this->intervenuto() && $this->ora_uscita === null;
    }

    /** Il nome del delegato com'è da scrivere: quello in rubrica, o quello scritto a mano. */
    public function nomeDelegato(): ?string
    {
        if ($this->tipo !== 'delega') {
            return null;
        }

        $nome = $this->delegato?->nome ?? $this->delegato_nome;

        return filled($nome) ? trim((string) $nome) : null;
    }

    /** @return list<int> */
    public function idUnita(): array
    {
        return array_values(array_map('intval', (array) ($this->unita ?? [])));
    }
}
