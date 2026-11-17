<?php

namespace App\Http\Controllers\Gestionale\Assemblee;

use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\Assemblee\AggiornaPuntoRequest;
use App\Http\Requests\Gestionale\Assemblee\VotiPuntoRequest;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\AssembleaPresenza;
use App\Models\Assemblee\AssembleaPunto;
use App\Models\Assemblee\AssembleaVoto;
use App\Models\Condominio;
use App\Services\Assemblee\VerbaleService;
use App\Traits\HandleFlashMessages;
use App\Traits\HasCondomini;
use App\Traits\HasEsercizio;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * I punti dell'ordine del giorno durante e dopo l'assemblea (Web Pro Italia, 17/11/2026): i voti (con il tocco
 * «tutti i presenti favorevoli» e poi i pochi da cambiare), la discussione, la delibera e l'esito confermato.
 */
class PuntiController extends Controller
{
    use GuardieAssemblea, HandleFlashMessages, HasCondomini, HasEsercizio;

    public function __construct(private readonly VerbaleService $verbali) {}

    public function voti(VotiPuntoRequest $request, Condominio $condominio, Assemblea $assemblea, AssembleaPunto $punto): RedirectResponse
    {
        $this->assicuraPunto($condominio, $assemblea, $punto);
        $this->verbali->assicuraModificabile($assemblea);
        $dati = $request->validated();

        if ($punto->informativo() && (! empty($dati['tutti']) || ! empty($dati['voti']))) {
            throw ValidationException::withMessages(['voti' => 'Il punto '.$punto->numero.' è informativo: non si vota. Se va votato, cambia la sua maggioranza nell\'ordine del giorno.']);
        }

        $presenze = AssembleaPresenza::with('anagrafica:id,nome')->where('assemblea_id', $assemblea->id)->get()->keyBy('id');
        foreach ($dati['voti'] ?? [] as $i => $v) {
            $presenza = $presenze->get((int) $v['presenza_id']);
            if ($presenza === null) {
                throw ValidationException::withMessages(["voti.{$i}.presenza_id" => 'Questa presenza non è di questa assemblea.']);
            }
            // Chi è segnato assente non vota (togliere un voto invece si può sempre). Chi è uscito a metà è intervenuto: i suoi voti valgono.
            if (($v['voto'] ?? null) !== null && ! $presenza->intervenuto()) {
                throw ValidationException::withMessages(["voti.{$i}.presenza_id" => $presenza->nomePersona().' è segnato assente: non si registra il suo voto. Se ha partecipato, segnalo prima presente o per delega dall\'elenco delle presenze, poi registra il voto.']);
            }
        }

        DB::transaction(function () use ($dati, $punto, $presenze) {
            if (! empty($dati['azzera'])) {
                AssembleaVoto::where('assemblea_punto_id', $punto->id)->delete();
            }
            if (! empty($dati['tutti'])) {
                foreach ($presenze->filter(fn (AssembleaPresenza $p) => $p->inSala()) as $p) {
                    AssembleaVoto::updateOrCreate(['assemblea_punto_id' => $punto->id, 'assemblea_presenza_id' => $p->id], ['voto' => $dati['tutti']]);
                }
            }
            foreach ($dati['voti'] ?? [] as $v) {
                $chiave = ['assemblea_punto_id' => $punto->id, 'assemblea_presenza_id' => (int) $v['presenza_id']];
                if (($v['voto'] ?? null) === null) {
                    AssembleaVoto::where($chiave)->delete();
                } else {
                    AssembleaVoto::updateOrCreate($chiave, ['voto' => $v['voto']]);
                }
            }
        });

        return back(fallback: route('admin.gestionale.assemblee.show', ['condominio' => $condominio->id, 'assemblea' => $assemblea->id]));
    }

    public function update(AggiornaPuntoRequest $request, Condominio $condominio, Assemblea $assemblea, AssembleaPunto $punto): RedirectResponse
    {
        $this->assicuraPunto($condominio, $assemblea, $punto);
        $this->verbali->assicuraModificabile($assemblea);

        foreach ($request->validated() as $campo => $valore) {
            $punto->{$campo} = is_string($valore) && trim($valore) === '' ? null : $valore;
        }
        $punto->save();

        return back(fallback: route('admin.gestionale.assemblee.show', ['condominio' => $condominio->id, 'assemblea' => $assemblea->id]))
            ->with($this->flashSuccess('Punto '.$punto->numero.' salvato.'));
    }
}
