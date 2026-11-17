/**
 * Le registrazioni delle riunioni (Web Pro Italia, 17/11/2026): le funzioni pure del registratore e delle pagine, provate in
 * `registratore.test.ts`. Il registratore vive in `components/registrazioni/RegistratoreAssemblea.vue`.
 */

export type Formato = 'webm' | 'mp4' | 'ogg';

/** I tipi che MediaRecorder prova, in ordine: webm/opus (Chrome, Edge, Firefox), mp4/AAC (Safari). */
export const TIPI_AUDIO = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4', 'audio/ogg;codecs=opus'] as const;

/** Il primo tipo che il browser sa registrare, con il formato del file. Senza nessuno, `null`: si lascia scegliere al browser. */
export function tipoAudio(supportato: (tipo: string) => boolean): { mime: string; formato: Formato } | null {
    for (const tipo of TIPI_AUDIO) {
        try {
            if (supportato(tipo)) return { mime: tipo, formato: formatoDaMime(tipo) };
        } catch {
            // Un browser che non conosce il tipo può anche lanciare: vale «non supportato».
        }
    }

    return null;
}

/** Il formato del file da un tipo MIME: Safari registra in mp4 (AAC), non in webm. */
export function formatoDaMime(mime: string | null | undefined): Formato {
    const m = String(mime || '').toLowerCase();
    if (m.includes('mp4') || m.includes('m4a') || m.includes('aac')) return 'mp4';
    if (m.includes('ogg')) return 'ogg';

    return 'webm';
}

/**
 * Il browser può prendere anche l'audio del computer (la videochiamata)? Serve `getDisplayMedia`, e l'audio della condivisione lo danno
 * Chrome ed Edge su computer; Safari e Firefox condividono lo schermo ma non l'audio. Sul telefono no.
 */
export function audioDelComputerPossibile(nav: { mediaDevices?: { getDisplayMedia?: unknown }; userAgent?: string } | undefined): boolean {
    if (!nav?.mediaDevices || typeof nav.mediaDevices.getDisplayMedia !== 'function') return false;
    const ua = String(nav.userAgent || '');
    if (/Android|iPhone|iPad|iPod|Mobile/i.test(ua)) return false;

    return /Chrome\/|Chromium\/|Edg\//.test(ua) && !/Firefox\//.test(ua);
}

/** «mm:ss», oppure «h:mm:ss» da un'ora in su. */
export function durata(secondi: number | null | undefined): string {
    const s = Math.max(0, Math.floor(Number(secondi) || 0));
    const h = Math.floor(s / 3600);
    const m = Math.floor((s % 3600) / 60);
    const r = s % 60;
    const due = (n: number) => String(n).padStart(2, '0');

    return h > 0 ? `${h}:${due(m)}:${due(r)}` : `${due(m)}:${due(r)}`;
}

/** La durata a parole per l'elenco: «45 min», «1 h 20 min», «meno di un minuto». */
export function durataAParole(secondi: number | null | undefined): string {
    const minuti = Math.round((Number(secondi) || 0) / 60);
    if (minuti < 1) return 'meno di un minuto';
    const h = Math.floor(minuti / 60);
    const m = minuti % 60;

    return h > 0 ? `${h} h${m ? ` ${m} min` : ''}` : `${m} min`;
}

/** Il testo in diretta: i pezzi in ordine di numero (non di arrivo), i vuoti saltati. */
export function testoInDiretta(pezzi: Record<number, string>): string {
    return Object.keys(pezzi)
        .map(Number)
        .sort((a, b) => a - b)
        .map((n) => (pezzi[n] || '').trim())
        .filter(Boolean)
        .join(' ');
}

export interface Turno {
    tempo: string | null;
    voce: string | null;
    testo: string;
}

/** La trascrizione finale, «[mm:ss] Persona N: …» un turno per capoverso, in pezzi da mostrare (niente HTML). */
export function turni(trascrizione: string | null | undefined): Turno[] {
    return String(trascrizione || '')
        .split(/\n\s*\n/)
        .map((c) => c.trim())
        .filter(Boolean)
        .map((c) => {
            const m = /^\[(\d{1,2}:\d{2}(?::\d{2})?)\]\s*(?:(Persona \d+):\s*)?([\s\S]*)$/.exec(c);

            return m ? { tempo: m[1], voce: m[2] ?? null, testo: m[3].trim() } : { tempo: null, voce: null, testo: c };
        });
}

const ESC: Record<string, string> = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
const esc = (s: string) => s.replace(/[&<>"']/g, (c) => ESC[c]);

/**
 * Il riassunto, scritto a sezioni («**In breve**», elenchi con «- »), in HTML semplice. Tutto il testo passa dall'escape: il riassunto lo
 * scrive un modello, e in pagina non deve poter entrare niente che non sia testo.
 */
export function riassuntoInHtml(testo: string | null | undefined): string {
    const fuori: string[] = [];
    let elenco = false;
    const chiudi = () => {
        if (elenco) {
            fuori.push('</ul>');
            elenco = false;
        }
    };

    for (const riga of String(testo || '').split('\n')) {
        const r = riga.trim();
        if (!r) {
            chiudi();
            continue;
        }
        const grassetto = esc(r).replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
        if (/^\*\*[^*]+\*\*:?$/.test(r) || /^#{1,4}\s/.test(r)) {
            chiudi();
            fuori.push(`<h3>${esc(r.replace(/^#{1,4}\s*/, '').replace(/\*/g, '').replace(/:$/, ''))}</h3>`);
            continue;
        }
        if (/^[-•*]\s+/.test(r)) {
            if (!elenco) {
                fuori.push('<ul>');
                elenco = true;
            }
            fuori.push(`<li>${grassetto.replace(/^[-•*]\s+/, '')}</li>`);
            continue;
        }
        chiudi();
        fuori.push(`<p>${grassetto}</p>`);
    }
    chiudi();

    return fuori.join('');
}

export const ETICHETTA_STATO: Record<string, string> = {
    in_corso: 'In corso',
    in_elaborazione: 'In preparazione',
    pronta: 'Pronta',
    errore: 'Errore',
};

/** Byte in «1,4 MB». */
export function megabyte(byte: number | null | undefined): string {
    const mb = (Number(byte) || 0) / 1048576;

    return `${mb < 0.1 && mb > 0 ? '0,1' : mb.toFixed(1).replace('.', ',')} MB`;
}
