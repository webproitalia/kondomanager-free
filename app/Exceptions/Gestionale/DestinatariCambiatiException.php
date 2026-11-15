<?php

namespace App\Exceptions\Gestionale;

use Exception;

/**
 * Il cancello (2) della decisione 14: la risoluzione **temporale** dei titolari ha cambiato un
 * destinatario o un peso rispetto a quella atemporale — o il piano contiene una pregressa registrata
 * senza periodo (decisione 26, 1.11.0-beta.35) — e chi genera non ha ancora detto di averlo letto.
 *
 * Stesso schema di {@see ScopertiNonAccettatiException}: il controller la traduce in un avviso, la
 * pagina mostra **chi** cambia e **perché** (il gradino usato, i giorni), e si rigenera con
 * `accetta_destinatari` e una nota di almeno dieci caratteri, congelata nelle quote.
 */
class DestinatariCambiatiException extends Exception
{
    /** @param list<array<string,mixed>> $cambiamenti */
    public function __construct(protected array $cambiamenti)
    {
        parent::__construct('La risoluzione per periodo ha cambiato dei destinatari, o il piano contiene una pregressa senza periodo: serve una presa d\'atto esplicita.');
    }

    /** @return list<array<string,mixed>> */
    public function getCambiamenti(): array
    {
        return $this->cambiamenti;
    }

    /**
     * Il cancello (2) in parole, per chi non vede il riquadro della pagina: un agente AI riceveva l'elenco senza una frase, con la
     * risposta «riuscita» e il rimando alla home (Web Pro Italia, 15/11/2026; `AutenticaAgente`). Le unità per nome, al più dieci.
     *
     * @param  list<array<string,mixed>>  $cambiamenti  già arricchiti con i nomi (`GeneratePianoRateAction::arricchisciCambiamenti`)
     */
    public static function fraseDeiCambiamenti(array $cambiamenti): string
    {
        $unita = array_values(array_unique(array_filter(array_map(fn ($c) => $c['immobile_nome'] ?? null, $cambiamenti))));
        $pregresse = count(array_filter($cambiamenti, fn ($c) => ($c['motivo'] ?? null) === 'pregressa_senza_periodo'));
        $mostrate = array_slice($unita, 0, 10);

        return 'Niente è stato scritto: con il periodo di competenza cambia chi paga'
            .($unita !== [] ? ' su '.(count($unita) === 1 ? 'un\'unità' : count($unita).' unità').' ('.implode(', ', $mostrate).(count($unita) > 10 ? sprintf(' e altre %d', count($unita) - 10) : '').')' : '')
            .($pregresse > 0 ? ($unita !== [] ? ', e ' : ': ').($pregresse === 1 ? 'una fattura pregressa non ha' : $pregresse.' fatture pregresse non hanno').' un periodo di competenza' : '')
            .'. Il dettaglio di chi entra e chi esce è nei dati di questo avviso. Se va bene così, ripeti la richiesta con la presa d\'atto'
            .' (accetta_destinatari e una nota di almeno 10 caratteri in nota_destinatari), che resta scritta nelle quote.';
    }

    public function report(): bool
    {
        return false;
    }
}
