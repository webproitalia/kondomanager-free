// @vitest-environment jsdom

/**
 * Dove sta il menu (Web Pro Italia, 18/11/2026): la scelta di Impostazioni → Aspetto arriva scritta sulla pagina
 * (`<html data-km-menu>`). Il menu laterale vale solo se la pagina lo dice: senza la scelta scritta, o con un valore che non
 * esiste, resta l'impalcatura di sempre, che è la via di ritorno.
 */
import { afterEach, describe, expect, test } from 'vitest';
import { impalcaturaScelta, useImpalcatura } from './useImpalcatura';

afterEach(() => delete document.documentElement.dataset.kmMenu);

describe('la scelta dell\'impalcatura', () => {
    test('«laterale»: l\'impalcatura a menu laterale', () => {
        document.documentElement.dataset.kmMenu = 'laterale';
        expect(impalcaturaScelta()).toBe('laterale');
        expect(useImpalcatura().menuLaterale).toBe(true);
    });

    test('«alto», niente, o un valore che non esiste: la testata di sempre', () => {
        for (const valore of ['alto', undefined, 'sotto']) {
            if (valore === undefined) {
                delete document.documentElement.dataset.kmMenu;
            } else {
                document.documentElement.dataset.kmMenu = valore;
            }
            expect(impalcaturaScelta(), String(valore)).toBe('alto');
            expect(useImpalcatura().menuLaterale).toBe(false);
        }
    });
});
