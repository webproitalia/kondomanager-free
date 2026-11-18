/**
 * Il campo di ricerca di un elenco nasce con il testo che il server ha già applicato (Web Pro Italia, 18/11/2026).
 * Che cosa NON copre: che ogni barra lo usi (provato dal vivo sui cinque elenchi con le schede laterali).
 */
import { beforeEach, describe, expect, test, vi } from 'vitest';

const pagina = vi.hoisted(() => ({ props: {} as Record<string, unknown> }));
vi.mock('@inertiajs/vue3', () => ({ usePage: () => pagina }));

const { filtroIniziale } = await import('./filtroIniziale');

beforeEach(() => {
    pagina.props = {};
});

describe('filtroIniziale', () => {
    test('il testo che il server ha applicato', () => {
        pagina.props = { filters: { nome: 'bianchi', condominio_id: [3] } };
        expect(filtroIniziale('nome')).toBe('bianchi');
    });

    test('un numero diventa testo: il campo è di testo', () => {
        pagina.props = { filters: { nome: 12 } };
        expect(filtroIniziale('nome')).toBe('12');
    });

    test('senza filtri, senza quella chiave, con un vuoto o con un elenco: campo vuoto', () => {
        expect(filtroIniziale('nome')).toBe('');
        pagina.props = { filters: null };
        expect(filtroIniziale('nome')).toBe('');
        pagina.props = { filters: { ragione_sociale: 'x', nome: null, condominio_id: [3] } };
        expect(filtroIniziale('nome')).toBe('');
        expect(filtroIniziale('condominio_id')).toBe('');
        // Laravel rimanda un elenco vuoto quando non c'è nessun filtro: `only()` su una richiesta senza parametri.
        pagina.props = { filters: [] };
        expect(filtroIniziale('nome')).toBe('');
    });
});
