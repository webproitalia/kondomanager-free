<?php

namespace App\Services\Assemblee;

/**
 * Il modello di verbale che il programma porta con sé (Web Pro Italia, 17/11/2026): un verbale di assemblea
 * condominiale con le frasi di rito che si usano di solito. La migrazione lo semina una volta come modello
 * predefinito; da lì è un modello come gli altri, e lo studio lo cambia o lo sostituisce con il suo.
 *
 * I segnaposto sono quelli di `SegnapostiVerbale`; il markup quello della nota del fascicolo (`TestoNota`): «# » per un
 * titolo, `**…**` per il grassetto, «- » per un elenco, una riga vuota fra i paragrafi.
 */
final class ModelloCanonico
{
    public const NOME = 'Verbale di assemblea (modello di serie)';

    public const DESCRIZIONE = 'Il verbale di assemblea condominiale con le frasi di rito. Sostituiscilo con il modello del tuo studio, o correggilo.';

    public const CORPO = <<<'TXT'
# VERBALE DI ASSEMBLEA {{assemblea.tipo_maiuscolo}} DEI CONDÒMINI
**{{condominio.nome_completo}}**{{#condominio.indirizzo}} — {{condominio.indirizzo}}{{/condominio.indirizzo}}{{#condominio.codice_fiscale}} — Codice fiscale {{condominio.codice_fiscale}}{{/condominio.codice_fiscale}}

Il giorno {{assemblea.data}}, alle ore {{assemblea.ora_inizio}}, {{assemblea.luogo}}, si è riunita in {{assemblea.convocazione}} convocazione l'assemblea {{assemblea.tipo}} dei condòmini del {{condominio.nome_completo}}{{#assemblea.data_avviso}}, convocata con avviso del {{assemblea.data_avviso}}{{/assemblea.data_avviso}}, per discutere e deliberare sul seguente ordine del giorno:

{{ordine_del_giorno}}

Assume la presidenza dell'assemblea {{presidente}}; funge da segretario {{segretario}}.
{{costituzione}}
{{#registrazione.avviso}}{{registrazione.avviso}}
{{/registrazione.avviso}}{{#apertura}}{{apertura}}
{{/apertura}}Si passa quindi alla trattazione dell'ordine del giorno.

{{#punti}}
# Punto {{numero}} — {{titolo}}
{{#discussione}}**Discussione.** {{discussione}}
{{/discussione}}{{#delibera}}**Delibera.** {{delibera}}
{{/delibera}}{{votazione}}
{{#maggioranza}}{{maggioranza}}
{{/maggioranza}}{{#esito}}**{{esito}}**{{/esito}}

{{/punti}}
{{#chiusura}}{{chiusura}}

{{/chiusura}}Esauriti gli argomenti all'ordine del giorno e non essendovi altro da deliberare, il presidente dichiara chiusa l'assemblea alle ore {{ora_fine}}.
Letto, approvato e sottoscritto.

{{firme}}

# Elenco dei presenti
{{presenze.tabella}}

{{#assenti}}**Assenti:** {{assenti}}.{{/assenti}}
TXT;
}
