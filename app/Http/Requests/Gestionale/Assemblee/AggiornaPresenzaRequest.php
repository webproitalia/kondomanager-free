<?php

namespace App\Http\Requests\Gestionale\Assemblee;

use App\Models\Assemblee\AssembleaPresenza;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Una presenza durante l'assemblea (Web Pro Italia, 17/11/2026): presente, per delega (a una persona in rubrica o a un
 * nome scritto a mano) o assente; chi rappresenta le unità (fra i loro titolari); le ore di arrivo e di uscita.
 *
 * `segna` è la scorciatoia della sala: «arrivo» (presente da adesso), «uscita» (esce adesso), «rientro» (torna in sala).
 * L'ora è quella dell'amministratore. Ogni campo è facoltativo: si cambia solo quello che si manda.
 */
class AggiornaPresenzaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tipo' => ['sometimes', Rule::in(AssembleaPresenza::TIPI)],
            'anagrafica_id' => ['sometimes', 'integer', 'exists:anagrafiche,id'],
            'delegato_anagrafica_id' => ['sometimes', 'nullable', 'integer', 'exists:anagrafiche,id'],
            'delegato_nome' => ['sometimes', 'nullable', 'string', 'max:150'],
            'ora_arrivo' => ['sometimes', 'nullable', 'date_format:H:i'],
            'ora_uscita' => ['sometimes', 'nullable', 'date_format:H:i'],
            'segna' => ['sometimes', 'nullable', Rule::in(['arrivo', 'uscita', 'rientro'])],
        ];
    }
}
