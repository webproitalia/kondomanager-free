<?php

namespace App\Services\Assemblee;

use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\AssembleaPresenza;
use App\Models\Assemblee\AssembleaPunto;
use App\Models\Immobile;
use Carbon\Carbon;

/**
 * I segnaposto dei modelli di verbale, con il loro valore per un'assemblea (Web Pro Italia, 17/11/2026).
 *
 * L'elenco qui sotto è anche la guida: la pagina dei modelli lo mostra così com'è, e un agente lo legge dai dati
 * dell'assemblea. Un valore che manca e che il verbale deve avere (presidente, ora, luogo…) esce come «______»,
 * perché chi rilegge lo veda; un valore facoltativo (l'avviso di convocazione, la discussione di un punto) esce vuoto,
 * e un blocco `{{#chiave}} … {{/chiave}}` intorno lo fa sparire con le parole che lo accompagnano.
 *
 * Le fonti sono i dati dell'assemblea: le presenze e i voti per i conteggi (`ConteggiAssemblea`), i campi dei punti
 * per discussione e delibera.
 */
final class SegnapostiVerbale
{
    public const VUOTO = '______';

    /** I segnaposto semplici e che cosa diventano. */
    public const ELENCO = [
        'condominio.nome' => 'Il nome del condominio, com\'è in anagrafica («Le Rose»).',
        'condominio.nome_completo' => 'Il nome con «Condominio» davanti una volta sola («Condominio Le Rose»).',
        'condominio.indirizzo' => 'Indirizzo, CAP, comune e provincia.',
        'condominio.codice_fiscale' => 'Il codice fiscale del condominio (vuoto se manca).',
        'assemblea.titolo' => 'Il titolo dato all\'assemblea.',
        'assemblea.tipo' => '«ordinaria» o «straordinaria».',
        'assemblea.tipo_maiuscolo' => '«ORDINARIA» o «STRAORDINARIA».',
        'assemblea.data' => 'Il giorno dell\'assemblea, nella convocazione tenuta («23 novembre 2026»).',
        'assemblea.ora_inizio' => 'L\'ora d\'inizio («18.30»).',
        'assemblea.ora_fine' => 'L\'ora di chiusura.',
        'ora_fine' => 'Come «assemblea.ora_fine».',
        'assemblea.luogo' => 'Il luogo con la modalità: «presso …», «in videoconferenza», «presso … e in videoconferenza».',
        'assemblea.luogo_semplice' => 'Il luogo com\'è scritto, senza «presso».',
        'assemblea.convocazione' => '«prima» o «seconda».',
        'assemblea.prima_convocazione' => 'Giorno e ora della prima convocazione.',
        'assemblea.seconda_convocazione' => 'Giorno e ora della seconda convocazione.',
        'assemblea.data_avviso' => 'Il giorno dell\'avviso di convocazione (vuoto se non è scritto).',
        'assemblea.tabella' => 'Il nome della tabella dei millesimi usata per i conteggi.',
        'presidente' => 'Chi presiede l\'assemblea.',
        'segretario' => 'Chi fa da segretario.',
        'ordine_del_giorno' => 'I punti numerati, uno per riga.',
        'costituzione' => 'La frase della costituzione: teste e millesimi intervenuti all\'apertura, e se l\'assemblea è validamente costituita.',
        'costituzione.teste' => 'Quanti condòmini sono intervenuti all\'apertura.',
        'costituzione.teste_totali' => 'Quanti sono i condòmini aventi diritto.',
        'costituzione.millesimi' => 'I millesimi intervenuti all\'apertura, su 1.000.',
        'costituzione.esito' => '«è validamente costituita» o «non risulta validamente costituita».',
        'registrazione.avviso' => 'Se la riunione è registrata: la frase che lo dice ai presenti; altrimenti vuoto.',
        'presenze.tabella' => 'L\'elenco dei presenti: nome, unità, millesimi, di persona o per delega, entrate e uscite.',
        'assenti' => 'I nomi degli assenti, separati da virgole (vuoto se non ce ne sono).',
        'intestazione' => 'Il testo «intestazione» scritto nel verbale (vuoto se non c\'è).',
        'apertura' => 'Il testo «apertura» scritto nel verbale (vuoto se non c\'è).',
        'chiusura' => 'Il testo «chiusura» scritto nel verbale (vuoto se non c\'è).',
        'firme' => 'Le righe per le firme di presidente e segretario.',
        'oggi' => 'Il giorno di oggi.',
    ];

    /** I segnaposto dentro il blocco `{{#punti}} … {{/punti}}`, che si ripete per ogni punto dell'ordine del giorno. */
    public const PUNTI = [
        'numero' => 'Il numero del punto.',
        'titolo' => 'Il titolo del punto.',
        'descrizione' => 'La descrizione del punto nell\'ordine del giorno (vuota se non c\'è).',
        'discussione' => 'La discussione scritta per il punto (vuota se non c\'è).',
        'delibera' => 'La delibera scritta per il punto (vuota se non c\'è).',
        'votazione' => 'Favorevoli, contrari e astenuti, con i nomi e i millesimi (tre righe); per un punto informativo, la frase che lo dice.',
        'favorevoli' => 'Solo la riga dei favorevoli.',
        'contrari' => 'Solo la riga dei contrari.',
        'astenuti' => 'Solo la riga degli astenuti.',
        'maggioranza' => '«Maggioranza richiesta: …» (vuoto per un punto informativo).',
        'esito' => 'La frase dell\'esito confermato («L\'assemblea approva.»); se non è confermato lo dice.',
        'esito.parola' => '«approvato», «respinto», «rinviato», «informativa» (vuoto se non è confermato).',
    ];

    public function __construct(private readonly ConteggiAssemblea $conteggi) {}

    /**
     * I valori per un'assemblea: i segnaposto semplici e l'elenco dei punti.
     *
     * @param  array{intestazione?:?string, apertura?:?string, chiusura?:?string}  $testiScritti
     * @return array{0: array<string, string>, 1: array<string, list<array<string, string>>>}
     */
    public function valori(Assemblea $assemblea, array $testiScritti = []): array
    {
        $assemblea->loadMissing(['condominio', 'tabella', 'presenze.anagrafica', 'presenze.delegato', 'punti.voti']);
        $c = $assemblea->condominio;
        $giorno = $assemblea->giorno();
        $oraInizio = ConteggiAssemblea::ora($assemblea->ora_inizio) ?? ($giorno && $giorno->format('H:i') !== '00:00' ? $giorno->format('H:i') : null);
        $oraFine = ConteggiAssemblea::ora($assemblea->ora_fine);

        $presentiAllApertura = $this->conteggi->presentiAllApertura($assemblea);
        $costituzione = $this->conteggi->costituzione($assemblea, $presentiAllApertura);
        $convocazione = $assemblea->convocazione_tenuta;
        $valida = $costituzione['per_convocazione'][$convocazione ?? $assemblea->convocazione()];
        $esito = $valida ? 'è validamente costituita' : 'non risulta validamente costituita';
        $tabella = $assemblea->tabella ? ' (tabella «'.$assemblea->tabella->nome.'»)' : '';

        $fraseCostituzione = sprintf(
            'Il presidente, verificate le presenze, constata che sono intervenuti, di persona o per delega, %d condòmini su %d, che rappresentano %s millesimi su 1.000%s, come risulta dall\'elenco dei presenti in calce al presente verbale. L\'assemblea, in %s convocazione, %s: servono %s.',
            $costituzione['teste_presenti'], $costituzione['teste_totali'], $costituzione['millesimi_presenti_testo'], $tabella,
            $convocazione ?? self::VUOTO, $esito, rtrim(ConteggiAssemblea::requisitiCostituzione($convocazione ?? $assemblea->convocazione()), '.'),
        );

        $luogo = trim((string) $assemblea->luogo);
        $dove = self::dove($assemblea);
        $indirizzo = self::indirizzoDelCondominio($c);

        $elenco = $this->elencoPresenti($assemblea);
        $presidente = filled($assemblea->presidente) ? trim((string) $assemblea->presidente) : self::VUOTO;
        $segretario = filled($assemblea->segretario) ? trim((string) $assemblea->segretario) : self::VUOTO;
        $tipo = $assemblea->tipo === 'straordinaria' ? 'straordinaria' : 'ordinaria';

        $valori = [
            'condominio.nome' => trim((string) $c->nome),
            'condominio.nome_completo' => VerbaleService::condominioConNome((string) $c->nome),
            'condominio.indirizzo' => $indirizzo,
            'condominio.codice_fiscale' => trim((string) $c->codice_fiscale),
            'assemblea.titolo' => trim((string) $assemblea->titolo),
            'assemblea.tipo' => $tipo,
            'assemblea.tipo_maiuscolo' => mb_strtoupper($tipo),
            'assemblea.data' => $giorno ? self::giorno($giorno) : self::VUOTO,
            'assemblea.ora_inizio' => $oraInizio ? str_replace(':', '.', $oraInizio) : self::VUOTO,
            'assemblea.ora_fine' => $oraFine ? str_replace(':', '.', $oraFine) : self::VUOTO,
            'ora_fine' => $oraFine ? str_replace(':', '.', $oraFine) : self::VUOTO,
            'assemblea.luogo' => $dove,
            'assemblea.luogo_semplice' => $luogo !== '' ? $luogo : self::VUOTO,
            'assemblea.convocazione' => $convocazione ?? self::VUOTO,
            'assemblea.prima_convocazione' => $assemblea->data_prima_convocazione ? self::giornoEOra($assemblea->data_prima_convocazione) : '',
            'assemblea.seconda_convocazione' => $assemblea->data_seconda_convocazione ? self::giornoEOra($assemblea->data_seconda_convocazione) : '',
            'assemblea.data_avviso' => $assemblea->data_avviso ? self::giorno($assemblea->data_avviso) : '',
            'assemblea.tabella' => $assemblea->tabella?->nome ?? '',
            'presidente' => $presidente,
            'segretario' => $segretario,
            'ordine_del_giorno' => $assemblea->punti->map(fn (AssembleaPunto $p) => $p->numero.'. '.$p->titolo)->implode("\n"),
            'costituzione' => $fraseCostituzione,
            'costituzione.teste' => (string) $costituzione['teste_presenti'],
            'costituzione.teste_totali' => (string) $costituzione['teste_totali'],
            'costituzione.millesimi' => $costituzione['millesimi_presenti_testo'],
            'costituzione.esito' => $esito,
            'registrazione.avviso' => $assemblea->registrata ? 'La riunione viene registrata al solo fine di redigere il verbale, e i presenti ne sono informati.' : '',
            'presenze.tabella' => $elenco['righe'] !== [] ? implode("\n", array_map(fn ($r) => '- '.$r, $elenco['righe'])) : 'Nessun presente registrato.',
            'assenti' => implode(', ', $elenco['assenti']),
            'intestazione' => trim((string) ($testiScritti['intestazione'] ?? '')),
            'apertura' => trim((string) ($testiScritti['apertura'] ?? '')),
            'chiusura' => trim((string) ($testiScritti['chiusura'] ?? '')),
            'firme' => "Il presidente: ______________________ ({$presidente})\nIl segretario: ______________________ ({$segretario})",
            'oggi' => self::giorno(\App\Helpers\DateHelper::adessoUtente()),
        ];

        $punti = $assemblea->punti->map(fn (AssembleaPunto $p) => $this->punto($assemblea, $p))->values()->all();

        return [$valori, ['punti' => $punti]];
    }

    /** I valori di un punto, per il blocco `{{#punti}}`. */
    public function punto(Assemblea $assemblea, AssembleaPunto $p): array
    {
        $v = $this->conteggi->votazione($assemblea, $p);

        $riga = function (string $titolo, array $g) {
            if ($g['teste'] === 0) {
                return "{$titolo}: nessuno.";
            }
            $nomi = implode(', ', array_map(fn ($n) => $n['nome'].($n['delegato'] ? ' per delega a '.$n['delegato'] : '').' ('.$n['millesimi_testo'].')', $g['nomi']));
            $chi = $g['teste'] === 1 ? '1 condòmino' : $g['teste'].' condòmini';

            return "{$titolo}: {$chi} per {$g['millesimi_testo']} millesimi ({$nomi}).";
        };

        if ($v['informativa']) {
            $votazione = 'Punto informativo: nessuna votazione.';
            [$fav, $con, $ast] = ['', '', ''];
        } elseif ($v['intervenuti']['teste'] === 0) {
            $votazione = 'Nessuna votazione registrata.';
            [$fav, $con, $ast] = ['', '', ''];
        } else {
            [$fav, $con, $ast] = [$riga('Favorevoli', $v['favorevoli']), $riga('Contrari', $v['contrari']), $riga('Astenuti', $v['astenuti'])];
            $votazione = "{$fav}\n{$con}\n{$ast}";
        }

        $esito = match ($p->esito) {
            'approvato' => 'L\'assemblea approva.',
            'respinto' => 'L\'assemblea respinge.',
            'rinviato' => 'Il punto è rinviato.',
            'informativa' => '',
            default => $v['informativa'] ? '' : 'Esito da confermare'.($v['esito_proposto'] ? ' (secondo i voti: '.$v['esito_proposto'].')' : '').'.',
        };

        return [
            'numero' => (string) $p->numero,
            'titolo' => trim((string) $p->titolo),
            'descrizione' => trim((string) $p->descrizione),
            'discussione' => trim((string) $p->discussione),
            'delibera' => trim((string) $p->delibera),
            'votazione' => $votazione,
            'favorevoli' => $fav,
            'contrari' => $con,
            'astenuti' => $ast,
            'maggioranza' => $v['informativa'] ? '' : 'Maggioranza richiesta: '.rtrim($v['regola'], '.').'.',
            'esito' => $esito,
            'esito.parola' => (string) ($p->esito ?? ''),
        ];
    }

    /**
     * L'elenco dei presenti, una riga ciascuno, e i nomi degli assenti.
     *
     * @return array{righe: list<string>, assenti: list<string>}
     */
    public function elencoPresenti(Assemblea $assemblea): array
    {
        $assemblea->loadMissing(['presenze.anagrafica', 'presenze.delegato']);
        $totale = $this->conteggi->totale($assemblea);
        $etichette = Immobile::whereIn('id', $assemblea->presenze->flatMap(fn (AssembleaPresenza $p) => $p->idUnita())->unique()->values())
            ->get()->mapWithKeys(fn ($u) => [(int) $u->id => $u->etichetta])->all();
        $inizio = ConteggiAssemblea::ora($assemblea->ora_inizio);

        $righe = [];
        $assenti = [];
        foreach ($assemblea->presenze as $p) {
            $nome = ConteggiAssemblea::nomeDi($p);
            if (! $p->intervenuto()) {
                $assenti[] = $nome;
                continue;
            }
            $arrivo = ConteggiAssemblea::ora($p->ora_arrivo);
            $uscita = ConteggiAssemblea::ora($p->ora_uscita);
            $parti = array_filter([
                $nome,
                implode(', ', array_map(fn ($id) => $etichette[$id] ?? 'unità '.$id, $p->idUnita())),
                Millesimi::testoSuMille(Millesimi::daValore($p->millesimi), $totale).' millesimi',
                $p->tipo === 'delega' ? 'per delega a '.($p->nomeDelegato() ?? self::VUOTO) : 'di persona',
                $arrivo && $inizio && $arrivo > $inizio ? 'entra alle '.str_replace(':', '.', $arrivo) : null,
                $uscita ? 'esce alle '.str_replace(':', '.', $uscita) : null,
            ], fn ($v) => $v !== null && $v !== '');
            $righe[] = implode(' — ', $parti);
        }

        return ['righe' => $righe, 'assenti' => $assenti];
    }

    /**
     * Il luogo con la modalità, come si scrive in una frase: «presso …», «in videoconferenza», «presso … e in
     * videoconferenza». Lo usano il verbale e l'avviso di convocazione.
     */
    public static function dove(Assemblea $assemblea): string
    {
        $luogo = trim((string) $assemblea->luogo);
        // Chi scrive il luogo spesso mette già «presso…», «nella…», «in…»: non si ripete («presso presso la sala»).
        $conPresso = preg_match('/^(presso|nell[aeo\']?|nei|negli|in|al|all[aeo\']?|ai|agli|sul|sull[aeo\']?)\b/iu', $luogo) === 1 ? $luogo : 'presso '.($luogo !== '' ? $luogo : self::VUOTO);

        return match ($assemblea->modalita) {
            'videoconferenza' => 'in videoconferenza'.($luogo !== '' ? ' ('.$luogo.')' : ''),
            'mista' => $conPresso.' e in videoconferenza',
            default => $conPresso,
        };
    }

    /** Indirizzo, CAP, comune e provincia del condominio su una riga. */
    public static function indirizzoDelCondominio(\App\Models\Condominio $c): string
    {
        return trim(implode(', ', array_filter([
            trim((string) $c->indirizzo),
            trim(implode(' ', array_filter([$c->cap, $c->comune, filled($c->provincia) ? '('.$c->provincia.')' : null]))),
        ])));
    }

    public static function giorno(Carbon $data): string
    {
        return $data->locale('it')->translatedFormat('j F Y');
    }

    public static function giornoEOra(Carbon $data): string
    {
        $ora = $data->format('H:i');

        return self::giorno($data).($ora !== '00:00' ? ' alle ore '.str_replace(':', '.', $ora) : '');
    }
}
