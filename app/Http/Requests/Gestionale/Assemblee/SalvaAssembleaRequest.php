<?php

namespace App\Http\Requests\Gestionale\Assemblee;

use App\Enums\MaggioranzaAssemblea;
use App\Models\Assemblee\Assemblea;
use App\Models\Condominio;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Un'assemblea nuova o corretta: tipo, titolo, date delle convocazioni, luogo, modalità, tabella dei conteggi,
 * esercizio e ordine del giorno (Web Pro Italia, 17/11/2026).
 *
 * L'ordine del giorno arriva intero, nell'ordine in cui va: un punto con `id` è uno che c'è già (si corregge e si
 * rinumera), uno senza `id` è nuovo, uno che manca si toglie (se non ha voti). In modifica ogni campo è facoltativo:
 * si cambia solo quello che si manda, e senza `punti` l'ordine del giorno resta com'è.
 */
class SalvaAssembleaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $condominio = $this->route('condominio');
        $condominioId = $condominio instanceof Condominio ? $condominio->id : $condominio;
        $nuova = $this->isMethod('post');
        $obbligatorio = $nuova ? 'required' : 'sometimes';

        return [
            'tipo' => [$obbligatorio, Rule::in(Assemblea::TIPI)],
            'titolo' => [$obbligatorio, 'string', 'max:200'],
            'esercizio_id' => ['nullable', 'integer', Rule::exists('esercizi', 'id')->where('condominio_id', $condominioId)],
            'data_prima_convocazione' => ['nullable', 'date'],
            'data_seconda_convocazione' => ['nullable', 'date', Rule::when($this->filled('data_prima_convocazione'), ['after_or_equal:data_prima_convocazione'])],
            'data_avviso' => ['nullable', 'date'],
            'luogo' => ['nullable', 'string', 'max:255'],
            'modalita' => [$obbligatorio, Rule::in(Assemblea::MODALITA)],
            'tabella_id' => ['nullable', 'integer', Rule::exists('tabelle', 'id')->where('condominio_id', $condominioId)],
            'note' => ['nullable', 'string', 'max:5000'],
            'punti' => ['sometimes', 'array', 'max:100'],
            'punti.*.id' => ['nullable', 'integer'],
            'punti.*.titolo' => ['required', 'string', 'max:500'],
            'punti.*.descrizione' => ['nullable', 'string', 'max:5000'],
            'punti.*.maggioranza' => ['required', Rule::in(MaggioranzaAssemblea::values())],
        ];
    }

    public function messages(): array
    {
        return [
            'titolo.required' => 'Scrivi un titolo per l\'assemblea, per esempio «Assemblea ordinaria 2026».',
            'data_seconda_convocazione.after_or_equal' => 'La seconda convocazione non può essere prima della prima.',
            'punti.*.titolo.required' => 'Ogni punto dell\'ordine del giorno ha bisogno di un titolo.',
            'punti.*.maggioranza.required' => 'Scegli la maggioranza di ogni punto.',
            'esercizio_id.exists' => 'L\'esercizio scelto non è di questo condominio.',
            'tabella_id.exists' => 'La tabella scelta non è di questo condominio.',
        ];
    }
}
