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
            'kondo_id'                   => ['nullable', 'integer'],
        ]);

        return response()->json($servizio->sincronizza($sorgente, $riferimento, $dati, $request->user()));
    }

    /**
     * Le persone di un condominio (anagrafiche con le loro unità), per riempire la rubrica del sistema esterno.
     * Solo lettura.
     */
    public function persone(Condominio $condominio): JsonResponse
    {
        $unita = \Illuminate\Support\Facades\DB::table('anagrafica_immobile as ai')
            ->join('immobili as i', 'i.id', '=', 'ai.immobile_id')
            ->where('i.condominio_id', $condominio->id)
            ->where(fn ($q) => $q->whereNull('ai.data_fine')->orWhere('ai.data_fine', '>=', now()->toDateString()))
            ->orderBy('i.id')
            ->get(['ai.anagrafica_id', 'i.interno', 'i.nome'])
            ->groupBy('anagrafica_id');

        return response()->json([
            'condominio' => ['id' => $condominio->id, 'nome' => $condominio->nome],
            'persone' => $condominio->anagrafiche()->orderBy('nome')->get()->map(fn ($a) => [
                'id'       => $a->id,
                'nome'     => $a->nome,
                'email'    => $a->email ?: ($a->email_secondaria ?: null),
                'telefono' => $a->cellulare ?: ($a->telefono ?: null),
                'unita'    => ($unita[$a->id] ?? collect())->map(fn ($u) => trim((string) ($u->interno ?: $u->nome)))->filter()->unique()->values()->all(),
            ])->values()->all(),
        ]);
    }

    /**
     * Una comunicazione della bacheca scritta nel sistema esterno (un avviso dell'app): arriva anche qui, una volta
     * sola, e segue le modifiche fatte là. Non parte nessuna notifica del gestionale: ci ha già pensato l'app.
     */
    public function comunicazione(Request $request, string $sorgente, string $riferimento): JsonResponse
    {
        $d = $request->validate([
            'titolo'      => ['required_without:eliminata', 'nullable', 'string', 'max:250'],
            'testo'       => ['nullable', 'string', 'max:50000'],
            'importante'  => ['nullable', 'boolean'],
            'eliminata'   => ['nullable', 'boolean'],
            'condomini'   => ['nullable', 'array'],
            'condomini.*' => ['integer', 'exists:condomini,id'],
        ]);

        return response()->json(\Illuminate\Support\Facades\DB::transaction(function () use ($d, $sorgente, $riferimento, $request) {
            SegnalazioniEsterne::$inCorso = true;

            try {
                $tipo = (new \App\Models\Comunicazione)->getMorphClass();
                $legame = \App\Models\CollegamentoEsterno::where('sorgente', $sorgente)->where('collegabile_type', $tipo)
                    ->where('riferimento', $riferimento)->lockForUpdate()->first();

                if ($legame?->eliminato_at) {
                    return ['scollegata' => true];
                }

                $c = $legame ? \App\Models\Comunicazione::find($legame->collegabile_id) : null;
                $testo = trim(strip_tags((string) ($d['testo'] ?? '')));

                if (! empty($d['eliminata'])) {
                    $c?->update(['is_published' => false, 'updated_by' => $request->user()->id]);

                    return ['id' => $c?->id, 'pubblicata' => false];
                }

                $campi = [
                    'subject' => $d['titolo'], 'description' => $testo !== '' ? $testo : $d['titolo'],
                    'priority' => ! empty($d['importante']) ? 'alta' : 'media', 'is_featured' => ! empty($d['importante']),
                    'updated_by' => $request->user()->id,
                ];

                if ($c) {
                    $c->update($campi + ['is_published' => true]);
                } else {
                    $c = \App\Models\Comunicazione::create($campi + [
                        'created_by' => $request->user()->id, 'is_private' => false, 'is_published' => true,
                        'is_approved' => true, 'can_comment' => false,
                    ]);
                    \App\Models\CollegamentoEsterno::create(['sorgente' => $sorgente, 'riferimento' => $riferimento, 'collegabile_type' => $tipo, 'collegabile_id' => $c->id]);
                }

                if (array_key_exists('condomini', $d)) {
                    $c->condomini()->sync($d['condomini'] ?? []);
                }

                return ['id' => $c->id, 'pubblicata' => true, 'url' => route('admin.comunicazioni.show', $c->id)];
            } finally {
                SegnalazioniEsterne::$inCorso = false;
            }
        }));
    }
}
