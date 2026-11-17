<?php

namespace App\Http\Requests\Gestionale\Assemblee;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Un modello di verbale dello studio (Web Pro Italia, 17/11/2026): il nome, una descrizione, il corpo con i segnaposto
 * (l'elenco è in `SegnapostiVerbale` e nella guida della pagina) e se è il predefinito. In modifica ogni campo è
 * facoltativo: si cambia solo quello che si manda.
 */
class SalvaModelloVerbaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $obbligatorio = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'nome' => [$obbligatorio, 'string', 'max:150'],
            'descrizione' => ['sometimes', 'nullable', 'string', 'max:500'],
            'corpo' => [$obbligatorio, 'string', 'max:200000'],
            'predefinito' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'nome.required' => 'Dai un nome al modello, per esempio «Verbale dello studio».',
            'corpo.required' => 'Il modello è vuoto: scrivi il testo del verbale con i segnaposto.',
        ];
    }
}
