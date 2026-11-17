<?php

namespace App\Http\Requests\Gestionale\Assemblee;

use App\Services\Assemblee\InviiAssemblea;
use App\Services\Gestionale\Lettere\TestiLettere;
use App\Services\Gestionale\Ricevute\EmissioneRicevute;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Manda per email l'avviso di convocazione o il verbale approvato (Web Pro Italia, 17/11/2026).
 *
 * È una scrittura verso l'esterno: le email partono davvero. Per questo la richiesta porta **sempre** due cose
 * esplicite, per una persona come per un agente AI:
 *
 * - `conferma: true`: chi manda ha letto l'anteprima «chi riceve che cosa» e conferma;
 * - `persone`: l'elenco delle persone (gli `id` dell'anteprima) a cui mandare. Non c'è un «manda a tutti» implicito.
 *
 * Facoltativi: `indirizzi` (i tipi di indirizzo: `email`, `email_secondaria`, `pec`; senza, primaria e secondaria),
 * `oggetto` e `testo` (senza, quelli del modello dello studio), `anche_gia_mandate` (rimanda anche a chi l'ha già
 * ricevuta per email con lo stesso testo: senza, quelle persone si saltano).
 */
class InviaComunicazioneAssembleaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'conferma' => ['required', 'accepted'],
            'persone' => ['required', 'array', 'min:1', 'max:5000'],
            'persone.*' => ['integer'],
            'indirizzi' => ['sometimes', 'array'],
            'indirizzi.*' => ['string', Rule::in(array_keys(EmissioneRicevute::TIPI_INDIRIZZO))],
            'oggetto' => ['sometimes', 'nullable', 'string', 'max:'.InviiAssemblea::LUNGHEZZA_OGGETTO],
            'testo' => ['sometimes', 'nullable', 'string', 'max:'.TestiLettere::LUNGHEZZA_MASSIMA],
            'anche_gia_mandate' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'conferma.required' => 'Mandare le email è una scrittura verso l\'esterno: leggi l\'anteprima «chi riceve che cosa» e conferma con `conferma: true`.',
            'conferma.accepted' => 'Mandare le email è una scrittura verso l\'esterno: leggi l\'anteprima «chi riceve che cosa» e conferma con `conferma: true`.',
            'persone.required' => 'Di\' a chi mandare: l\'elenco delle persone (`persone`), con gli `id` dell\'anteprima.',
            'persone.min' => 'Scegli almeno una persona.',
            'indirizzi.*.in' => 'I tipi di indirizzo sono: email (primaria), email_secondaria, pec.',
        ];
    }
}
