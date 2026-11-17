<?php

namespace App\Enums;

/**
 * La maggioranza che un punto dell'ordine del giorno chiede per essere approvato (Web Pro Italia, 17/11/2026).
 *
 * Ogni maggioranza è fatta di due condizioni che devono valere insieme: una sulle **teste** (quante persone votano a
 * favore) e una sul **valore** (quanti millesimi votano a favore, sul valore dell'intero edificio). Qui stanno le due
 * frazioni; il conto lo fa `App\Services\Assemblee\ConteggiAssemblea`, con numeri interi e senza arrotondamenti.
 *
 * - `ordinaria` (art. 1136 c. 2 e 3): maggioranza degli intervenuti e almeno metà del valore in prima convocazione,
 *   maggioranza degli intervenuti e almeno un terzo del valore in seconda;
 * - `art1136c4`: le delibere che il comma 4 elenca (nomina e revoca dell'amministratore, liti attive e passive
 *   fuori dalle sue attribuzioni, ricostruzione dell'edificio, riparazioni straordinarie di notevole entità e le
 *   altre che il comma richiama): maggioranza degli intervenuti e almeno metà del valore, in ogni convocazione.
 *   Qui sta anche **l'approvazione e la modifica del regolamento** (art. 1138 c. 3 c.c.: la maggioranza dell'art.
 *   1136 c. 2, cioè gli stessi numeri, in prima e in seconda convocazione). Il regolamento non è una delibera
 *   «ordinaria»: in seconda convocazione un terzo del valore non basta;
 * - `art1136c5`: le innovazioni dell'art. 1120 c. 1 e le altre che il comma 5 richiama: maggioranza degli intervenuti
 *   e almeno due terzi del valore;
 * - `unanimita`: tutti i partecipanti al condominio e tutto il valore;
 * - `informativa`: il punto non si vota.
 *
 * ⚠️ Quale maggioranza serve a una delibera lo decide chi prepara l'ordine del giorno, non il programma: le leggi
 * speciali (barriere architettoniche, impianti, risparmio energetico…) hanno quorum loro che qui non ci sono. Per
 * quelle si sceglie la maggioranza più vicina e la si scrive nella descrizione del punto.
 */
enum MaggioranzaAssemblea: string
{
    case ORDINARIA = 'ordinaria';
    case ART1136C4 = 'art1136c4';
    case ART1136C5 = 'art1136c5';
    case UNANIMITA = 'unanimita';
    case INFORMATIVA = 'informativa';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Il nome breve, per il menu dell'ordine del giorno. */
    public function etichetta(): string
    {
        return match ($this) {
            self::ORDINARIA => 'Ordinaria (art. 1136 c. 2-3)',
            self::ART1136C4 => 'Qualificata, metà del valore (art. 1136 c. 4)',
            self::ART1136C5 => 'Qualificata, due terzi del valore (art. 1136 c. 5)',
            self::UNANIMITA => 'Unanimità',
            self::INFORMATIVA => 'Informativa, senza votazione',
        };
    }

    /** A che cosa serve, in parole semplici: la mostrano la pagina e la guida. */
    public function aCheServe(): string
    {
        return match ($this) {
            self::ORDINARIA => 'Le decisioni di tutti i giorni: approvare il rendiconto e il preventivo, le spese ordinarie.',
            self::ART1136C4 => 'Nomina e revoca dell\'amministratore, liti fuori dalle sue attribuzioni, ricostruzione dell\'edificio, riparazioni straordinarie di notevole entità, approvazione e modifica del regolamento (art. 1138 c.c.).',
            self::ART1136C5 => 'Le innovazioni (art. 1120 c. 1): opere nuove che migliorano o rendono più comodo l\'uso delle parti comuni.',
            self::UNANIMITA => 'Le decisioni che toccano i diritti di ognuno: servono tutti i condòmini e tutti i millesimi.',
            self::INFORMATIVA => 'Comunicazioni dell\'amministratore e varie ed eventuali: si discute e si mette a verbale, senza votare.',
        };
    }

    /**
     * La frazione del valore dell'edificio che i favorevoli devono raggiungere: `[numeratore, denominatore]`, «almeno».
     * Null per la maggioranza informativa, che non si vota.
     *
     * @return array{0:int,1:int}|null
     */
    public function quotaDelValore(string $convocazione): ?array
    {
        return match ($this) {
            self::ORDINARIA => $convocazione === 'seconda' ? [1, 3] : [1, 2],
            self::ART1136C4 => [1, 2],
            self::ART1136C5 => [2, 3],
            self::UNANIMITA => [1, 1],
            self::INFORMATIVA => null,
        };
    }

    /** La regola scritta per esteso, come la riporta il verbale. */
    public function regola(string $convocazione): string
    {
        return match ($this) {
            self::ORDINARIA => $convocazione === 'seconda'
                ? 'la maggioranza degli intervenuti e almeno un terzo del valore dell\'edificio (333,33 millesimi), art. 1136 c. 3 c.c.'
                : 'la maggioranza degli intervenuti e almeno la metà del valore dell\'edificio (500 millesimi), art. 1136 c. 2 c.c.',
            self::ART1136C4 => 'la maggioranza degli intervenuti e almeno la metà del valore dell\'edificio (500 millesimi), art. 1136 c. 4 c.c.',
            self::ART1136C5 => 'la maggioranza degli intervenuti e almeno due terzi del valore dell\'edificio (666,67 millesimi), art. 1136 c. 5 c.c.',
            self::UNANIMITA => 'l\'unanimità dei partecipanti al condominio e di tutto il valore dell\'edificio (1.000 millesimi)',
            self::INFORMATIVA => 'nessuna: il punto è informativo e non si vota',
        };
    }
}
