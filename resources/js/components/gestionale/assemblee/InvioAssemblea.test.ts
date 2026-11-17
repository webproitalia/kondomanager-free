// @vitest-environment jsdom

/**
 * La finestra degli invii dell'assemblea (la convocazione, il verbale): mostra chi riceve che cosa e chi non ha un indirizzo; di serie sono
 * spuntate le persone proposte dal server; «Manda per email» chiede la conferma e solo dopo manda `conferma: true` con l'elenco delle persone
 * che hanno un indirizzo; «Registra la spedizione» manda le persone spuntate con il canale, la data e il numero di ognuna; se non si può
 * mandare (il verbale non approvato) il tasto è spento e la pagina dice perché.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import type { AnteprimaInvio, PersonaInvio } from '@/lib/gestionale/assemblee';

const get = vi.fn();
const post = vi.fn();
const elimina = vi.fn();
vi.mock('axios', () => ({ default: { get: (...a: unknown[]) => get(...a), post: (...a: unknown[]) => post(...a), delete: (...a: unknown[]) => elimina(...a) } }));

const Invio = (await import('./InvioAssemblea.vue')).default;

const persona = (id: number, nome: string, altro: Partial<PersonaInvio> = {}): PersonaInvio => ({
    id, nome, indirizzo_postale: 'Via Roma 1', unita: [`Int. ${id}`], millesimi: '250,00', rappresentante: true, assente: null,
    indirizzi: [{ tipo: 'email', indirizzo: `${nome.toLowerCase().replace(' ', '.')}@prova.it` }], senza_email: false, solo_pec: false, proposta: true,
    mandata: false, in_coda: false, non_partita: false, quando: null, come: null, testo_cambiato: false, invii: [], ...altro,
});

const anteprima = (altro: Partial<AnteprimaInvio> = {}): AnteprimaInvio => ({
    cosa: 'convocazione', chi: 'tutti', indirizzi: ['email', 'email_secondaria'], tipi_indirizzo: { email: 'primaria', email_secondaria: 'secondaria', pec: 'PEC' },
    persone: [
        persona(1, 'Anna Alberti', { indirizzi: [{ tipo: 'email', indirizzo: 'anna@prova.it' }, { tipo: 'email_secondaria', indirizzo: 'anna.casa@prova.it' }] }),
        persona(2, 'Bruno Bassi', { mandata: true, proposta: false, come: 'email', quando: '2026-11-12',
            invii: [{ id: 9, canale: 'email', destinatario: 'bruno.bassi@prova.it', tipo_indirizzo: 'email', stato: 'inviata', quando: '2026-11-12 10:00', da: 'Studio', errore: null, spedizione: null, versione: null, attuale: true, pdf: true }] }),
        persona(3, 'Dino Dei', { indirizzi: [], senza_email: true }),
    ],
    riepilogo: { aventi_diritto: 3, persone: 3, con_email: 1, messaggi: 2, senza_indirizzo: ['Dino Dei'], gia_mandate: 1, in_coda: 0, non_partite: 0, testo_cambiato: 0 },
    elenco_preparato: true, oggetto: 'Convocazione dell\'assemblea', testo: 'In allegato trova l\'avviso.', modello: 'Email della convocazione (modello di serie)',
    esempio: { persona: 'Anna Alberti', oggetto: 'Convocazione dell\'assemblea', saluto: 'Gentile Anna Alberti,', righe: ['In allegato trova l\'avviso.'], chiusura: 'Cordiali saluti' },
    allegato: 'convocazione-assemblea.pdf', senza_server_posta: false, tetto_messaggi: null, tetto_pdf: 40,
    canali_spedizione: { raccomandata: 'raccomandata A/R', pec: 'PEC mandata fuori dal programma', a_mano: 'a mano', posta: 'posta ordinaria', altro: 'altro' },
    si_puo_mandare: true, perche_no: null, ...altro,
});

// La finestra di conferma mostra il suo tasto quando è aperta: basta questo per premere «Manda».
const stubs = {
    ConfirmDialog: { props: ['modelValue', 'title', 'description'], emits: ['confirm', 'update:modelValue'],
        template: '<div v-if="modelValue" data-testid="finestra-conferma"><p>{{ description }}</p><button data-testid="conferma-manda" @click="$emit(\'confirm\')">Manda</button></div>' },
};

let w: VueWrapper | null = null;
afterEach(() => {
    w?.unmount();
    w = null;
    get.mockReset();
    post.mockReset();
    elimina.mockReset();
});

const monta = (invio: AnteprimaInvio, cosa: 'convocazione' | 'verbale' = 'convocazione') => {
    w = mount(Invio, { props: { cosa, base: '/admin/gestionale/5/assemblee/7', invio, puoGestire: true }, global: { stubs } });

    return w;
};

describe('gli invii dell\'assemblea', () => {
    test('mostra chi riceve che cosa, chi non ha un indirizzo e che cosa è già stato mandato; di serie sono spuntate le persone proposte', () => {
        const pagina = monta(anteprima());
        const righe = pagina.findAll('[data-testid="riga-invio"]');

        expect(righe).toHaveLength(3);
        expect(righe[0].text()).toContain('Anna Alberti');
        expect(righe[0].text()).toContain('anna.casa@prova.it');
        expect(righe[1].text()).toContain('Mandata per email il 12/11/2026');
        expect(righe[2].text()).toContain('nessun indirizzo email: da stampare');
        // Spuntate Anna e Dino (proposte), non Bruno che l'ha già avuta: due messaggi, quelli di Anna.
        expect(righe.map((r) => (r.find('input[type="checkbox"]').element as HTMLInputElement).checked)).toEqual([true, false, true]);
        expect(pagina.find('[data-testid="manda-convocazione"]').text()).toContain('Manda per email (2)');
        expect(pagina.find('[data-testid="registra-convocazione"]').text()).toContain('Registra la spedizione (2)');
        expect(pagina.text()).toContain('Gentile Anna Alberti,');
    });

    test('«Manda per email» chiede la conferma, e solo dopo manda la conferma esplicita con le persone che hanno un indirizzo', async () => {
        post.mockResolvedValue({ data: { persone: 1, messaggi: 2, inviate: 2, in_coda: 0, non_partite: 0, errori: [], senza_indirizzo: [], saltate: [], restanti: null } });
        get.mockResolvedValue({ data: anteprima() });
        const pagina = monta(anteprima());

        await pagina.find('[data-testid="manda-convocazione"]').trigger('click');
        expect(post).not.toHaveBeenCalled();
        expect(pagina.find('[data-testid="finestra-conferma"]').text()).toContain('Partono 2 email a 1 persona');

        await pagina.find('[data-testid="conferma-manda"]').trigger('click');
        await flushPromises();

        expect(post).toHaveBeenCalledTimes(1);
        expect(post.mock.calls[0][0]).toBe('/admin/gestionale/5/assemblee/7/invii/convocazione');
        expect(post.mock.calls[0][1]).toMatchObject({ conferma: true, persone: [1], indirizzi: ['email', 'email_secondaria'], anche_gia_mandate: false,
            oggetto: 'Convocazione dell\'assemblea', testo: 'In allegato trova l\'avviso.' });
        expect(pagina.find('[data-testid="esito-invio"]').text()).toContain('2 messaggi a 1 persona');
        // Dopo l'invio l'anteprima si rilegge, e la pagina che la ospita viene avvisata.
        expect(get).toHaveBeenCalledWith('/admin/gestionale/5/assemblee/7/invii/convocazione', { params: { indirizzi: ['email', 'email_secondaria'], chi: 'tutti' } });
        expect(pagina.emitted('aggiornato')).toHaveLength(1);
    });

    test('il rifiuto del server si legge nella finestra, e niente risulta mandato', async () => {
        post.mockRejectedValue({ response: { data: { message: 'Dati non validi.', errors: { posta: ['Nessun server di posta configurato: le email non partono.'] } } } });
        const pagina = monta(anteprima());

        await pagina.find('[data-testid="manda-convocazione"]').trigger('click');
        await pagina.find('[data-testid="conferma-manda"]').trigger('click');
        await flushPromises();

        expect(pagina.find('[data-testid="errore-invio"]').text()).toContain('Nessun server di posta configurato');
        expect(pagina.find('[data-testid="esito-invio"]').exists()).toBe(false);
        expect(pagina.emitted('aggiornato')).toBeUndefined();
    });

    test('«Registra la spedizione» manda le persone spuntate con il canale, la data e il numero di ognuna', async () => {
        post.mockResolvedValue({ data: { registrate: 2 } });
        get.mockResolvedValue({ data: anteprima() });
        const pagina = monta(anteprima());

        await pagina.find('[data-testid="registra-convocazione"]').trigger('click');
        const modulo = pagina.find('[data-testid="modulo-spedizione"]');
        expect(modulo.exists()).toBe(true);
        await modulo.find('input[type="date"]').setValue('2026-11-10');
        // Il numero della raccomandata si scrive accanto al nome: compare per le persone spuntate.
        await pagina.findAll('[data-testid="riga-invio"]')[2].find('input[placeholder="numero (se c\'è)"]').setValue('15012345678-9');
        await pagina.find('[data-testid="conferma-spedizione"]').trigger('click');
        await flushPromises();

        expect(post.mock.calls[0][0]).toBe('/admin/gestionale/5/assemblee/7/invii/convocazione/spedizione');
        expect(post.mock.calls[0][1]).toEqual({ persone: [1, 3], canale: 'raccomandata', data: '2026-11-10', numeri: { 3: '15012345678-9' }, nota: null });
        expect(pagina.text()).toContain('Spedizione registrata per 2 persone.');
    });

    test('il verbale non approvato non si manda: il tasto è spento e la finestra dice perché; approvato, propone gli assenti', async () => {
        const fermo = monta(anteprima({ cosa: 'verbale', chi: 'assenti', si_puo_mandare: false, perche_no: 'Si manda solo un verbale approvato, con il suo PDF nell\'archivio: questo non lo è ancora.' }), 'verbale');
        expect(fermo.find('[data-testid="perche-no"]').text()).toContain('Si manda solo un verbale approvato');
        expect(fermo.find('[data-testid="manda-verbale"]').attributes('disabled')).toBeDefined();
        expect(fermo.find('[data-testid="registra-verbale"]').attributes('disabled')).toBeDefined();
        fermo.unmount();

        get.mockResolvedValue({ data: anteprima({ cosa: 'verbale', chi: 'tutti' }) });
        const pagina = monta(anteprima({ cosa: 'verbale', chi: 'assenti', persone: [persona(1, 'Anna Alberti', { assente: true }), persona(2, 'Bruno Bassi', { assente: false, proposta: false })] }), 'verbale');
        expect(pagina.findAll('[data-testid="riga-invio"]')[0].text()).toContain('assente');
        expect(pagina.find('[data-testid="manda-verbale"]').text()).toContain('Manda per email (1)');

        // «a tutti i condòmini» rilegge l'anteprima e ripropone le spunte del server.
        await pagina.find('[data-testid="chi-tutti"]').trigger('click');
        await flushPromises();
        expect(get).toHaveBeenCalledWith('/admin/gestionale/5/assemblee/7/invii/verbale', { params: { indirizzi: ['email', 'email_secondaria'], chi: 'tutti' } });
    });
});
