<?php

namespace App\Http\Requests\Gestionale\Fascicolo;

use App\Services\Fascicolo\FascicoloBilancioService;
use Illuminate\Foundation\Http\FormRequest;

/** La parte scritta del fascicolo di bilancio: tipo, titolo di copertina, nota e sezioni (Web Pro Italia, 13/11/2026). */
class UpdateFascicoloBilancioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tipo' => ['nullable', 'string', 'in:'.implode(',', FascicoloBilancioService::TIPI)],
            'titolo' => ['nullable', 'string', 'max:150'],
            'sottotitolo' => ['nullable', 'string', 'max:250'],
            'nota' => ['nullable', 'string', 'max:30000'],
            'sezioni' => ['nullable', 'array'],
            'sezioni.*' => ['string', 'in:'.implode(',', array_keys(FascicoloBilancioService::SEZIONI))],
        ];
    }
}
