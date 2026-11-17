<?php

namespace App\Http\Requests\Gestionale\Assemblee;

use App\Models\Assemblee\VerbaleModello;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Genera il testo della convocazione da un modello (Web Pro Italia, 17/11/2026). Senza `modello_id`, il modello di
 * convocazione predefinito. Se la convocazione ha già un testo serve `sovrascrivi: true`: il testo nuovo sostituisce
 * il vecchio per intero.
 */
class GeneraConvocazioneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'modello_id' => ['nullable', 'integer', Rule::exists('verbale_modelli', 'id')->where('tipo', VerbaleModello::CONVOCAZIONE)],
            'sovrascrivi' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'modello_id.exists' => 'Il modello scelto non è un modello di convocazione.',
        ];
    }
}
