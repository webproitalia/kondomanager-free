<?php

namespace App\Http\Requests\Gestionale\Assemblee;

use App\Models\Assemblee\VerbaleModello;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            // Solo un modello di verbale: quelli della convocazione e delle email stanno nella stessa tabella, con un altro tipo.
            'modello_id' => ['nullable', 'integer', Rule::exists('verbale_modelli', 'id')->where('tipo', VerbaleModello::VERBALE)],
            'sovrascrivi' => ['sometimes', 'boolean'],
        ];
    }
}
