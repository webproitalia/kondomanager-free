/**
 * Lo stile «professionale» è una pelle che si mette e si toglie (Web Pro Italia, 21/11/2026).
 *
 * Il foglio `stile-professionale.css` deve valere **solo** quando la pagina porta `<html data-km-stile="professionale">`:
 * con «morbido» (e con il menu in alto, dove lo stile è sempre quello di prima) nessuna sua regola deve toccare le pagine.
 * Qui si controlla il foglio stesso: ogni regola di primo livello comincia da quell'attributo. Una regola aggiunta fuori
 * farebbe cambiare aspetto anche a chi ha scelto lo stile di prima, e questo test la ferma.
 *
 * Controlla anche che i valori presi da Odoo 16 — quelli della tabella in `docs/stile_professionale.it.md` — siano ancora
 * scritti nel foglio: chi ne cambia uno lo fa sapendo che si allontana dal riferimento.
 *
 * Che cosa NON copre: come le pagine si vedono (le fotografie prima/dopo del lavoro) e la scelta in Impostazioni → Aspetto
 * (`resources/js/pages/impostazioni/impostazioniAspetto.test.ts`, `tests/Feature/Impostazioni/AspettoStileTest.php`).
 */
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, test } from 'vitest';

// I test stanno sotto resources/js (è lì che li cerca vitest); il foglio sta in resources/css.
const cartella = join(dirname(fileURLToPath(import.meta.url)), '..', '..', 'css');
const foglio = readFileSync(join(cartella, 'stile-professionale.css'), 'utf8');
const senzaCommenti = foglio.replace(/\/\*[\s\S]*?\*\//g, '');

/** I selettori delle regole di primo livello: ciò che precede ogni `{` aperta a profondità zero. */
function regoleDiPrimoLivello(css: string): string[] {
    const selettori: string[] = [];
    let profondita = 0;
    let inizio = 0;
    for (let i = 0; i < css.length; i += 1) {
        if (css[i] === '{') {
            if (profondita === 0) {
                selettori.push(css.slice(inizio, i).trim());
            }
            profondita += 1;
        } else if (css[i] === '}') {
            profondita -= 1;
            if (profondita === 0) {
                inizio = i + 1;
            }
        }
    }

    return selettori;
}

describe('il foglio vale solo con lo stile professionale', () => {
    const selettori = regoleDiPrimoLivello(senzaCommenti);

    test('ogni regola di primo livello comincia da <html data-km-stile="professionale">', () => {
        expect(selettori.length).toBeGreaterThan(0);
        for (const selettore of selettori) {
            for (const parte of selettore.split(',').map((p) => p.trim())) {
                expect(parte, parte).toMatch(/^html\[data-km-stile='professionale'\]/);
            }
        }
    });

    test('nessuna regola fuori posto: né `:root`, né `.dark` da soli, né un `@media` di primo livello', () => {
        expect(selettori.filter((s) => s.startsWith('@') || s.startsWith(':root') || s.startsWith('.'))).toEqual([]);
    });

    test('il foglio è importato da app.css dopo il menu laterale, di cui ridefinisce l\'area di lavoro', () => {
        const app = readFileSync(join(cartella, 'app.css'), 'utf8');
        expect(app.indexOf("@import './stile-professionale.css'")).toBeGreaterThan(app.indexOf("@import './menu-laterale.css'"));
    });
});

describe('i valori presi da Odoo 16', () => {
    const scritto = (dichiarazione: string) => expect(senzaCommenti.replace(/\s+/g, ' '), dichiarazione).toContain(dichiarazione);

    test('la scala dei grigi', () => {
        for (const [nome, valore] of Object.entries({ 100: '#f8f9fa', 200: '#e9ecef', 300: '#dee2e6', 400: '#ced4da', 500: '#adb5bd', 600: '#6c757d', 700: '#495057', 800: '#343a40', 900: '#212529' })) {
            scritto(`--km-g${nome}: ${valore};`);
        }
    });

    test('il testo, i titoli, le cornici, le righe e il passaggio del mouse delle tabelle', () => {
        scritto('--km-testo: var(--km-g700);');
        scritto('--km-titoli: var(--km-g900);');
        scritto('--km-bordo: var(--km-g300);');
        scritto('--km-separatore: var(--km-g200);');
        scritto('--km-testata-tabella: var(--km-g200);');
        scritto('--km-passaggio: rgb(0 0 0 / 0.055);');
    });

    test('i colori di stato, il raggio, l\'ombra dei menu a tendina', () => {
        scritto('--km-successo: #28a745;');
        scritto('--km-informazione: #17a2b8;');
        scritto('--km-avviso: #ffac00;');
        scritto('--km-pericolo: #dc3545;');
        scritto('--km-raggio: 3px;');
        scritto('--km-ombra-tendina: 0 1rem 1.1rem rgb(0 0 0 / 0.1);');
    });

    test('il carattere: quello del sistema, 13 px, e «grassetto» che pesa 500', () => {
        scritto("--km-carattere: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Ubuntu, 'Liberation Sans', Arial, sans-serif,");
        scritto('--text-sm: 0.8125rem;');
        scritto('--font-weight-bold: 500;');
        scritto('--font-weight-semibold: 500;');
    });

    test('il colore principale non lo decide lo stile: resta quello scelto in Aspetto', () => {
        expect(senzaCommenti).not.toMatch(/--primary\s*:/);
    });
});
