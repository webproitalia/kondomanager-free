<?php

namespace App\Exceptions\Gestionale;

use App\Helpers\MoneyHelper;
use App\Models\Esercizio;
use App\Models\Gestionale\PianoRate;
use App\Services\Riparto\VociFraPiuPiani;
use RuntimeException;

/**
 * Il piano, insieme agli altri piani validi della stessa gestione e dello stesso esercizio, chiederebbe a una voce più del suo
 * fabbisogno: la stessa spesa due volte (`VociFraPiuPiani`, Web Pro Italia 15/11/2026). Niente è stato scritto: la creazione e il
 * ricalcolo si annullano.
 *
 * Il messaggio è un capoverso solo, come gli altri rifiuti del piano: la pagina lo mostra così com'è. Nomina ogni voce (al più
 * dieci, le altre si contano) con il preventivo, quanto le danno gli altri piani e quali, quanto chiede questo e quanto al massimo
 * potrebbe chiedere. `errori()` ripete le stesse frasi una per voce, con la chiave `capitoli.{id della voce}`: chi lavora per un
 * agente le corregge una alla volta.
 *
 * Estende `RuntimeException` e non `Exception`: un chiamante che non la conosce la tratta come gli altri rifiuti della generazione.
 */
class VociOltreIlPreventivoException extends RuntimeException
{
    /** @var array<string, list<string>> */
    private array $errori = [];

    /**
     * @param  list<array<string, mixed>>  $eccedenze  le voci di `VociFraPiuPiani::eccedenze()`
     * @param  string  $azione  `creazione`, `ricalcolo` o `sincronizzazione` (il ricalcolo che aggiunge voci con «Sincronizza»)
     */
    public function __construct(public readonly PianoRate $pianoRate, public readonly array $eccedenze, public readonly string $azione, ?Esercizio $esercizio = null)
    {
        $n = count($eccedenze);

        $testa = match ($azione) {
            'creazione' => sprintf('Il piano «%s» non si crea', $pianoRate->nome),
            'sincronizzazione' => sprintf('Il piano «%s» non si ricalcola e le voci scelte non si aggiungono', $pianoRate->nome),
            default => sprintf('Il piano «%s» non si ricalcola', $pianoRate->nome),
        };
        $testa .= ($n === 1 ? ': una sua voce, insieme agli altri piani, supererebbe' : sprintf(': %d sue voci, insieme agli altri piani, supererebbero', $n)).' il fabbisogno';
        $testa .= sprintf(', e ai condòmini si chiederebbe due volte la stessa spesa (contano i piani validi della stessa gestione%s, in bozza o approvati).',
            $esercizio !== null ? ' nell\'esercizio «'.$esercizio->nome.'»' : '');

        $frasi = [];
        foreach ($eccedenze as $v) {
            $frase = self::fraseDellaVoce($v);
            $this->errori['capitoli.'.$v['voce_id']] = [$frase];
            $frasi[] = $frase;
        }

        $mostrate = array_slice($frasi, 0, VociFraPiuPiani::ESEMPI);
        $messaggio = $testa.' '.implode(' ', $mostrate);
        if ($n > VociFraPiuPiani::ESEMPI) {
            $messaggio .= sprintf(' E altre %d voci, nello stesso modo.', $n - VociFraPiuPiani::ESEMPI);
        }

        $messaggio .= ' '.match (true) {
            $azione === 'creazione' => 'Per dividere una voce fra più piani scrivi in ognuno solo la sua parte; se invece la voce costa di più, alza prima il suo preventivo nel piano dei conti. Poi rimanda il modulo.',
            $azione === 'sincronizzazione' => 'Una voce che un altro piano copre già non si aggiunge intera: se deve stare anche qui, togli prima la sua parte dall\'altro piano.',
            default => 'Togli la voce dal piano che non la deve chiedere (il cestino accanto alla voce, nella pagina del piano) oppure elimina il piano e ricrealo con la sua parte; se la voce costa davvero di più, alza prima il suo preventivo nel piano dei conti.',
        };

        parent::__construct($messaggio);
    }

    /** @return array<string, list<string>> una frase per voce, con la chiave `capitoli.{id della voce}` */
    public function errori(): array
    {
        return $this->errori;
    }

    /** «Pulizia scale»: preventivo € 1.000,00; gli altri piani le danno già € 600,00 («Acconto» € 600,00); questo piano chiede … */
    public static function fraseDellaVoce(array $v): string
    {
        $f = fn (int $c) => MoneyHelper::format($c);
        $base = $v['tetto'] > $v['preventivo']
            ? sprintf('fabbisogno %s (preventivo %s, fatture registrate %s)', $f($v['tetto']), $f($v['preventivo']), $f($v['speso']))
            : 'preventivo '.$f($v['preventivo']);
        // «Sposta spesa» non conta (`VociFraPiuPiani`): la cifra è quella di prima degli spostamenti, e la frase lo dice.
        $spostato = match (true) {
            ($v['spostato'] ?? 0) > 0 => sprintf(' (senza i %s arrivati con «Sposta spesa», che qui non contano)', $f($v['spostato'])),
            ($v['spostato'] ?? 0) < 0 => sprintf(' (compresi i %s ceduti ad altre voci con «Sposta spesa», che qui contano ancora)', $f(-$v['spostato'])),
            default => '',
        };

        $altri = array_sum(array_column($v['altri'], 'importo'));
        $elenco = implode(', ', array_map(fn ($a) => '«'.$a['nome'].'» '.$f($a['importo']), $v['altri']));
        $resta = max(0, $v['tetto'] - $altri);

        return sprintf('«%s»: %s; %s le %s già %s (%s); questo piano chiede %s%s: in tutto %s, %s oltre. Qui %s.',
            $v['voce'], $base, count($v['altri']) === 1 ? 'un altro piano' : 'gli altri piani', count($v['altri']) === 1 ? 'dà' : 'danno', $f($altri), $elenco,
            $f($v['questo']), $spostato, $f($v['totale']), $f($v['oltre']),
            $resta > 0 ? 'al massimo '.$f($resta) : 'niente: gli altri piani la coprono già tutta');
    }

    public function report(): bool
    {
        return false;
    }
}
