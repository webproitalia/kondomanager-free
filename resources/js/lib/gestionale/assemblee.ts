/**
 * I tipi e le piccole regole di scrittura delle pagine delle assemblee (Web Pro Italia, 17/11/2026). I conteggi non si
 * rifanno qui: arrivano dal server (`ConteggiAssemblea`), che li calcola con i numeri interi. La pagina li mostra e basta.
 */

export type TipoPresenza = 'presente' | 'delega' | 'assente';
export type Voto = 'favorevole' | 'contrario' | 'astenuto';
export type Esito = 'approvato' | 'respinto' | 'rinviato' | 'informativa';

export interface DatiAssemblea {
    id: number;
    condominio_id: number;
    esercizio_id: number | null;
    esercizio: string | null;
    tipo: 'ordinaria' | 'straordinaria';
    titolo: string;
    data_prima_convocazione: string | null;
    data_seconda_convocazione: string | null;
    convocazione_tenuta: 'prima' | 'seconda' | null;
    data_avviso: string | null;
    luogo: string | null;
    modalita: 'presenza' | 'videoconferenza' | 'mista';
    tabella_id: number | null;
    tabella: string | null;
    millesimi_totali: string | null;
    presidente: string | null;
    segretario: string | null;
    ora_inizio: string | null;
    ora_fine: string | null;
    stato: 'bozza' | 'convocata' | 'in_corso' | 'chiusa';
    registrata: boolean;
    note: string | null;
    bloccata: boolean;
}

export interface GruppoVoti {
    teste: number;
    millesimi: number;
    millesimi_testo: string;
    nomi: { presenza_id: number; nome: string; delegato: string | null; millesimi_testo: string }[];
}

export interface Votazione {
    punto_id: number;
    maggioranza: string;
    informativa: boolean;
    regola: string;
    favorevoli: GruppoVoti;
    contrari: GruppoVoti;
    astenuti: GruppoVoti;
    intervenuti: { teste: number; millesimi: number; millesimi_testo: string };
    senza_voto: number[];
    teste_ok: boolean;
    valore_ok: boolean;
    raggiunta: boolean;
    esito_proposto: Esito | null;
    esito_diverso: boolean;
    spiegazione: string;
}

export interface Punto {
    id: number;
    numero: number;
    titolo: string;
    descrizione: string | null;
    maggioranza: string;
    maggioranza_etichetta: string;
    discussione: string | null;
    delibera: string | null;
    esito: Esito | null;
    voti: { presenza_id: number; voto: Voto }[];
    votazione: Votazione;
}

export interface Presenza {
    id: number;
    anagrafica_id: number;
    nome: string;
    tipo: TipoPresenza;
    delegato_anagrafica_id: number | null;
    delegato_nome: string | null;
    delegato: string | null;
    millesimi: string;
    millesimi_su_mille: string;
    unita: { id: number; etichetta: string }[];
    candidati: { id: number; nome: string; ruolo: string }[];
    contitolari: string[];
    ora_arrivo: string | null;
    ora_uscita: string | null;
    in_sala: boolean;
}

export interface Costituzione {
    convocazione: 'prima' | 'seconda';
    convocazione_scelta: boolean;
    teste_totali: number;
    teste_presenti: number;
    teste_di_persona: number;
    teste_per_delega: number;
    millesimi_presenti_testo: string;
    valida: boolean;
    per_convocazione: { prima: boolean; seconda: boolean };
    requisiti: string;
    mancano: string | null;
}

export interface DatiVerbale {
    id: number;
    stato: 'bozza' | 'da_approvare' | 'approvato';
    versione: number;
    testo: string | null;
    modello_id: number | null;
    modello: string | null;
    intestazione: string | null;
    apertura: string | null;
    chiusura: string | null;
    documento: { id: number; nome: string } | null;
    inviato_in_approvazione_at: string | null;
    approvato_at: string | null;
    approvato_da: string | null;
    modificabile: boolean;
}

export interface Fotografia {
    assemblea: DatiAssemblea;
    punti: Punto[];
    presenze: Presenza[];
    conteggi: { costituzione: Costituzione; avvisi: { tipo: string; testo: string }[] };
    verbale: DatiVerbale;
}

export interface Maggioranza {
    valore: string;
    etichetta: string;
    a_che_serve: string;
    regola_prima: string;
    regola_seconda: string;
}

export const NOMI_STATO: Record<DatiAssemblea['stato'], string> = {
    bozza: 'In preparazione',
    convocata: 'Convocata',
    in_corso: 'In corso',
    chiusa: 'Chiusa',
};

export const NOMI_STATO_VERBALE: Record<DatiVerbale['stato'], string> = {
    bozza: 'Bozza',
    da_approvare: 'Da approvare',
    approvato: 'Approvato',
};

export const NOMI_ESITO: Record<Esito, string> = {
    approvato: 'Approvato',
    respinto: 'Respinto',
    rinviato: 'Rinviato',
    informativa: 'Informativo',
};

export const NOMI_MODALITA: Record<DatiAssemblea['modalita'], string> = {
    presenza: 'In presenza',
    videoconferenza: 'In videoconferenza',
    mista: 'In presenza e in videoconferenza',
};

/** «23/11/2026 18:30» da «2026-11-23 18:30»; vuoto se manca. */
export function giornoEOra(valore: string | null | undefined): string {
    if (!valore) return '';
    const [data, ora] = valore.split(' ');
    const [a, m, g] = data.split('-');
    return `${g}/${m}/${a}${ora && ora !== '00:00' ? ' ' + ora : ''}`;
}

/** La ricerca dell'elenco delle presenze: per nome (anche dei comproprietari e del delegato) o per unità, senza badare agli accenti. */
export function corrispondeAllaRicerca(p: Presenza, testo: string): boolean {
    const t = normalizza(testo);
    if (t === '') return true;
    const dove = [p.nome, ...p.contitolari, p.delegato ?? '', ...p.unita.map((u) => u.etichetta)].map(normalizza).join(' | ');
    return t.split(/\s+/).every((parola) => dove.includes(parola));
}

export function normalizza(testo: string): string {
    return testo.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
}
