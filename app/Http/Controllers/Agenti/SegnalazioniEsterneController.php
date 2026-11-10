<?php

namespace App\Http\Controllers\Agenti;

use App\Http\Controllers\Controller;
use App\Models\Condominio;
use App\Services\Agenti\SegnalazioniEsterne;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Il punto d'ingresso per un sistema esterno che tiene allineate le sue segnalazioni con il gestionale (Web Pro
 * Italia, 10/11/2026). Si usa con una chiave di agente «Consultare e modificare». Vedi SegnalazioniEsterne.
 */
class SegnalazioniEsterneController extends Controller
{
    /** I condomini del gestionale, per dire al sistema esterno a quale corrisponde ciascuno dei suoi. */
    public function condomini(): JsonResponse
    {
        return response()->json([
            'condomini' => Condominio::orderBy('nome')->get(['id', 'nome', 'codice_fiscale', 'indirizzo', 'comune'])
                ->map(fn (Condominio $c) => ['id' => $c->id, 'nome' => $c->nome, 'codice_fiscale' => $c->codice_fiscale, 'indirizzo' => trim($c->indirizzo.' '.$c->comune)])->all(),
        ]);
    }

    public function sincronizza(Request $request, string $sorgente, string $riferimento, SegnalazioniEsterne $servizio): JsonResponse
    {
        $dati = $request->validate([
            'titolo'                     => ['required_without:eliminata', 'nullable', 'string', 'max:500'],
            'testo'                      => ['nullable', 'string', 'max:20000'],
            'stato'                      => ['nullable', 'in:aperta,in_lavorazione,chiusa'],
            'stato_base'                 => ['nullable', 'in:aperta,in_lavorazione,chiusa'],
            'urgente'                    => ['nullable', 'boolean'],
            'urgente_base'               => ['nullable', 'boolean'],
            'eliminata'                  => ['nullable', 'boolean'],
            'creata'                     => ['nullable', 'date'],
            'link'                       => ['nullable', 'url', 'max:500'],
            'condominio'                 => ['nullable', 'array'],
            'condominio.id'              => ['nullable', 'integer'],
            'condominio.nome'            => ['nullable', 'string', 'max:255'],
            'condominio.codice_fiscale'  => ['nullable', 'string', 'max:32'],
            'persona'                    => ['nullable', 'array'],
            'persona.nome'               => ['nullable', 'string', 'max:255'],
            'persona.email'              => ['nullable', 'string', 'max:255'],
            'persona.telefono'           => ['nullable', 'string', 'max:64'],
            'persona.interno'            => ['nullable', 'string', 'max:64'],
            'dettagli'                   => ['nullable', 'array', 'max:12'],
            'commenti'                   => ['nullable', 'array', 'max:500'],
            'commenti.*.rif'             => ['required', 'string', 'max:100'],
            'commenti.*.testo'           => ['nullable', 'string', 'max:20000'],
            'commenti.*.da'              => ['nullable', 'in:studio,condomino'],
            'commenti.*.interna'         => ['nullable', 'boolean'],
            'commenti.*.autore'          => ['nullable', 'string', 'max:255'],
            'commenti.*.quando'          => ['nullable', 'date'],
            'commenti.*.foto'            => ['nullable', 'integer'],
            'commenti.*.kondo_id'        => ['nullable', 'integer'],
        ]);

        return response()->json($servizio->sincronizza($sorgente, $riferimento, $dati, $request->user()));
    }
}
