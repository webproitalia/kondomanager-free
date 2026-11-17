<?php

namespace App\Http\Controllers\Gestionale\Assemblee;

use App\Helpers\DateHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\Assemblee\AggiornaPresenzaRequest;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\AssembleaPresenza;
use App\Models\Condominio;
use App\Services\Assemblee\AventiDiritto;
use App\Services\Assemblee\VerbaleService;
use App\Traits\HandleFlashMessages;
use App\Traits\HasCondomini;
use App\Traits\HasEsercizio;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

/**
 * Le presenze dell'assemblea (Web Pro Italia, 17/11/2026): l'elenco degli aventi diritto preparato dai titolari delle
 * unità, e per ognuno presente, per delega o assente, chi lo rappresenta, le ore di arrivo e di uscita. Pensato per la
 * sala: ogni tocco è una richiesta piccola, senza messaggi di conferma che coprirebbero l'elenco.
 */
class PresenzeController extends Controller
{
    use GuardieAssemblea, HandleFlashMessages, HasCondomini, HasEsercizio;

    public function __construct(
        private readonly AventiDiritto $aventiDiritto,
        private readonly VerbaleService $verbali,
    ) {}

    /** Prepara (o riallinea) l'elenco degli aventi diritto dai titolari delle unità e dalla tabella scelta. */
    public function prepara(Condominio $condominio, Assemblea $assemblea): RedirectResponse
    {
        $this->assicuraAssemblea($condominio, $assemblea);
        $this->verbali->assicuraModificabile($assemblea);

        if ($assemblea->tabella_id === null) {
            throw ValidationException::withMessages(['tabella_id' => 'Prima scegli la tabella dei millesimi su cui contare (di norma la generale di proprietà): si sceglie modificando l\'assemblea.']);
        }

        $esito = $this->aventiDiritto->prepara($assemblea);
        $totale = $assemblea->presenze()->count();

        $frase = "Elenco pronto: {$totale} aventi diritto";
        $parti = array_filter([
            $esito['aggiunte'] ? $esito['aggiunte'].' nuovi' : null,
            $esito['tolte'] ? $esito['tolte'].' tolti' : null,
        ]);
        $frase .= $parti ? ' ('.implode(', ', $parti).').' : '.';
        if ($esito['unita_senza_titolari'] !== []) {
            $frase .= ' Unità senza titolari in archivio: '.implode(', ', array_map(fn ($u) => $u['etichetta'], $esito['unita_senza_titolari'])).'.';
        }
        if ($esito['rimaste'] !== []) {
            return back()->with($this->flashWarning($frase.' '.count($esito['rimaste']).' righe non corrispondono più alle unità di adesso ma hanno dei voti: restano, controllale.'));
        }

        return back(fallback: route('admin.gestionale.assemblee.show', ['condominio' => $condominio->id, 'assemblea' => $assemblea->id]))
            ->with($this->flashSuccess($frase));
    }

    public function update(AggiornaPresenzaRequest $request, Condominio $condominio, Assemblea $assemblea, AssembleaPresenza $presenza): RedirectResponse
    {
        $this->assicuraPresenza($condominio, $assemblea, $presenza);
        $this->verbali->assicuraModificabile($assemblea);
        $dati = $request->validated();
        $adesso = DateHelper::adessoUtente()->format('H:i');

        if (array_key_exists('anagrafica_id', $dati) && (int) $dati['anagrafica_id'] !== (int) $presenza->anagrafica_id) {
            $candidati = $this->aventiDiritto->candidati($assemblea, $presenza->idUnita());
            if (! in_array((int) $dati['anagrafica_id'], $candidati, true)) {
                throw ValidationException::withMessages(['anagrafica_id' => 'Può rappresentare queste unità solo uno dei loro titolari (proprietari, nudi proprietari, usufruttuari).']);
            }
            $presenza->anagrafica_id = (int) $dati['anagrafica_id'];
        }

        if (array_key_exists('tipo', $dati)) {
            $presenza->tipo = $dati['tipo'];
            if ($dati['tipo'] !== 'delega') {
                $presenza->delegato_anagrafica_id = null;
                $presenza->delegato_nome = null;
            }
            if ($dati['tipo'] === 'assente') {
                $presenza->ora_arrivo = null;
                $presenza->ora_uscita = null;
            }
        }

        if (array_key_exists('delegato_anagrafica_id', $dati)) {
            $presenza->delegato_anagrafica_id = $dati['delegato_anagrafica_id'];
            if ($dati['delegato_anagrafica_id'] !== null) {
                $presenza->delegato_nome = null;
                $presenza->tipo = 'delega';
            }
        }
        if (array_key_exists('delegato_nome', $dati)) {
            $nome = filled($dati['delegato_nome']) ? trim((string) $dati['delegato_nome']) : null;
            $presenza->delegato_nome = $nome;
            if ($nome !== null) {
                $presenza->delegato_anagrafica_id = null;
                $presenza->tipo = 'delega';
            }
        }

        foreach (['ora_arrivo', 'ora_uscita'] as $campo) {
            if (array_key_exists($campo, $dati)) {
                $presenza->{$campo} = $dati[$campo];
            }
        }

        switch ($dati['segna'] ?? null) {
            case 'arrivo':
                // Arriva adesso, ad assemblea già cominciata: nel verbale «entra alle …».
                if ($presenza->tipo === 'assente') {
                    $presenza->tipo = 'presente';
                }
                $presenza->ora_arrivo = $adesso;
                $presenza->ora_uscita = null;
                break;
            case 'uscita':
                if ($presenza->tipo === 'assente') {
                    throw ValidationException::withMessages(['segna' => 'Non si può segnare l\'uscita di chi è assente.']);
                }
                $presenza->ora_uscita = $adesso;
                break;
            case 'rientro':
                $presenza->ora_uscita = null;
                break;
        }

        $presenza->save();

        return back(fallback: route('admin.gestionale.assemblee.show', ['condominio' => $condominio->id, 'assemblea' => $assemblea->id]));
    }
}
