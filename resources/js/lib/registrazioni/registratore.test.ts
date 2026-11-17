/**
 * Le funzioni pure del registratore: il formato che il browser sa registrare (Safari in mp4), quando si può prendere l'audio del computer,
 * il testo in diretta in ordine di numero, la trascrizione a turni e il riassunto in HTML senza niente che non sia testo.
 */
import { describe, expect, test } from 'vitest';
import {
    audioDelComputerPossibile,
    durata,
    durataAParole,
    formatoDaMime,
    megabyte,
    riassuntoInHtml,
    testoInDiretta,
    tipoAudio,
    turni,
} from './registratore';

const CHROME_WIN = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';
const EDGE = CHROME_WIN + ' Edg/131.0.0.0';
const SAFARI_MAC = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Safari/605.1.15';
const FIREFOX = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:132.0) Gecko/20100101 Firefox/132.0';
const CHROME_ANDROID = 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Mobile Safari/537.36';
const conCondivisione = (userAgent: string) => ({ userAgent, mediaDevices: { getDisplayMedia: () => null } });

describe('il formato', () => {
    test('Chrome registra in webm/opus, Safari in mp4', () => {
        expect(tipoAudio((t) => t.startsWith('audio/webm'))).toEqual({ mime: 'audio/webm;codecs=opus', formato: 'webm' });
        expect(tipoAudio((t) => t === 'audio/mp4')).toEqual({ mime: 'audio/mp4', formato: 'mp4' });
        expect(tipoAudio(() => false)).toBeNull();
        expect(tipoAudio(() => { throw new Error('tipo sconosciuto'); })).toBeNull();
    });

    test('dal tipo del pezzo al formato del file', () => {
        expect(formatoDaMime('audio/mp4;codecs=mp4a.40.2')).toBe('mp4');
        expect(formatoDaMime('audio/webm;codecs=opus')).toBe('webm');
        expect(formatoDaMime('audio/ogg')).toBe('ogg');
        expect(formatoDaMime('')).toBe('webm');
    });
});

describe('l\'audio del computer (videochiamata)', () => {
    test('Chrome ed Edge su computer sì; Safari, Firefox e il telefono no', () => {
        expect(audioDelComputerPossibile(conCondivisione(CHROME_WIN))).toBe(true);
        expect(audioDelComputerPossibile(conCondivisione(EDGE))).toBe(true);
        expect(audioDelComputerPossibile(conCondivisione(SAFARI_MAC))).toBe(false);
        expect(audioDelComputerPossibile(conCondivisione(FIREFOX))).toBe(false);
        expect(audioDelComputerPossibile(conCondivisione(CHROME_ANDROID))).toBe(false);
    });

    test('senza getDisplayMedia no, qualunque sia il browser', () => {
        expect(audioDelComputerPossibile({ userAgent: CHROME_WIN, mediaDevices: {} })).toBe(false);
        expect(audioDelComputerPossibile({ userAgent: CHROME_WIN })).toBe(false);
        expect(audioDelComputerPossibile(undefined)).toBe(false);
    });
});

describe('i tempi', () => {
    test('il cronometro', () => {
        expect(durata(0)).toBe('00:00');
        expect(durata(65)).toBe('01:05');
        expect(durata(5400)).toBe('1:30:00');
    });

    test('la durata a parole', () => {
        expect(durataAParole(20)).toBe('meno di un minuto');
        expect(durataAParole(45 * 60)).toBe('45 min');
        expect(durataAParole(80 * 60)).toBe('1 h 20 min');
        expect(durataAParole(120 * 60)).toBe('2 h');
    });
});

describe('i testi', () => {
    test('il testo in diretta segue il numero dei pezzi, non l\'arrivo', () => {
        expect(testoInDiretta({ 3: 'terzo', 1: 'primo', 2: '  ' })).toBe('primo terzo');
    });

    test('la trascrizione a turni: tempo, voce, testo', () => {
        expect(turni('[00:00] Persona 1: Buonasera.\n\n[1:02:15] Persona 2: Approvato.\n\nUn capoverso senza tempo')).toEqual([
            { tempo: '00:00', voce: 'Persona 1', testo: 'Buonasera.' },
            { tempo: '1:02:15', voce: 'Persona 2', testo: 'Approvato.' },
            { tempo: null, voce: null, testo: 'Un capoverso senza tempo' },
        ]);
        expect(turni(null)).toEqual([]);
    });

    test('il riassunto in HTML: sezioni, elenchi, grassetti; nessun tag che venga dal testo', () => {
        const html = riassuntoInHtml('**In breve**\nApprovato il **rendiconto**.\n\n**Decisioni**\n- Lavori al tetto <script>x</script>\n- Fondo di riserva');
        expect(html).toBe('<h3>In breve</h3><p>Approvato il <strong>rendiconto</strong>.</p><h3>Decisioni</h3><ul><li>Lavori al tetto &lt;script&gt;x&lt;/script&gt;</li><li>Fondo di riserva</li></ul>');
    });

    test('i megabyte', () => {
        expect(megabyte(3355443)).toBe('3,2 MB');
        expect(megabyte(2000)).toBe('0,1 MB');
        expect(megabyte(0)).toBe('0,0 MB');
    });
});
