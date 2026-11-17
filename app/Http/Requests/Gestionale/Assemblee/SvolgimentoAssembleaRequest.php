<?php

namespace App\Http\Requests\Gestionale\Assemblee;

use App\Models\Assemblee\Assemblea;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Come va l'assemblea (Web Pro Italia, 17/11/2026): lo stato, la convocazione tenuta, presidente e segretario, le ore
 * d'inizio e di fine, se la riunione è registrata. Ogni campo è facoltativo: si cambia solo quello che si manda.
 *
 * `adesso` è una scorciatoia per la sala: «inizia» porta l'assemblea in corso e segna l'ora d'inizio (se non c'è),
 * «chiudi» la chiude e segna l'ora di fine. L'ora è quella dell'amministratore, non quella del server.
 */
class SvolgimentoAssembleaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'stato' => ['sometimes', Rule::in(Assemblea::STATI)],
            'convocazione_tenuta' => ['sometimes', 'nullable', Rule::in(Assemblea::CONVOCAZIONI)],
            'presidente' => ['sometimes', 'nullable', 'string', 'max:150'],
            'segretario' => ['sometimes', 'nullable', 'string', 'max:150'],
            'ora_inizio' => ['sometimes', 'nullable', 'date_format:H:i'],
            'ora_fine' => ['sometimes', 'nullable', 'date_format:H:i'],
            'registrata' => ['sometimes', 'boolean'],
            'adesso' => ['sometimes', 'nullable', Rule::in(['inizia', 'chiudi'])],
        ];
    }

    public function messages(): array
    {
        return [
            'ora_inizio.date_format' => 'Scrivi l\'ora d\'inizio come 18:30.',
            'ora_fine.date_format' => 'Scrivi l\'ora di fine come 20:15.',
        ];
    }
}
