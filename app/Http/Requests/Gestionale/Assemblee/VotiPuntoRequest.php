<?php

namespace App\Http\Requests\Gestionale\Assemblee;

use App\Models\Assemblee\AssembleaVoto;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * I voti di un punto (Web Pro Italia, 17/11/2026).
 *
 * - `tutti`: lo stesso voto a tutti quelli che sono in sala adesso (il tocco «tutti i presenti favorevoli»);
 * - `voti`: i voti di alcuni, uno per presenza; `voto` vuoto toglie la persona dalla votazione (non ha votato);
 * - `azzera`: toglie tutti i voti del punto.
 *
 * Si applicano in quest'ordine: prima `azzera`, poi `tutti`, poi `voti` (così «tutti favorevoli, tranne questi due
 * contrari» è una richiesta sola).
 */
class VotiPuntoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'azzera' => ['sometimes', 'boolean'],
            'tutti' => ['sometimes', 'nullable', Rule::in(AssembleaVoto::VOTI)],
            'voti' => ['sometimes', 'array', 'max:2000'],
            'voti.*.presenza_id' => ['required', 'integer'],
            'voti.*.voto' => ['nullable', Rule::in(AssembleaVoto::VOTI)],
        ];
    }
}
