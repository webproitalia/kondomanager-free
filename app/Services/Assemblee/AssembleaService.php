<?php

namespace App\Services\Assemblee;

use App\Enums\MaggioranzaAssemblea;
use App\Enums\RuoloAnagraficaImmobile;
use App\Models\Anagrafica;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\AssembleaPresenza;
use App\Models\Assemblee\AssembleaPunto;
use App\Models\Assemblee\Verbale;
use App\Models\Immobile;
use App\Models\TitolaritaImmobile;
use Carbon\CarbonImmutable;

/**
 * Tutto di un'assemblea in una lettura sola (Web Pro Italia, 17/11/2026): l'assemblea, l'ordine del giorno con i voti
 * e i conteggi di ogni punto, le presenze, la costituzione, gli avvisi e il verbale. È quello che ricevono la pagina
 * dell'assemblea e un agente AI che chiede i dati (`assemblee.dati`): la stessa fotografia, così non possono dire due
 * cose diverse.
 */
final class AssembleaService
{
    public function __construct(
        private readonly ConteggiAssemblea $conteggi,
        private readonly VerbaleService $verbali,
    ) {}

    public function fotografia(Assemblea $assemblea): array
    {
        $assemblea->load(['condominio', 'tabella:id,nome', 'esercizio:id,nome', 'presenze.anagrafica:id,nome', 'presenze.delegato:id,nome,user_id', 'punti.voti', 'verbale.documento:id,name', 'verbale.approvatore:id,name', 'verbale.modello:id,nome']);
        $verbale = $this->verbali->verbaleDi($assemblea);
        $totale = $this->conteggi->totale($assemblea);

        return [
            'assemblea' => $this->datiAssemblea($assemblea),
            'punti' => $assemblea->punti->map(fn (AssembleaPunto $p) => $this->datiPunto($assemblea, $p))->values()->all(),
            'presenze' => $this->datiPresenze($assemblea, $totale),
            'conteggi' => [
                'costituzione' => $this->conteggi->costituzione($assemblea),
                'avvisi' => $this->conteggi->avvisi($assemblea),
            ],
            'verbale' => $this->datiVerbale($assemblea, $verbale),
        ];
    }

    public function datiAssemblea(Assemblea $a): array
    {
        return [
            'id' => (int) $a->id,
            'condominio_id' => (int) $a->condominio_id,
            'esercizio_id' => $a->esercizio_id ? (int) $a->esercizio_id : null,
            'esercizio' => $a->esercizio?->nome,
            'tipo' => $a->tipo,
            'titolo' => $a->titolo,
            'data_prima_convocazione' => $a->data_prima_convocazione?->format('Y-m-d H:i'),
            'data_seconda_convocazione' => $a->data_seconda_convocazione?->format('Y-m-d H:i'),
            'convocazione_tenuta' => $a->convocazione_tenuta,
            'data_avviso' => $a->data_avviso?->format('Y-m-d'),
            'luogo' => $a->luogo,
            'modalita' => $a->modalita,
            'collegamento_videoconferenza' => $a->collegamento_videoconferenza,
            'tabella_id' => $a->tabella_id ? (int) $a->tabella_id : null,
            'tabella' => $a->tabella?->nome,
            'millesimi_totali' => $a->millesimi_totali,
            'presidente' => $a->presidente,
            'segretario' => $a->segretario,
            'ora_inizio' => ConteggiAssemblea::ora($a->ora_inizio),
            'ora_fine' => ConteggiAssemblea::ora($a->ora_fine),
            'stato' => $a->stato,
            'registrata' => (bool) $a->registrata,
            'note' => $a->note,
            'bloccata' => $a->bloccata(),
        ];
    }

    private function datiPunto(Assemblea $assemblea, AssembleaPunto $p): array
    {
        $maggioranza = $p->maggioranzaEnum();

        return [
            'id' => (int) $p->id,
            'numero' => (int) $p->numero,
            'titolo' => $p->titolo,
            'descrizione' => $p->descrizione,
            'maggioranza' => $maggioranza->value,
            'maggioranza_etichetta' => $maggioranza->etichetta(),
            'discussione' => $p->discussione,
            'delibera' => $p->delibera,
            'esito' => $p->esito,
            'voti' => $p->voti->map(fn ($v) => ['presenza_id' => (int) $v->assemblea_presenza_id, 'voto' => $v->voto])->values()->all(),
            'votazione' => $this->conteggi->votazione($assemblea, $p),
        ];
    }

    private function datiPresenze(Assemblea $assemblea, int $totale): array
    {
        $idUnita = $assemblea->presenze->flatMap(fn (AssembleaPresenza $p) => $p->idUnita())->unique()->values();
        $unita = Immobile::whereIn('id', $idUnita)->get()->keyBy('id');

        // Chi può rappresentare ogni unità: i titolari di un diritto reale in corso il giorno dell'assemblea.
        $giorno = CarbonImmutable::parse(($assemblea->giorno() ?? now())->toDateString());
        $ruoli = array_map(fn ($r) => $r->value, RuoloAnagraficaImmobile::titolariDiDirittoReale());
        $titolari = TitolaritaImmobile::whereIn('immobile_id', $idUnita)->whereIn('tipologia', $ruoli)->get()
            ->filter(fn (TitolaritaImmobile $r) => $r->inCorsoIl($giorno));
        $nomi = Anagrafica::whereIn('id', $titolari->pluck('anagrafica_id')->unique())->pluck('nome', 'id');
        $perUnita = $titolari->groupBy('immobile_id');

        return $assemblea->presenze->map(function (AssembleaPresenza $p) use ($unita, $perUnita, $nomi, $totale) {
            $candidati = collect($p->idUnita())->flatMap(fn ($id) => $perUnita->get($id, collect()))
                ->unique(fn ($r) => $r->anagrafica_id.'-'.$r->tipologia)
                ->map(fn ($r) => ['id' => (int) $r->anagrafica_id, 'nome' => (string) ($nomi[$r->anagrafica_id] ?? ''), 'ruolo' => RuoloAnagraficaImmobile::tryFrom($r->tipologia)?->label() ?? $r->tipologia])
                ->unique('id')->sortBy('nome')->values()->all();

            return [
                'id' => (int) $p->id,
                // Vuoto se l'anagrafica è stata eliminata: la riga resta, con il nome fotografato.
                'anagrafica_id' => $p->anagrafica_id ? (int) $p->anagrafica_id : null,
                'nome' => ConteggiAssemblea::nomeDi($p),
                'tipo' => $p->tipo,
                'delegato_anagrafica_id' => $p->delegato_anagrafica_id ? (int) $p->delegato_anagrafica_id : null,
                'delegato_nome' => $p->delegato_nome,
                'delegato' => $p->nomeDelegato(),
                'millesimi' => $p->millesimi,
                'millesimi_su_mille' => Millesimi::testoSuMille(Millesimi::daValore($p->millesimi), $totale),
                'unita' => array_map(fn ($id) => ['id' => $id, 'etichetta' => $unita->get($id)?->etichetta_estesa ?? 'Unità '.$id], $p->idUnita()),
                'candidati' => $candidati,
                'contitolari' => array_values(array_filter(array_map(fn ($c) => $c['id'] !== (int) $p->anagrafica_id ? $c['nome'] : null, $candidati))),
                'ora_arrivo' => ConteggiAssemblea::ora($p->ora_arrivo),
                'ora_uscita' => ConteggiAssemblea::ora($p->ora_uscita),
                'in_sala' => $p->inSala(),
            ];
        })->values()->all();
    }

    private function datiVerbale(Assemblea $assemblea, Verbale $verbale): array
    {
        return [
            'id' => (int) $verbale->id,
            'stato' => $verbale->stato,
            'versione' => (int) $verbale->versione,
            'testo' => $verbale->testo,
            'modello_id' => $verbale->modello_id ? (int) $verbale->modello_id : null,
            'modello' => $verbale->modello?->nome,
            'intestazione' => $verbale->intestazione,
            'apertura' => $verbale->apertura,
            'chiusura' => $verbale->chiusura,
            'documento' => $verbale->documento ? ['id' => (int) $verbale->documento->id, 'nome' => $verbale->documento->name] : null,
            'inviato_in_approvazione_at' => $verbale->inviato_in_approvazione_at?->setTimezone(config('app.user_timezone', 'Europe/Rome'))->format('Y-m-d H:i'),
            'approvato_at' => $verbale->approvato_at?->setTimezone(config('app.user_timezone', 'Europe/Rome'))->format('Y-m-d H:i'),
            'approvato_da' => $verbale->approvatore?->name,
            'modificabile' => $verbale->stato === Verbale::BOZZA,
        ];
    }

    /** Le maggioranze, per i menu e la guida. */
    public static function maggioranze(): array
    {
        return array_map(fn (MaggioranzaAssemblea $m) => [
            'valore' => $m->value,
            'etichetta' => $m->etichetta(),
            'a_che_serve' => $m->aCheServe(),
            'regola_prima' => $m->regola('prima'),
            'regola_seconda' => $m->regola('seconda'),
        ], MaggioranzaAssemblea::cases());
    }
}
