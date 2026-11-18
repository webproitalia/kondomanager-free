import { usePage } from '@inertiajs/vue3';

/**
 * Il testo di ricerca che il server ha già applicato all'elenco, per far nascere il campo già pieno
 * (Web Pro Italia, 18/11/2026).
 *
 * ## Perché esiste
 *
 * Lo stato di un elenco vive nell'indirizzo (`useTabellaServer.ts`), ma il campo di ricerca della barra nasceva vuoto:
 * `ref('')`. Finché la pagina dell'elenco non si ridisegnava non si notava. Con le schede laterali si nota a ogni giro: si
 * cerca «bianchi», si apre la scheda, la si chiude, e l'elenco è ancora quello di «bianchi» ma il campo è vuoto (lo stesso con
 * «Indietro» del browser, o aprendo un indirizzo già filtrato). Per togliere il filtro bisognava scrivere e cancellare.
 *
 * ## Come si usa
 *
 * `const nomeFilter = ref(filtroIniziale('nome'))`, **prima** dell'osservatore che manda i filtri al server: l'osservatore
 * nasce già allineato e non parte nessuna richiesta in più (la stessa regola di `useReidratazioneFiltri.ts`).
 *
 * La chiave è quella che il controller rimanda in `filters` dopo averla letta dalla richiesta.
 */
export function filtroIniziale(chiave: string): string {
    const valore = (usePage().props.filters as Record<string, unknown> | null | undefined)?.[chiave];

    return typeof valore === 'string' || typeof valore === 'number' ? String(valore) : '';
}
