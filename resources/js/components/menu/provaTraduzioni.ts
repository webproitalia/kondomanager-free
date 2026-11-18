/**
 * Per i test del menu laterale: le traduzioni della testata (`lang/it/appHeader.php`) come le vede il menu in italiano.
 * Nei test le traduzioni non sono caricate: con queste i test leggono i nomi veri delle voci, e non le chiavi.
 * Una chiave che qui non c'è torna com'è, come fa `trans()` quando una traduzione manca.
 */
const IT: Record<string, string> = {
    'appHeader.dashboard': 'Dashboard',
    'appHeader.condomini': 'Condomini',
    'appHeader.elencoCondomini': 'Elenco condomìni',
    'appHeader.importaDati': 'Importa dati',
    'appHeader.rubrica': 'Rubrica',
    'appHeader.anagrafiche': 'Anagrafiche',
    'appHeader.fornitori': 'Fornitori',
    'appHeader.bacheca': 'Bacheca',
    'appHeader.segnalazioni': 'Segnalazioni',
    'appHeader.documenti': 'Documenti',
    'appHeader.allegati': 'Allegati',
    'appHeader.agenda': 'Agenda',
    'appHeader.repository': 'Repository',
    'appHeader.documentation': 'Documentazione',
    'appHeader.settings': 'Impostazioni',
    'appHeader.persone': 'Persone',
    'appHeader.guida': 'Guida',
};

export const tradotto = (chiave: string): string => IT[chiave] ?? chiave;
