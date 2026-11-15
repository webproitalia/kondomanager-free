<?php

namespace App\Http\Requests\Condominio;

use App\Models\Condominio;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

/**
 * Eliminare un condominio con tutto il suo contenuto (Web Pro Italia, 15/11/2026): chi lo chiede deve riscrivere
 * il nome del condominio. Non è una formalità: l'indirizzo porta solo un numero, e un numero sbagliato di una cifra
 * è un altro condominio. Il nome dice che si sa quale si sta buttando.
 */
class EliminaCondominioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('delete', Condominio::class);
    }

    public function rules(): array
    {
        return [
            'conferma_nome' => ['required', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $condominio = $this->route('condominio');
            $atteso = $condominio instanceof Condominio ? self::piano((string) $condominio->nome) : null;

            if ($atteso === null || self::piano((string) $this->input('conferma_nome')) !== $atteso) {
                $validator->errors()->add('conferma_nome', 'Il nome scritto non è quello del condominio: per eliminarlo va riscritto il suo nome, uguale.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'conferma_nome.required' => 'Per eliminare il condominio con tutto il suo contenuto va riscritto il suo nome.',
        ];
    }

    /** Stesso nome a meno di maiuscole e spazi doppi: l'intenzione è chiara, la pignoleria non aggiunge sicurezza. */
    private static function piano(string $testo): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $testo)));
    }
}
