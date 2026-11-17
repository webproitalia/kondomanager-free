// @vitest-environment jsdom

/**
 * La pagina della convocazione: mostra gli avvisi che arrivano dal server (i cinque giorni), il testo e lo stato dell'assemblea; «Genera dal
 * modello» sopra un testo che c'è chiede prima la conferma; «Salva» manda il testo e il collegamento; i tasti delle stampe aprono le loro
 * rotte; la finestra degli invii è quella comune. Le props sono quelle che manda `ConvocazioneController::show` (i test Pest di
 * `ConvocazioneAssembleaTest` provano il server). La pagina è quella vera: si stubbano la cornice, la guida e la finestra degli invii.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';

const router = vi.hoisted(() => ({ put: vi.fn(), post: vi.fn(), reload: vi.fn() }));
vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router,
    usePage: () => ({ props: { flash: {}, errors: {} } }),
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({ generatePath: (p: string, v: Record<string, unknown> = {}) => '/' + p.replace(/:(\w+)/g, (_m, k) => String(v[k])) }),
}));

const ConvocazioneEdit = (await import('@/pages/gestionale/assemblee/ConvocazioneEdit.vue')).default;

const stubs = {
    GestionaleLayout: { template: '<div><slot /></div>' },
    PageHeaderGuide: { template: '<div><slot name="actions" /></div>' },
    AssembleeGuide: true,
    Alert: true,
    InvioAssemblea: { name: 'InvioAssemblea', props: ['cosa', 'base', 'invio', 'puoGestire'], template: '<div data-testid="invii">{{ cosa }} {{ base }}</div>' },
    ConfirmDialog: { props: ['modelValue'], emits: ['confirm'], template: '<div v-if="modelValue" data-testid="finestra-conferma"><button data-testid="sostituisci" @click="$emit(\'confirm\')">Sostituisci</button></div>' },
};

const props = (altro: Record<string, unknown> = {}) => ({
    condominio: { id: 5, nome: 'Le Rose' }, condomini: [], esercizio: null,
    assemblea: { id: 7, condominio_id: 5, titolo: 'Assemblea ordinaria 2026', tipo: 'ordinaria', stato: 'bozza', data_prima_convocazione: '2026-11-22 09:00',
        data_seconda_convocazione: '2026-11-23 18:30', data_avviso: null, luogo: 'sala', modalita: 'presenza', collegamento_videoconferenza: null, bloccata: false },
    punti: [{ numero: 1, titolo: 'Rendiconto', descrizione: null }],
    convocazione: { testo: null, modello_id: null, modello: null, collegamento_videoconferenza: null, modificabile: true },
    modelli: [{ id: 3, nome: 'Avviso di convocazione (modello di serie)', descrizione: null, predefinito: true }],
    proposta: '# AVVISO DI CONVOCAZIONE',
    avvisi: [{ tipo: 'warning', codice: 'cinque_giorni', testo: 'Se l\'avviso parte oggi (18/11/2026), fino alla prima convocazione (22/11/2026) ci sono 4 giorni: meno dei cinque giorni.' }],
    invio: { persone: [] },
    segnaposti: { semplici: { 'prima.giorno': 'Il giorno della prima convocazione.' }, punti: { numero: 'Il numero.' }, destinatario: { 'destinatario.nome': 'Il nome di chi riceve.' } },
    puo: { gestire: true, approvare: false },
    ...altro,
});

let pagina: VueWrapper | null = null;
afterEach(() => {
    pagina?.unmount();
    pagina = null;
    router.put.mockReset();
    router.post.mockReset();
    vi.unstubAllGlobals();
});
const monta = (altro: Record<string, unknown> = {}) => {
    pagina = mount(ConvocazioneEdit, { props: props(altro) as never, global: { stubs } });

    return pagina;
};

describe('la pagina della convocazione', () => {
    test('mostra l\'avviso dei cinque giorni, lo stato dell\'assemblea e la finestra degli invii della convocazione', () => {
        const p = monta();

        expect(p.find('[data-testid="avvisi-convocazione"]').text()).toContain('ci sono 4 giorni: meno dei cinque giorni');
        expect(p.find('[data-testid="stato-assemblea"]').text()).toBe('In preparazione');
        expect(p.text()).toContain('avviso non ancora partito');
        expect(p.find('[data-testid="invii"]').text()).toBe('convocazione /gestionale/5/assemblee/7');
        expect(p.find('[data-testid="genera-convocazione"]').text()).toContain('Genera dal modello');
    });

    test('senza un testo «Genera dal modello» genera subito; sopra un testo che c\'è chiede la conferma e poi sovrascrive', async () => {
        const vuota = monta();
        await vuota.find('[data-testid="genera-convocazione"]').trigger('click');
        expect(router.post).toHaveBeenCalledWith('/gestionale/5/assemblee/7/convocazione/genera', { modello_id: 3, sovrascrivi: false }, expect.anything());
        vuota.unmount();
        router.post.mockReset();

        const scritta = monta({ convocazione: { testo: 'Il testo scritto a mano.', modello_id: 3, modello: 'Avviso', collegamento_videoconferenza: null, modificabile: true } });
        expect(scritta.find('[data-testid="genera-convocazione"]').text()).toContain('Rigenera dal modello');
        await scritta.find('[data-testid="genera-convocazione"]').trigger('click');
        expect(router.post).not.toHaveBeenCalled();
        await scritta.find('[data-testid="sostituisci"]').trigger('click');
        expect(router.post).toHaveBeenCalledWith('/gestionale/5/assemblee/7/convocazione/genera', { modello_id: 3, sovrascrivi: true }, expect.anything());
    });

    test('«Salva» manda il testo e il collegamento; le stampe aprono l\'avviso, uno per persona e il foglio delle firme', async () => {
        const apri = vi.fn();
        vi.stubGlobal('open', apri);
        const p = monta({ convocazione: { testo: 'Prima.', modello_id: 3, modello: 'Avviso', collegamento_videoconferenza: null, modificabile: true } });

        expect(p.find('[data-testid="salva-convocazione"]').attributes('disabled')).toBeDefined();
        await p.find('[data-testid="testo-convocazione"]').setValue('Dopo.');
        await p.find('[data-testid="collegamento"]').setValue('https://riunioni.example/x');
        await p.find('[data-testid="salva-convocazione"]').trigger('click');
        expect(router.put).toHaveBeenCalledWith('/gestionale/5/assemblee/7/convocazione', { testo: 'Dopo.', collegamento_videoconferenza: 'https://riunioni.example/x' }, expect.anything());

        await p.find('[data-testid="stampa-avviso"]').trigger('click');
        await p.find('[data-testid="stampa-per-persona"]').trigger('click');
        await p.find('[data-testid="stampa-foglio-firme"]').trigger('click');
        expect(apri.mock.calls.map((c) => c[0])).toEqual(['/gestionale/5/assemblee/7/convocazione/stampa', '/gestionale/5/assemblee/7/convocazione/per-persona/stampa', '/gestionale/5/assemblee/7/foglio-firme/stampa']);
    });

    test('con il verbale approvato il testo non si tocca: i campi e i tasti sono spenti', () => {
        const p = monta({ convocazione: { testo: 'Mandata.', modello_id: 3, modello: 'Avviso', collegamento_videoconferenza: null, modificabile: false } });

        expect(p.find('[data-testid="testo-convocazione"]').attributes('disabled')).toBeDefined();
        expect(p.find('[data-testid="genera-convocazione"]').attributes('disabled')).toBeDefined();
    });
});
