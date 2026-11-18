/**
 * Andare a un altro condominio, dal selettore della barra in alto o dalla riga di testa del gruppo del condominio nel menu.
 *
 * Dentro un condominio si resta nella stessa pagina dove la pagina lo sa fare e l'indirizzo esiste anche nell'altro
 * (`indirizzoAltroCondominio` in `percorsoPagina.ts`); altrimenti, e dalle pagine dello studio, si apre il suo cruscotto.
 *
 * È una scelta esplicita di chi lavora: se il gruppo del condominio nel menu era ripiegato, si riapre.
 *
 * «Chiudi il condominio» toglie quello in uso; da dentro una sua pagina si torna alla dashboard dello studio.
 */
import { router, usePage } from '@inertiajs/vue3';
import { usePermission } from '@/composables/permissions';
import { useCondominioInUso } from './condominioInUso';
import { useElencoCondomini } from './elencoCondomini';
import { indirizzoAltroCondominio, usePercorsoPagina } from './percorsoPagina';
import type { Building } from '@/types/buildings';

export function useCambioCondominio(opzioni: { gestionale?: boolean } = {}) {
    const page = usePage();
    const { generatePath } = usePermission();
    const { condomini } = useElencoCondomini();
    const dellaPagina = usePercorsoPagina();
    const { riapri, dimentica } = useCondominioInUso();

    const cruscottoDi = (id: number) => generatePath('gestionale/:condominio', { condominio: id });

    /** Va al condominio scelto. `sezione` (dal menu, senza un condominio in uso): la sezione a cui andare, per esempio `/immobili`. */
    const vaiAlCondominio = (id: number, sezione?: string): void => {
        riapri();
        if (sezione !== undefined) {
            router.visit(generatePath(`gestionale/:condominio${sezione}`, { condominio: id }));

            return;
        }
        const attuale = opzioni.gestionale ? (page.props.condominio as Building | undefined)?.id : undefined;
        if (attuale === id) {
            return;
        }
        if (! attuale) {
            router.visit(cruscottoDi(id));

            return;
        }
        router.visit(indirizzoAltroCondominio({
            url: page.url,
            attuale,
            nuovo: id,
            sulPosto: !! dellaPagina?.cambiaCondominioSulPosto,
            cruscotto: cruscottoDi(id),
            esercizioAperto: condomini.value.find((c) => c.id === id)?.esercizio_aperto?.id,
        }), { preserveState: false });
    };

    /** Nessun condominio in uso: il menu torna a «Scegli un condominio». Da dentro una sua pagina si va alla dashboard. */
    const chiudiCondominio = (): void => {
        dimentica();
        if (opzioni.gestionale) {
            router.visit(generatePath('dashboard'));
        }
    };

    return { vaiAlCondominio, chiudiCondominio };
}
