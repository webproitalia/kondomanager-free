// @vitest-environment jsdom

/**
 * La pagina dei modelli dell'assemblea: una scheda per tipo (verbale, avviso di convocazione, email della convocazione, trasmissione del
 * verbale), ognuna con i suoi modelli e i suoi segnaposto; un modello nuovo parte dal modello di serie del tipo aperto e si salva con quel
 * tipo; la prova manda il tipo, così il server usa i segnaposto giusti. Le props sono quelle di `ModelliVerbaleController::index`.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const router = vi.hoisted(() => ({ put: vi.fn(), post: vi.fn(), delete: vi.fn() }));
const post = vi.hoisted(() => vi.fn());
vi.mock('axios', () => ({ default: { post: (...a: unknown[]) => post(...a) } }));
vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    router,
    usePage: () => ({ props: { flash: {}, errors: {} } }),
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({ generatePath: (p: string, v: Record<string, unknown> = {}) => '/' + p.replace(/:(\w+)/g, (_m, k) => String(v[k])) }),
}));

const ModelliVerbale = (await import('@/pages/gestionale/assemblee/ModelliVerbale.vue')).default;

const stubs = { GestionaleLayout: { template: '<div><slot /></div>' }, PageHeaderGuide: true, Alert: true, ConfirmDialog: true };
const modello = (id: number, tipo: string, nome: string, corpo: string) => ({ id, tipo, nome, descrizione: null, corpo, predefinito: true, autore: null, cambiato_il: '2026-11-17', sconosciuti: [] });
const tipo = (t: string, nome: string, canonico: string, destinatario: Record<string, string> = {}) => ({ tipo: t, nome, canonico,
    segnaposti: { semplici: t === 'verbale' ? { presidente: 'Chi presiede.' } : { 'prima.giorno': 'Il giorno della prima convocazione.' }, punti: { numero: 'Il numero.' }, destinatario } });

const props = () => ({
    condominio: { id: 5, nome: 'Le Rose' }, condomini: [], esercizio: null,
    modelli: [modello(1, 'verbale', 'Verbale di serie', '# VERBALE'), modello(2, 'convocazione', 'Avviso di serie', '# AVVISO'), modello(3, 'email_convocazione', 'Email di serie', 'Oggetto: x')],
    assemblee: [{ id: 7, titolo: 'Assemblea 2026' }],
    segnaposti: { semplici: { presidente: 'Chi presiede.' }, punti: { numero: 'Il numero.' } },
    canonico: '# VERBALE CANONICO',
    tipi: [tipo('verbale', 'Verbale', '# VERBALE CANONICO'), tipo('convocazione', 'Avviso di convocazione', '# AVVISO CANONICO', { 'destinatario.nome': 'Il nome di chi riceve.' }),
        tipo('email_convocazione', 'Email della convocazione', 'Oggetto: canonico'), tipo('trasmissione_verbale', 'Trasmissione del verbale (email e lettera)', 'Oggetto: verbale')],
    puo: { gestire: true, approvare: false },
});

let pagina: VueWrapper | null = null;
afterEach(() => {
    pagina?.unmount();
    pagina = null;
    router.post.mockReset();
    router.put.mockReset();
    post.mockReset();
});
const monta = () => (pagina = mount(ModelliVerbale, { props: props() as never, global: { stubs } }));
const corpo = (p: VueWrapper) => (p.find('[data-testid="corpo-modello"]').element as HTMLTextAreaElement).value;

describe('i modelli dell\'assemblea', () => {
    test('una scheda per tipo: si apre sul verbale; la convocazione mostra i suoi modelli e i segnaposto di chi riceve', async () => {
        const p = monta();

        expect(p.findAll('[data-testid="tipi-modello"] button').map((b) => b.text())).toEqual(['Verbale (1)', 'Avviso di convocazione (1)', 'Email della convocazione (1)', 'Trasmissione del verbale (email e lettera) (0)']);
        expect(corpo(p)).toBe('# VERBALE');
        expect(p.find('[data-testid="guida-segnaposto"]').text()).toContain('{{presidente}}');
        expect(p.find('[data-testid="guida-segnaposto"]').text()).not.toContain('Chi riceve');

        await p.find('[data-testid="tipo-convocazione"]').trigger('click');
        expect(corpo(p)).toBe('# AVVISO');
        expect((p.find('[data-testid="nome-modello"]').element as HTMLInputElement).value).toBe('Avviso di serie');
        expect(p.find('[data-testid="guida-segnaposto"]').text()).toContain('{{prima.giorno}}');
        expect(p.find('[data-testid="guida-segnaposto"]').text()).toContain('{{destinatario.nome}}');
        expect(p.text()).toContain('è il modulo di delega');
    });

    test('un tipo senza modelli parte dal suo modello di serie, e si salva con quel tipo; la prova manda il tipo', async () => {
        post.mockResolvedValue({ data: { testo: 'Oggetto: verbale del 23 novembre', sconosciuti: [] } });
        const p = monta();

        await p.find('[data-testid="tipo-trasmissione_verbale"]').trigger('click');
        expect(corpo(p)).toBe('Oggetto: verbale');
        expect(p.text()).toContain('La prima riga «Oggetto: …» è l\'oggetto dell\'email');

        await p.find('[data-testid="nome-modello"]').setValue('Trasmissione dello studio');
        await p.find('[data-testid="salva-modello"]').trigger('click');
        expect(router.post).toHaveBeenCalledTimes(1);
        expect(router.post.mock.calls[0][0]).toBe('/gestionale/5/assemblee/modelli');
        expect(router.post.mock.calls[0][1]).toMatchObject({ tipo: 'trasmissione_verbale', nome: 'Trasmissione dello studio', corpo: 'Oggetto: verbale' });

        await p.findAll('button').find((b) => b.text() === 'Prova')!.trigger('click');
        await flushPromises();
        expect(post).toHaveBeenCalledWith('/gestionale/5/assemblee/modelli/prova', { corpo: 'Oggetto: verbale', assemblea_id: 7, tipo: 'trasmissione_verbale' });
        expect(p.text()).toContain('Oggetto: verbale del 23 novembre');
    });

    test('un modello che c\'è si salva senza cambiare tipo', async () => {
        const p = monta();

        await p.find('[data-testid="tipo-email_convocazione"]').trigger('click');
        await p.find('[data-testid="corpo-modello"]').setValue('Oggetto: nuovo');
        await p.find('[data-testid="salva-modello"]').trigger('click');

        expect(router.put).toHaveBeenCalledWith('/gestionale/5/assemblee/modelli/3', { nome: 'Email di serie', descrizione: null, corpo: 'Oggetto: nuovo', predefinito: true }, expect.anything());
        expect(router.post).not.toHaveBeenCalled();
    });
});
