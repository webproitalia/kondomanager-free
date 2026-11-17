<?php

namespace App\Http\Requests\Gestionale\Assemblee;

use App\Models\Assemblee\AssembleaPunto;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Il testo di un punto nel verbale e il suo esito (Web Pro Italia, 17/11/2026): la discussione e la delibera (testo
 * libero, a mano o da un assistente) e l'esito confermato dalla persona (il programma lo propone dai voti). Ogni campo è
 * facoltativo: si cambia solo quello che si manda.
 */
class AggiornaPuntoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'discussione' => ['sometimes', 'nullable', 'string', 'max:60000'],
            'delibera' => ['sometimes', 'nullable', 'string', 'max:60000'],
            'esito' => ['sometimes', 'nullable', Rule::in(AssembleaPunto::ESITI)],
        ];
    }
}
