<?php

namespace App\Services\Assemblee;

use App\Helpers\DateHelper;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\AssembleaPunto;
use App\Services\Gestionale\Lettere\EmissioneLettere;
use App\Services\PDF\CartaDiStampa;
use Carbon\Carbon;

/**
 * I segnaposto dell'avviso di convocazione e dei testi che accompagnano gli invii dell'assemblea (l'email della
 * convocazione, la trasmissione del verbale), con il loro valore per un'assemblea (Web Pro Italia, 17/11/2026).
 *
 * L'elenco qui sotto è anche la guida: la pagina dei modelli lo mostra così com'è, e un agente lo legge dalla pagina
 * della convocazione. Stesso motore del verbale ({@see CompilatoreModello}): un valore che manca e che l'avviso deve
 * avere (il giorno e l'ora della prima convocazione, il luogo) esce come una riga da compilare; uno facoltativo (la
 * seconda convocazione, il collegamento) esce vuoto, e un blocco `{{#chiave}} … {{/chiave}}` intorno lo fa sparire
 * con le parole che lo accompagnano.
 *
 * ## I segnaposto del destinatario
 *
 * `{{destinatario.nome}}`, `{{destinatario.unita}}`… **restano scritti nel testo dell'avviso** quando lo si genera dal
 * modello: si riempiono alla stampa. Nell'avviso generico diventano una riga da compilare a mano (il modulo di delega
 * in bianco); nella stampa «per persona» e nell'email diventano il nome e le unità di chi lo riceve.
 */
final class SegnapostiConvocazione
{
    /** La riga da compilare a mano: più lunga di quella del verbale, ci si scrive un nome. */
    public const RIGA = '______________________________';

    /** I segnaposto semplici e che cosa diventano. */
    public const ELENCO = [
        'condominio.nome' => 'Il nome del condominio, com\'è in anagrafica («Le Rose»).',
        'condominio.nome_completo' => 'Il nome con «Condominio» davanti una volta sola («Condominio Le Rose»).',
        'condominio.indirizzo' => 'Indirizzo, CAP, comune e provincia del condominio.',
        'condominio.codice_fiscale' => 'Il codice fiscale del condominio (vuoto se manca).',
        'studio.nome' => 'Il nome dello studio, dalle impostazioni di stampa (vuoto se non è scritto).',
        'studio.recapiti' => 'Indirizzo, telefono, email e PEC dello studio su una riga (vuoto se non sono scritti).',
        'amministratore' => 'Chi firma l\'avviso: il nome dello studio (vuoto se non è scritto: resta «L\'amministratore»).',
        'assemblea.titolo' => 'Il titolo dato all\'assemblea.',
        'assemblea.tipo' => '«ordinaria» o «straordinaria».',
        'assemblea.tipo_maiuscolo' => '«ORDINARIA» o «STRAORDINARIA».',
        'prima.giorno' => 'Il giorno della prima convocazione, con il giorno della settimana («domenica 22 novembre 2026»).',
        'prima.ora' => 'L\'ora della prima convocazione («9.00»).',
        'seconda.giorno' => 'Il giorno della seconda convocazione (vuoto se non c\'è).',
        'seconda.ora' => 'L\'ora della seconda convocazione (vuota se non c\'è).',
        'assemblea.prima_convocazione' => 'Giorno e ora della prima convocazione in una frase («22 novembre 2026 alle ore 9.00»).',
        'assemblea.seconda_convocazione' => 'Giorno e ora della seconda convocazione in una frase (vuoto se non c\'è).',
        'assemblea.data' => 'Il giorno dell\'assemblea: quello della convocazione tenuta o, prima che si tenga, della prima convocazione.',
        'assemblea.luogo' => 'Il luogo con la modalità: «presso …», «in videoconferenza», «presso … e in videoconferenza».',
        'assemblea.luogo_semplice' => 'Il luogo com\'è scritto, senza «presso».',
        'assemblea.modalita' => 'La frase della modalità: in presenza, in videoconferenza, o tutte e due.',
        'assemblea.collegamento' => 'Il collegamento della videoconferenza (vuoto se non c\'è).',
        'ordine_del_giorno' => 'I punti numerati, uno per riga, con la descrizione se c\'è.',
        'assemblea.data_avviso' => 'Il giorno dell\'avviso di convocazione; se non è scritto, oggi.',
        'luogo_e_data' => 'Luogo e data dell\'avviso («Frosinone, 17 novembre 2026»): il comune dello studio o del condominio e il giorno dell\'avviso.',
        'oggi' => 'Il giorno di oggi.',
    ];

    /** I segnaposto dentro il blocco `{{#punti}} … {{/punti}}`, che si ripete per ogni punto dell'ordine del giorno. */
    public const PUNTI = [
        'numero' => 'Il numero del punto.',
        'titolo' => 'Il titolo del punto.',
        'descrizione' => 'La descrizione del punto (vuota se non c\'è).',
    ];

    /** I segnaposto di chi riceve: restano nel testo dell'avviso e si riempiono alla stampa. */
    public const DESTINATARIO = [
        'destinatario.nome' => 'Il nome di chi riceve; nell\'avviso generico, una riga da compilare.',
        'destinatario.indirizzo' => 'L\'indirizzo postale di chi riceve (vuoto se manca, e nell\'avviso generico).',
        'destinatario.unita' => 'Le sue unità («Int. 3, Box 12»); nell\'avviso generico, una riga da compilare.',
        'destinatario.millesimi' => 'I millesimi che rappresenta, su 1.000; nell\'avviso generico, una riga da compilare.',
    ];

    /**
     * I valori per un'assemblea: i segnaposto semplici (senza quelli del destinatario) e l'elenco dei punti.
     *
     * @return array{0: array<string, string>, 1: array<string, list<array<string, string>>>}
     */
    public function valori(Assemblea $assemblea): array
    {
        $assemblea->loadMissing(['condominio', 'punti']);
        $c = $assemblea->condominio;
        $studio = CartaDiStampa::studio();
        $prima = $assemblea->data_prima_convocazione;
        $seconda = $assemblea->data_seconda_convocazione;
        $giorno = $assemblea->giorno();
        $luogo = trim((string) $assemblea->luogo);
        $tipo = $assemblea->tipo === 'straordinaria' ? 'straordinaria' : 'ordinaria';
        $avviso = $assemblea->data_avviso ?? Carbon::parse(DateHelper::oggiUtente());
        $comune = EmissioneLettere::luogo($c);

        $recapiti = implode(' — ', array_filter([
            $studio['indirizzo'],
            $studio['telefono'] !== null ? 'tel. '.$studio['telefono'] : null,
            $studio['email'],
            $studio['pec'] !== null ? 'PEC '.$studio['pec'] : null,
        ]));

        $valori = [
            'condominio.nome' => trim((string) $c->nome),
            'condominio.nome_completo' => VerbaleService::condominioConNome((string) $c->nome),
            'condominio.indirizzo' => self::suUnaRiga(SegnapostiVerbale::indirizzoDelCondominio($c)),
            'condominio.codice_fiscale' => trim((string) $c->codice_fiscale),
            'studio.nome' => (string) ($studio['nome'] ?? ''),
            'studio.recapiti' => $recapiti,
            'amministratore' => (string) ($studio['nome'] ?? ''),
            'assemblea.titolo' => trim((string) $assemblea->titolo),
            'assemblea.tipo' => $tipo,
            'assemblea.tipo_maiuscolo' => mb_strtoupper($tipo),
            'prima.giorno' => $prima ? self::giornoConLaSettimana($prima) : SegnapostiVerbale::VUOTO,
            'prima.ora' => $prima && $prima->format('H:i') !== '00:00' ? $prima->format('G.i') : SegnapostiVerbale::VUOTO,
            'seconda.giorno' => $seconda ? self::giornoConLaSettimana($seconda) : '',
            'seconda.ora' => $seconda ? ($seconda->format('H:i') !== '00:00' ? $seconda->format('G.i') : SegnapostiVerbale::VUOTO) : '',
            'assemblea.prima_convocazione' => $prima ? SegnapostiVerbale::giornoEOra($prima) : SegnapostiVerbale::VUOTO,
            'assemblea.seconda_convocazione' => $seconda ? SegnapostiVerbale::giornoEOra($seconda) : '',
            'assemblea.data' => $giorno ? SegnapostiVerbale::giorno($giorno) : SegnapostiVerbale::VUOTO,
            'assemblea.luogo' => SegnapostiVerbale::dove($assemblea),
            'assemblea.luogo_semplice' => $luogo !== '' ? $luogo : SegnapostiVerbale::VUOTO,
            'assemblea.modalita' => match ($assemblea->modalita) {
                'videoconferenza' => 'L\'assemblea si tiene in videoconferenza.',
                'mista' => 'All\'assemblea si può partecipare di persona oppure in videoconferenza.',
                default => 'L\'assemblea si tiene in presenza.',
            },
            'assemblea.collegamento' => trim((string) $assemblea->collegamento_videoconferenza),
            'ordine_del_giorno' => $assemblea->punti->map(fn (AssembleaPunto $p) => $p->numero.'. '.trim((string) $p->titolo)
                .(filled($p->descrizione) ? ' — '.self::suUnaRiga((string) $p->descrizione) : ''))->implode("\n"),
            'assemblea.data_avviso' => SegnapostiVerbale::giorno($avviso),
            'luogo_e_data' => ($comune !== null ? $comune.', ' : '').SegnapostiVerbale::giorno($avviso),
            'oggi' => SegnapostiVerbale::giorno(DateHelper::adessoUtente()),
        ];

        $punti = $assemblea->punti->map(fn (AssembleaPunto $p) => [
            'numero' => (string) $p->numero,
            'titolo' => trim((string) $p->titolo),
            'descrizione' => self::suUnaRiga((string) $p->descrizione),
        ])->values()->all();

        return [$valori, ['punti' => $punti]];
    }

    /**
     * I valori del destinatario. Senza una persona (l'avviso generico) il nome, le unità e i millesimi sono righe da
     * compilare a mano, e l'indirizzo è vuoto.
     *
     * @param  array{nome: string, indirizzo?: ?string, unita?: list<string>, millesimi?: ?string}|null  $destinatario
     * @return array<string, string>
     */
    public static function destinatario(?array $destinatario): array
    {
        if ($destinatario === null) {
            return [
                'destinatario.nome' => self::RIGA,
                'destinatario.indirizzo' => '',
                'destinatario.unita' => SegnapostiVerbale::VUOTO.SegnapostiVerbale::VUOTO,
                'destinatario.millesimi' => SegnapostiVerbale::VUOTO,
            ];
        }

        $unita = implode(', ', (array) ($destinatario['unita'] ?? []));

        return [
            'destinatario.nome' => trim((string) $destinatario['nome']),
            'destinatario.indirizzo' => trim((string) ($destinatario['indirizzo'] ?? '')),
            'destinatario.unita' => $unita !== '' ? $unita : SegnapostiVerbale::VUOTO.SegnapostiVerbale::VUOTO,
            'destinatario.millesimi' => filled($destinatario['millesimi'] ?? null) ? (string) $destinatario['millesimi'] : SegnapostiVerbale::VUOTO,
        ];
    }

    /** Tutte le chiavi che un modello di questi tipi può usare, per segnalare quelle sconosciute. */
    public static function chiavi(): array
    {
        return array_merge(array_keys(self::ELENCO), array_keys(self::PUNTI), array_keys(self::DESTINATARIO));
    }

    /** «domenica 22 novembre 2026». */
    public static function giornoConLaSettimana(Carbon $data): string
    {
        return $data->locale('it')->translatedFormat('l j F Y');
    }

    private static function suUnaRiga(string $testo): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $testo));
    }
}
