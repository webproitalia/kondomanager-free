<?php

namespace App\Http\Requests\Gestionale\Assemblee;

use App\Helpers\DateHelper;
use App\Services\Gestionale\Lettere\RegistroLettere;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * «Registra la spedizione» dell'avviso di convocazione o del verbale (Web Pro Italia, 17/11/2026): le persone a cui è
 * stato spedito o consegnato fuori dal programma, come (i canali delle lettere: raccomandata, PEC mandata fuori dal
 * programma, a mano, posta ordinaria, altro), la data (non dopo oggi), il numero di ognuna se c'è (`numeri`, per
 * `id` della persona: il numero della raccomandata) e una nota. Non manda niente: scrive il registro.
 */
class RegistraSpedizioneAssembleaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'persone' => ['required', 'array', 'min:1', 'max:5000'],
            'persone.*' => ['integer'],
            'canale' => ['required', Rule::in(array_keys(RegistroLettere::CANALI_SPEDIZIONE))],
            'data' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.DateHelper::oggiUtente()],
            'numeri' => ['nullable', 'array'],
            'numeri.*' => ['nullable', 'string', 'max:100'],
            'nota' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'persone.required' => 'Scegli le persone a cui è stata spedita o consegnata.',
            'canale.required' => 'Scegli come è stata spedita.',
            'canale.in' => 'I canali sono: raccomandata, pec, a_mano, posta, altro.',
            'data.required' => 'Scrivi la data della spedizione o della consegna.',
            'data.before_or_equal' => 'La data della spedizione non può essere dopo oggi.',
        ];
    }
}
