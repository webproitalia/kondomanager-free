<?php

namespace App\Http\Requests\Gestionale\Assemblee;

use Illuminate\Foundation\Http\FormRequest;

/**
 * La convocazione scritta (Web Pro Italia, 17/11/2026): il testo intero dell'avviso (quello che si stampa e si manda,
 * con il markup della nota del fascicolo: «# » titolo, `**…**` grassetto, «- » elenco, una riga di soli trattini per
 * staccare il modulo di delega) e il collegamento della videoconferenza. Ogni campo è facoltativo: si cambia solo
 * quello che si manda. I segnaposto `{{destinatario.…}}` si lasciano nel testo: li riempie la stampa per persona.
 */
class AggiornaConvocazioneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'testo' => ['sometimes', 'nullable', 'string', 'max:300000'],
            'collegamento_videoconferenza' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'collegamento_videoconferenza.max' => 'Il collegamento è troppo lungo: al massimo 500 caratteri.',
        ];
    }
}
