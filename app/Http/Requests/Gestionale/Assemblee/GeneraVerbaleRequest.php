<?php

namespace App\Http\Requests\Gestionale\Assemblee;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Genera il testo del verbale da un modello (Web Pro Italia, 17/11/2026). Senza `modello_id`, il modello predefinito.
 * Se il verbale ha già un testo serve `sovrascrivi: true`: il testo nuovo sostituisce il vecchio per intero.
 */
class GeneraVerbaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'modello_id' => ['nullable', 'integer', 'exists:verbale_modelli,id'],
            'sovrascrivi' => ['sometimes', 'boolean'],
        ];
    }
}
