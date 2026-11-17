<?php

namespace App\Http\Requests\Gestionale\Assemblee;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Manda il verbale in approvazione (Web Pro Italia, 17/11/2026). Di norma senza campi: l'invio controlla da solo che il
 * testo corrisponda ai dati dell'assemblea, e se qualcosa non va lo dice. Due conferme esplicite, per chi sa quello
 * che fa:
 *
 * - `dati_riletti: true` — i dati dell'assemblea (presenze, voti, esiti, ore…) sono cambiati dopo l'ultima volta che il
 *   testo è stato generato o salvato, ma chi manda ha riletto il testo con i dati di adesso e lo conferma com'è;
 * - `esiti_confermati: [id dei punti]` — l'esito scritto per quei punti è diverso da quello che danno i voti
 *   registrati, ed è giusto così (per esempio una maggioranza di una legge speciale, che il programma non conosce).
 *   Lo decide la persona, non un assistente.
 */
class InviaVerbaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'dati_riletti' => ['sometimes', 'boolean'],
            'esiti_confermati' => ['sometimes', 'array', 'max:100'],
            'esiti_confermati.*' => ['integer'],
        ];
    }
}
