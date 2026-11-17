<?php

namespace App\Http\Requests\Gestionale\Assemblee;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Il verbale scritto (Web Pro Italia, 17/11/2026): il testo intero (quello che si stampa, con il markup della nota del
 * fascicolo: «# » titolo, `**…**` grassetto, «- » elenco), i testi facoltativi che un modello richiama (intestazione,
 * apertura, chiusura) e, se servono, discussione e delibera dei punti. Ogni campo è facoltativo: si cambia solo quello
 * che si manda.
 */
class AggiornaVerbaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'testo' => ['sometimes', 'nullable', 'string', 'max:300000'],
            'intestazione' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'apertura' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'chiusura' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'punti' => ['sometimes', 'array', 'max:100'],
            'punti.*.id' => ['required', 'integer'],
            'punti.*.discussione' => ['sometimes', 'nullable', 'string', 'max:60000'],
            'punti.*.delibera' => ['sometimes', 'nullable', 'string', 'max:60000'],
        ];
    }
}
