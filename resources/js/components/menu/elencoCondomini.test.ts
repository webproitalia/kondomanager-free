// @vitest-environment jsdom

/**
 * L'elenco dei condomini della ricerca rapida e del selettore della barra (Web Pro Italia, 18/11/2026): se la pagina lo porta
 * si usa quello, senza chiamate; altrimenti si chiede una volta sola a `/fetch-condomini`, e solo quando serve. A chi non può
 * vederli (un condòmino) non si chiede niente: la rotta gli risponderebbe di no.
 */
import { beforeEach, describe, expect, test, vi } from 'vitest';

const pagina = vi.hoisted(() => ({ url: '/admin/dashboard', props: {} as Record<string, unknown> }));
const chiamate = vi.hoisted(() => ({ get: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({ usePage: () => pagina }));
vi.mock('axios', () => ({ default: { get: chiamate.get } }));

const { useElencoCondomini, dimenticaElencoCondomini } = await import('./elencoCondomini');

const entra = (ruoli: string[], permessi: string[], altro: Record<string, unknown> = {}) => {
    pagina.props = { auth: { user: { roles: ruoli, permissions: permessi } }, ...altro };
};

beforeEach(() => {
    dimenticaElencoCondomini();
    chiamate.get.mockReset();
    chiamate.get.mockResolvedValue({ data: [{ id: 1, nome: 'Le Rose' }, { id: 2, nome: 'I Tigli' }] });
});

describe('l\'elenco dei condomini', () => {
    test('la pagina lo porta: si usa quello, e non si chiama niente', async () => {
        entra(['amministratore'], [], { condomini: [{ id: 7, nome: 'Le Rose', esercizio_aperto: { id: 3 } }] });
        const { condomini, carica } = useElencoCondomini();
        await carica();
        expect(condomini.value).toEqual([{ id: 7, nome: 'Le Rose', esercizio_aperto: { id: 3 } }]);
        expect(chiamate.get).not.toHaveBeenCalled();
    });

    test('la pagina non lo porta: si chiede una volta sola, alla prima apertura', async () => {
        entra(['amministratore'], []);
        const { condomini, carica } = useElencoCondomini();
        expect(condomini.value).toEqual([]);
        expect(chiamate.get).not.toHaveBeenCalled();

        await Promise.all([carica(), carica()]);
        await carica();
        expect(chiamate.get).toHaveBeenCalledTimes(1);
        expect(chiamate.get).toHaveBeenCalledWith('/fetch-condomini');
        expect(condomini.value.map((c) => c.nome)).toEqual(['Le Rose', 'I Tigli']);

        // Un'altra pagina della stessa visita lo trova già.
        const altra = useElencoCondomini();
        await altra.carica();
        expect(altra.condomini.value.length).toBe(2);
        expect(chiamate.get).toHaveBeenCalledTimes(1);
    });

    test('«condomini» della pagina non è un elenco di condomini (un elenco a pagine): non lo si prende per buono', async () => {
        entra(['amministratore'], [], { condomini: { data: [{ id: 7, nome: 'Le Rose' }], meta: {} } });
        const { condomini, carica } = useElencoCondomini();
        await carica();
        expect(chiamate.get).toHaveBeenCalledTimes(1);
        expect(condomini.value.length).toBe(2);
    });

    test('il condòmino: nessuna chiamata e nessun condominio, anche se la pagina ne porta', async () => {
        entra(['utente'], ['Visualizza comunicazioni'], { condomini: [{ id: 7, nome: 'Le Rose' }] });
        const { condomini, carica, puoVederli } = useElencoCondomini();
        await carica();
        expect(puoVederli.value).toBe(false);
        expect(condomini.value).toEqual([]);
        expect(chiamate.get).not.toHaveBeenCalled();
    });

    test('la chiamata non riesce: l\'elenco resta vuoto e la ricerca porta lo stesso alle sezioni', async () => {
        chiamate.get.mockRejectedValue(new Error('rete'));
        entra(['collaboratore'], []);
        const { condomini, carica } = useElencoCondomini();
        await expect(carica()).resolves.toBeUndefined();
        expect(condomini.value).toEqual([]);
    });
});
