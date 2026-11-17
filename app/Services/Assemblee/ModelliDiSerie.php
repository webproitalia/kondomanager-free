<?php

namespace App\Services\Assemblee;

use App\Models\Assemblee\VerbaleModello;

/**
 * I modelli che il programma porta con sé, uno per tipo (Web Pro Italia, 17/11/2026): il verbale ({@see ModelloCanonico}),
 * l'avviso di convocazione con il modulo di delega, il testo dell'email che porta l'avviso e quello con cui si manda il
 * verbale approvato. Le migrazioni li seminano una volta come predefiniti; da lì sono modelli come gli altri, e lo
 * studio li cambia o li sostituisce con i suoi dalla pagina dei modelli.
 *
 * I segnaposto del verbale sono quelli di {@see SegnapostiVerbale}; quelli degli altri tre sono di
 * {@see SegnapostiConvocazione}. Il markup è quello della nota del fascicolo (`TestoNota`): «# » per un titolo,
 * `**…**` per il grassetto, «- » o «1. » per un elenco, una riga vuota fra i paragrafi. In più, nell'avviso di
 * convocazione, una riga con soli tre trattini (`---`) stacca quello che segue con una linea tratteggiata: è il modulo
 * di delega, da ritagliare.
 *
 * Niente termini di legge scritti come fatti: l'avviso richiama solo la delega scritta dell'art. 67 disp. att. c.c. La
 * regola dei cinque giorni dell'art. 66 disp. att. c.c. è un avviso della pagina, non una frase del modello.
 */
final class ModelliDiSerie
{
    public const CONVOCAZIONE = <<<'TXT'
# AVVISO DI CONVOCAZIONE DELL'ASSEMBLEA {{assemblea.tipo_maiuscolo}}

Ai Signori Condòmini del {{condominio.nome_completo}}{{#condominio.indirizzo}}, {{condominio.indirizzo}}{{/condominio.indirizzo}}

I Signori Condòmini sono convocati in assemblea {{assemblea.tipo}} in **prima convocazione** il giorno **{{prima.giorno}}** alle ore **{{prima.ora}}** {{assemblea.luogo}}{{#seconda.giorno}} e, occorrendo, in **seconda convocazione** il giorno **{{seconda.giorno}}** alle ore **{{seconda.ora}}** {{assemblea.luogo}}{{/seconda.giorno}}, per discutere e deliberare sul seguente

# Ordine del giorno
{{#punti}}{{numero}}. {{titolo}}{{#descrizione}} — {{descrizione}}{{/descrizione}}
{{/punti}}

{{assemblea.modalita}}{{#assemblea.collegamento}} Collegamento per partecipare a distanza: {{assemblea.collegamento}}{{/assemblea.collegamento}}

Chi non può intervenire di persona può farsi rappresentare da un'altra persona con delega scritta (art. 67 delle disposizioni di attuazione del codice civile), anche con il modulo in fondo a questo avviso.

{{luogo_e_data}}

L'amministratore
{{amministratore}}

---

# DELEGA

Il/La sottoscritto/a {{destinatario.nome}}, condòmino/a del {{condominio.nome_completo}} per l'unità {{destinatario.unita}}, delega il/la signor/a ______________________________ a rappresentarlo/a nell'assemblea {{assemblea.tipo}} convocata per il giorno {{prima.giorno}} in prima convocazione{{#seconda.giorno}} e per il giorno {{seconda.giorno}} in seconda convocazione{{/seconda.giorno}}, con ogni facoltà di intervento e di voto sui punti all'ordine del giorno.

Data ______________________ Firma ______________________________
TXT;

    public const EMAIL_CONVOCAZIONE = <<<'TXT'
Oggetto: Convocazione dell'assemblea {{assemblea.tipo}} — {{condominio.nome_completo}}

In allegato trova l'avviso di convocazione dell'assemblea {{assemblea.tipo}} del {{condominio.nome_completo}}, fissata in prima convocazione per il giorno {{prima.giorno}} alle ore {{prima.ora}}{{#seconda.giorno}} e in seconda convocazione per il giorno {{seconda.giorno}} alle ore {{seconda.ora}}{{/seconda.giorno}}, {{assemblea.luogo}}.
Nell'avviso ci sono l'ordine del giorno e il modulo per la delega.
TXT;

    public const TRASMISSIONE_VERBALE = <<<'TXT'
Oggetto: Verbale dell'assemblea {{assemblea.tipo}} del {{assemblea.data}} — {{condominio.nome_completo}}

Le trasmetto in allegato il verbale dell'assemblea {{assemblea.tipo}} del {{condominio.nome_completo}} che si è tenuta il giorno {{assemblea.data}}.
Per ogni chiarimento può rivolgersi allo studio.
TXT;

    /**
     * Il modello di serie di un tipo: il nome, la descrizione e il corpo che la migrazione semina e che la pagina
     * propone per un modello nuovo.
     *
     * @return array{nome: string, descrizione: string, corpo: string}
     */
    public static function perTipo(string $tipo): array
    {
        return match ($tipo) {
            VerbaleModello::CONVOCAZIONE => [
                'nome' => 'Avviso di convocazione (modello di serie)',
                'descrizione' => 'L\'avviso di convocazione con l\'ordine del giorno e, in fondo, il modulo di delega. Sostituiscilo con quello del tuo studio, o correggilo.',
                'corpo' => self::CONVOCAZIONE,
            ],
            VerbaleModello::EMAIL_CONVOCAZIONE => [
                'nome' => 'Email della convocazione (modello di serie)',
                'descrizione' => 'Il testo breve dell\'email che porta l\'avviso in PDF. La prima riga «Oggetto: …» è l\'oggetto del messaggio.',
                'corpo' => self::EMAIL_CONVOCAZIONE,
            ],
            VerbaleModello::TRASMISSIONE_VERBALE => [
                'nome' => 'Trasmissione del verbale (modello di serie)',
                'descrizione' => 'Il testo dell\'email e della lettera con cui si manda il verbale approvato. La prima riga «Oggetto: …» è l\'oggetto.',
                'corpo' => self::TRASMISSIONE_VERBALE,
            ],
            default => ['nome' => ModelloCanonico::NOME, 'descrizione' => ModelloCanonico::DESCRIZIONE, 'corpo' => ModelloCanonico::CORPO],
        };
    }

    /** Il corpo da usare per un tipo quando lo studio non ha nessun modello di quel tipo. */
    public static function corpo(string $tipo): string
    {
        return self::perTipo($tipo)['corpo'];
    }

    /**
     * Un testo di email in due parti: l'oggetto (la prima riga, se comincia con «Oggetto:») e il resto. Senza quella
     * riga l'oggetto è vuoto, e chi chiama mette il suo.
     *
     * @return array{oggetto: string, testo: string}
     */
    public static function oggettoETesto(string $corpo): array
    {
        $corpo = ltrim(str_replace(["\r\n", "\r"], "\n", $corpo));
        if (preg_match('/^\s*oggetto\s*:\s*(.*)$/imu', strtok($corpo, "\n") ?: '', $m) === 1) {
            $resto = (string) substr($corpo, strpos($corpo."\n", "\n") + 1);

            return ['oggetto' => trim($m[1]), 'testo' => trim($resto)];
        }

        return ['oggetto' => '', 'testo' => trim($corpo)];
    }
}
