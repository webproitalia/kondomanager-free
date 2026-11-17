// @vitest-environment jsdom

/**
 * Il registratore: senza la chiave di Deepgram il tasto non c'è e lo dice; il tasto non parte finché non si conferma l'avviso ai presenti;
 * la casella dell'audio del computer c'è (e parte accesa) solo dove il browser lo permette; quando il servizio non lo dice la pagina, lo
 * chiede al server. Avviato, manda la conferma, l'assemblea e il formato; un pezzo sale con il suo numero e il suo testo compare.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const get = vi.fn();
const post = vi.fn();
vi.mock('axios', () => ({ default: { get: (...a: unknown[]) => get(...a), post: (...a: unknown[]) => post(...a) } }));
vi.mock('@inertiajs/vue3', () => ({ router: { on: () => () => undefined } }));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (nome: string) => nome }) }));
(globalThis as unknown as { route: unknown }).route = (nome: string, p: Record<string, unknown>) => `/${nome}/${p.condominio}${p.registrazione ? `/${p.registrazione}` : ''}`;

const Registratore = (await import('./RegistratoreAssemblea.vue')).default;
const CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';
const SAFARI = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Safari/605.1.15';

let w: VueWrapper | null = null;
afterEach(() => {
    w?.unmount();
    w = null;
    get.mockReset();
    post.mockReset();
    vi.unstubAllGlobals();
});

function browser(userAgent: string, condivisione = true) {
    const stop = vi.fn();
    const traccia = { stop, addEventListener: vi.fn() };
    const flusso = { getTracks: () => [traccia], getAudioTracks: () => [traccia], getVideoTracks: () => [] };
    vi.stubGlobal('navigator', {
        userAgent,
        clipboard: { writeText: vi.fn() },
        mediaDevices: { getUserMedia: vi.fn(async () => flusso), ...(condivisione ? { getDisplayMedia: vi.fn(async () => { throw new Error('annullato'); }) } : {}) },
    });
    class RegistratoreFinto {
        static isTypeSupported = (t: string) => t.startsWith('audio/webm');
        state = 'inactive';
        mimeType = 'audio/webm';
        ondataavailable: ((e: { data: Blob }) => void) | null = null;
        onstop: (() => void) | null = null;
        start() { this.state = 'recording'; }
        stop() { this.state = 'inactive'; this.ondataavailable?.({ data: new Blob(['x'], { type: 'audio/webm' }) }); this.onstop?.(); }
    }
    vi.stubGlobal('MediaRecorder', RegistratoreFinto);

    return { stop };
}

const monta = (props: Record<string, unknown> = {}) => {
    w = mount(Registratore, { props: { condominioId: 3, disponibile: true, ...props }, global: { stubs: { Checkbox: { props: ['modelValue'], emits: ['update:modelValue'], template: '<input type="checkbox" :checked="modelValue" @change="$emit(\'update:modelValue\', $event.target.checked)" />' } } } });

    return w;
};

describe('il registratore', () => {
    test('senza la chiave di Deepgram il tasto non c\'è, e lo dice', () => {
        browser(CHROME);
        const r = monta({ disponibile: false });
        expect(r.find('[data-testid="registratore-rec"]').exists()).toBe(false);
        expect(r.get('[data-testid="registratore-spento"]').text()).toContain('KONDO_DEEPGRAM_KEY');
    });

    test('se la pagina non lo dice, chiede al server se la trascrizione c\'è', async () => {
        browser(CHROME);
        get.mockResolvedValue({ data: { trascrizione: false, riassunto: false, giorni_audio: 30, secondi_segmento: 25 } });
        const r = monta({ disponibile: null });
        await flushPromises();
        expect(get).toHaveBeenCalledWith('/gestionale.registrazioni.servizio/3');
        expect(r.find('[data-testid="registratore-spento"]').exists()).toBe(true);
    });

    test('il tasto non parte finché non si conferma l\'avviso ai presenti', async () => {
        browser(CHROME);
        const r = monta();
        expect(r.get('[data-testid="registratore-rec"]').attributes('disabled')).toBeDefined();
        await r.get('[data-testid="registratore-rec"]').trigger('click');
        expect(post).not.toHaveBeenCalled();

        await r.get('[data-testid="registratore-avviso"] input').setValue(true);
        expect(r.get('[data-testid="registratore-rec"]').attributes('disabled')).toBeUndefined();
    });

    test('l\'audio del computer: con Chrome la casella c\'è ed è accesa; con Safari no, e la pagina dice perché', () => {
        browser(CHROME);
        const chrome = monta();
        expect((chrome.get('[data-testid="registratore-computer"] input').element as HTMLInputElement).checked).toBe(true);
        chrome.unmount();

        browser(SAFARI);
        const safari = monta();
        expect(safari.find('[data-testid="registratore-computer"]').exists()).toBe(false);
        expect(safari.get('[data-testid="registratore-solo-microfono"]').text()).toContain('Chrome o Edge');
    });

    test('avviato: la conferma, l\'assemblea, il formato; condivisione rifiutata = solo microfono, detto; un pezzo sale col suo numero e il testo compare', async () => {
        browser(CHROME);
        post.mockImplementation(async (url: string, dati: unknown) => {
            if (url.startsWith('/gestionale.registrazioni.avvia')) return { data: { id: 41, titolo: 'Assemblea', secondi_segmento: 25, url: '/r/41' } };
            if (url.startsWith('/gestionale.registrazioni.segmento')) return { data: { numero: Number((dati as FormData).get('numero')), testo: 'Buonasera a tutti.' } };

            return { data: { message: 'Registrazione chiusa.' } };
        });
        const r = monta({ assembleaId: 9, titolo: 'Assemblea ordinaria' });
        await r.get('[data-testid="registratore-avviso"] input').setValue(true);
        await r.get('[data-testid="registratore-rec"]').trigger('click');
        await flushPromises();

        expect(post.mock.calls[0][0]).toBe('/gestionale.registrazioni.avvia/3');
        expect(post.mock.calls[0][1]).toMatchObject({ avviso_presenti: true, assemblea_id: 9, titolo: 'Assemblea ordinaria', formato: 'webm' });
        expect(r.get('[data-testid="registratore-avvisi"]').text()).toContain('registro solo il microfono');
        expect(r.emitted('avviata')?.[0]).toEqual([41]);

        await r.get('[data-testid="registratore-stop"]').trigger('click');
        await flushPromises();

        const pezzo = post.mock.calls.find((c) => String(c[0]).startsWith('/gestionale.registrazioni.segmento'))!;
        expect(pezzo[0]).toBe('/gestionale.registrazioni.segmento/3/41');
        expect((pezzo[1] as FormData).get('numero')).toBe('1');
        expect(((pezzo[1] as FormData).get('audio') as File).name).toBe('pezzo-1.webm');
        expect(post.mock.calls.at(-1)?.[0]).toBe('/gestionale.registrazioni.termina/3/41');
        expect(r.get('[data-testid="registratore-testo"]').text()).toContain('Buonasera a tutti.');
        expect(r.emitted('terminata')?.[0]).toEqual([41]);
    });
});
