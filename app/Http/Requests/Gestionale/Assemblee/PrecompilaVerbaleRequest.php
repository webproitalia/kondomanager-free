<?php

namespace App\Http\Requests\Gestionale\Assemblee;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Una bozza di discussione e delibera per i punti (Web Pro Italia, 17/11/2026): dalla registrazione o da un
 * assistente. Riempie SOLO i campi vuoti dei punti con quel numero; quello che la persona ha scritto resta.
 */
class PrecompilaVerbaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'punti' => ['required', 'array', 'min:1', 'max:100'],
            'punti.*.numero' => ['required', 'integer', 'min:1'],
            'punti.*.discussione' => ['nullable', 'string', 'max:60000'],
            'punti.*.delibera' => ['nullable', 'string', 'max:60000'],
            'fonte' => ['nullable', 'string', 'max:60'],
        ];
    }
}
