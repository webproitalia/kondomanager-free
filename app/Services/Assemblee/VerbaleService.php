<?php

namespace App\Services\Assemblee;

use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\AssembleaPresenza;
use App\Models\Assemblee\AssembleaPunto;
use App\Models\Assemblee\Verbale;
use App\Models\Assemblee\VerbaleModello;
use App\Models\CategoriaDocumento;
use App\Models\Documento;
use App\Models\User;
use App\Services\Documenti\ArchivioDocumenti;
use App\Services\PDF\GruppoCarta;
use App\Services\PDF\PdfService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Il verbale dell'assemblea (Web Pro Italia, 17/11/2026).
 *
 * ## Da dove viene il testo
 *
 * Il verbale parte da un **modello dello studio** (`verbale_modelli`, uno predefinito): il programma riempie i
 * segnaposto con i dati dell'assemblea ({@see genera()}) e il risultato diventa il **testo del verbale**
 * (`verbali.testo`), libero e modificabile per intero, a mano o da un assistente. Rigenerare dal modello sovrascrive il
 * testo, e per questo chiede una conferma esplicita. Il PDF stampa il testo così com'è.
 *
 * Le fonti dei segnaposto restano i dati: i conteggi dalle presenze e dai voti, discussione e delibera dai campi dei
 * punti ({@see precompilaDaBozza()}, che riempie solo quelli vuoti), intestazione, apertura e chiusura dai campi del
 * verbale (segnaposti facoltativi).
 *
 * ## Gli stati
 *
 * `bozza` → `da_approvare` (chi l'ha preparato lo manda) → `approvato` (da una persona, mai da un agente AI: la rotta
 * è in `AutenticaAgente::VIETATE`). All'approvazione il PDF entra nell'archivio dei documenti del condominio e niente
 * dell'assemblea si modifica più, finché non si apre una nuova versione.
 *
 * **Anche in attesa di approvazione niente dell'assemblea si modifica** (voti, esiti, presenze, svolgimento, ordine del
 * giorno, testi dei punti, la bozza di una registrazione): chi approva deve firmare un testo che corrisponde ai dati.
 * Per correggere si ritira il verbale ({@see ritira()}). E prima di mandarlo in approvazione si controlla che il testo
 * corrisponda ai dati di adesso: vedi {@see ControlliVerbale}.
 */
final class VerbaleService
{
    public function __construct(
        private readonly SegnapostiVerbale $segnaposti,
        private readonly ControlliVerbale $controlli,
    ) {}

    /** Il verbale dell'assemblea; lo crea in bozza la prima volta che serve. */
    public function verbaleDi(Assemblea $assemblea): Verbale
    {
        $assemblea->loadMissing('verbale');
        $verbale = $assemblea->verbale ?? Verbale::firstOrCreate(['assemblea_id' => $assemblea->id], ['stato' => Verbale::BOZZA, 'versione' => 1]);
        $assemblea->setRelation('verbale', $verbale);

        return $verbale;
    }

    /**
     * Riempie con una bozza (della registrazione, di un assistente) **solo i campi vuoti** dei punti: discussione e
     * delibera del punto con quel numero. Quello che la persona ha già scritto non si tocca mai, nemmeno una parola.
     *
     * Non cambia il testo del verbale già generato: discussione e delibera sono le fonti dei segnaposto, ed entrano nel
     * testo alla prossima generazione dal modello.
     *
     * @param  list<array{numero:int|string, discussione?:?string, delibera?:?string}>  $bozzaPunti
     * @param  string  $fonte  da dove viene la bozza («registrazione», «assistente»…): va nel registro dell'applicazione
     * @return int  quanti campi ha riempito
     *
     * @throws ValidationException se il verbale è approvato (per scriverci si apre una nuova versione) o in attesa di
     *                             approvazione (prima si ritira)
     */
    public function precompilaDaBozza(Assemblea $assemblea, array $bozzaPunti, string $fonte): int
    {
        $this->assicuraModificabile($assemblea);

        $punti = AssembleaPunto::where('assemblea_id', $assemblea->id)->get()->keyBy(fn (AssembleaPunto $p) => (int) $p->numero);
        $riempiti = 0;

        DB::transaction(function () use ($bozzaPunti, $punti, &$riempiti) {
            foreach ($bozzaPunti as $voce) {
                if (! is_array($voce) || ! isset($voce['numero']) || ! is_numeric($voce['numero'])) {
                    continue;
                }
                $punto = $punti->get((int) $voce['numero']);
                if ($punto === null) {
                    continue;
                }
                $cambiato = false;
                foreach (['discussione', 'delibera'] as $campo) {
                    $proposto = isset($voce[$campo]) && is_string($voce[$campo]) ? trim($voce[$campo]) : '';
                    if ($proposto === '' || trim((string) $punto->{$campo}) !== '') {
                        continue;
                    }
                    $punto->{$campo} = $proposto;
                    $cambiato = true;
                    $riempiti++;
                }
                if ($cambiato) {
                    $punto->save();
                }
            }
        });

        Log::info('Assemblee: bozza del verbale applicata', ['assemblea_id' => $assemblea->id, 'fonte' => $fonte, 'campi_riempiti' => $riempiti]);

        return $riempiti;
    }

    /**
     * Un verbale approvato o in attesa di approvazione ferma ogni scrittura sull'assemblea: lo dice con un errore che la
     * pagina e l'agente leggono. In attesa di approvazione i dati non devono più cambiare, altrimenti chi approva
     * firmerebbe un testo che non corrisponde più ai dati: prima si ritira il verbale.
     */
    public function assicuraModificabile(Assemblea $assemblea): void
    {
        $assemblea->loadMissing('verbale');
        if ($assemblea->bloccata()) {
            throw ValidationException::withMessages([
                'verbale' => 'Il verbale di questa assemblea è approvato: non si modifica più. Per correggerlo apri una nuova versione dalla pagina del verbale.',
            ]);
        }
        if ($assemblea->verbale?->stato === Verbale::DA_APPROVARE) {
            throw ValidationException::withMessages([
                'verbale' => 'Il verbale è in attesa di approvazione: ritiralo per modificare (dalla pagina del verbale, «Torna in bozza»). Finché è in attesa niente dell\'assemblea cambia: presenze, voti, esiti, ordine del giorno e testi dei punti.',
            ]);
        }
    }

    // --- Il testo ------------------------------------------------------------------------------------------------

    /** Il testo che il modello darebbe adesso, senza salvarlo. Senza un modello, quello predefinito. */
    public function compila(Assemblea $assemblea, ?VerbaleModello $modello = null): string
    {
        $modello ??= VerbaleModello::predefinito();
        $corpo = $modello?->corpo ?? ModelloCanonico::CORPO;
        $verbale = $this->verbaleDi($assemblea);

        [$valori, $elenchi] = $this->segnaposti->valori($assemblea, [
            'intestazione' => $verbale->intestazione,
            'apertura' => $verbale->apertura,
            'chiusura' => $verbale->chiusura,
        ]);

        return CompilatoreModello::compila((string) $corpo, $valori, $elenchi);
    }

    /**
     * Genera il testo del verbale dal modello e lo salva. Se il verbale ha già un testo, lo sovrascrive solo con
     * `$sovrascrivi` (la pagina chiede la conferma, un agente lo dice nella richiesta).
     */
    public function genera(Assemblea $assemblea, ?VerbaleModello $modello, bool $sovrascrivi): Verbale
    {
        $this->assicuraModificabile($assemblea);
        $verbale = $this->verbaleDi($assemblea);

        if ($verbale->stato !== Verbale::BOZZA) {
            throw ValidationException::withMessages(['verbale' => 'Il verbale è in attesa di approvazione: ritiralo prima di rigenerarlo.']);
        }
        if (filled($verbale->testo) && ! $sovrascrivi) {
            throw ValidationException::withMessages(['sovrascrivi' => 'Il verbale ha già un testo: rigenerarlo dal modello lo sostituisce per intero. Conferma che vuoi sovrascriverlo.']);
        }

        $modello ??= VerbaleModello::predefinito();
        // Il testo e l'impronta nascono dagli stessi dati, riletti adesso dal database.
        $dati = Assemblea::findOrFail($assemblea->getKey());
        $verbale->forceFill([
            'testo' => $this->compila($dati, $modello),
            'modello_id' => $modello?->id,
            'impronta_dati' => $this->controlli->impronta($dati),
        ])->save();

        return $verbale;
    }

    /**
     * Il testo del verbale è stato salvato a mano (o da un assistente): si fotografa l'impronta dei dati di adesso. Chi
     * salva il testo lo ha scritto o riletto con questi dati; se poi i dati cambiano, l'invio in approvazione lo dice.
     */
    public function segnaTestoRiletto(Assemblea $assemblea): Verbale
    {
        $verbale = $this->verbaleDi($assemblea);
        $verbale->forceFill(['impronta_dati' => $this->controlli->impronta($assemblea)])->save();

        return $verbale;
    }

    /** Il testo che si stampa: quello del verbale, o (finché non c'è) quello che darebbe il modello predefinito. */
    public function testoDaStampare(Assemblea $assemblea): string
    {
        $verbale = $this->verbaleDi($assemblea);

        return filled($verbale->testo) ? (string) $verbale->testo : $this->compila($assemblea);
    }

    // --- Gli stati -----------------------------------------------------------------------------------------------

    /**
     * Chi l'ha preparato manda il verbale in approvazione. Prima si controlla che ci sia quello che un verbale deve
     * avere: il testo, presidente, segretario, la convocazione tenuta e l'esito di ogni punto. Poi che il testo
     * corrisponda ai dati ({@see ControlliVerbale::primaDiInviare()}): le ore, niente parti da compilare, esiti
     * coerenti con i voti, e nessun dato cambiato dopo l'ultimo testo.
     *
     * @param  bool  $datiRiletti  chi manda ha riletto il testo con i dati di adesso: procede anche se i dati sono cambiati dopo l'ultimo testo
     * @param  list<int>  $esitiConfermati  gli id dei punti di cui si conferma l'esito anche se i voti registrati ne danno un altro
     */
    public function inviaInApprovazione(Assemblea $assemblea, bool $datiRiletti = false, array $esitiConfermati = []): Verbale
    {
        $verbale = $this->verbaleDi($assemblea);
        if ($verbale->stato !== Verbale::BOZZA) {
            throw ValidationException::withMessages(['verbale' => $verbale->approvato()
                ? 'Il verbale è già approvato.'
                : 'Il verbale è già in attesa di approvazione.']);
        }

        $assemblea->loadMissing('punti');
        // I punti informativi non si votano: l'esito è «informativa» senza bisogno di confermarlo.
        foreach ($assemblea->punti as $p) {
            if ($p->informativo() && $p->esito === null) {
                $p->forceFill(['esito' => 'informativa'])->save();
            }
        }

        $errori = [];
        if (blank($verbale->testo)) {
            $errori['testo'] = 'Il verbale non ha ancora un testo: generalo dal modello o scrivilo.';
        }
        if (blank($assemblea->presidente)) {
            $errori['presidente'] = 'Scrivi chi presiede l\'assemblea.';
        }
        if (blank($assemblea->segretario)) {
            $errori['segretario'] = 'Scrivi chi fa da segretario.';
        }
        if ($assemblea->convocazione_tenuta === null) {
            $errori['convocazione_tenuta'] = 'Scegli se l\'assemblea si è tenuta in prima o in seconda convocazione.';
        }
        $senzaEsito = $assemblea->punti->filter(fn (AssembleaPunto $p) => $p->esito === null)->pluck('numero')->all();
        if ($senzaEsito !== []) {
            $errori['punti'] = (count($senzaEsito) === 1 ? 'Conferma l\'esito del punto ' : 'Conferma l\'esito dei punti ').implode(', ', $senzaEsito).' prima di mandare il verbale in approvazione.';
        }
        $errori += $this->controlli->primaDiInviare($assemblea, $verbale, $datiRiletti, array_map('intval', $esitiConfermati));
        if ($errori !== []) {
            throw ValidationException::withMessages($errori);
        }

        // Da qui il testo vale per i dati di adesso: anche chi ha proceduto con «dati_riletti» lascia l'impronta aggiornata.
        $verbale->forceFill([
            'stato' => Verbale::DA_APPROVARE,
            'inviato_in_approvazione_at' => now(),
            'impronta_dati' => $this->controlli->impronta($assemblea),
        ])->save();

        return $verbale;
    }

    /** Il verbale in attesa torna in bozza (per correggerlo prima che qualcuno lo approvi). */
    public function ritira(Assemblea $assemblea): Verbale
    {
        $verbale = $this->verbaleDi($assemblea);
        if ($verbale->stato !== Verbale::DA_APPROVARE) {
            throw ValidationException::withMessages(['verbale' => 'Si ritira solo un verbale in attesa di approvazione.']);
        }
        $verbale->forceFill(['stato' => Verbale::BOZZA, 'inviato_in_approvazione_at' => null])->save();

        return $verbale;
    }

    /**
     * L'approvazione, fatta da una persona: il PDF del testo entra nell'archivio dei documenti del condominio con la
     * categoria «Verbali di assemblea», e il verbale non si modifica più.
     */
    public function approva(Assemblea $assemblea, User $persona): Verbale
    {
        $verbale = $this->verbaleDi($assemblea);
        if ($verbale->stato !== Verbale::DA_APPROVARE) {
            throw ValidationException::withMessages(['verbale' => $verbale->approvato()
                ? 'Il verbale è già approvato.'
                : 'Prima di approvarlo il verbale va mandato in approvazione.']);
        }

        $archivio = app(ArchivioDocumenti::class);
        $percorso = null;

        try {
            DB::transaction(function () use ($assemblea, $verbale, $persona, $archivio, &$percorso) {
                $verbale->forceFill([
                    'stato' => Verbale::APPROVATO,
                    'approvato_at' => now(),
                    'approvato_by' => $persona->id,
                ])->save();
                $this->fotografaINomi($assemblea);

                $pdf = $this->pdf($assemblea, false);
                $percorso = $archivio->salvaContenuto($pdf, 'pdf');

                $documento = Documento::create([
                    'name' => $this->nomeDocumento($assemblea, $verbale),
                    'description' => 'Verbale approvato il '.\App\Helpers\DateHelper::adessoUtente()->format('d/m/Y').' da '.$persona->name.'.',
                    'path' => $percorso,
                    'mime_type' => 'application/pdf',
                    'file_size' => strlen($pdf),
                    'created_by' => $persona->id,
                    // Nell'archivio dello studio, non ancora nel portale: lo pubblica chi decide di mandarlo ai condòmini.
                    'is_published' => false,
                    'is_approved' => true,
                ]);
                $documento->condomini()->attach($assemblea->condominio_id);
                if ($categoria = CategoriaDocumento::perVerbaliAssemblea()) {
                    $documento->categorie()->attach($categoria->id);
                }

                $verbale->forceFill(['documento_id' => $documento->id])->save();
            });
        } catch (Throwable $e) {
            $archivio->elimina($percorso);
            throw $e;
        }

        return $verbale->fresh();
    }

    /**
     * All'approvazione i nomi di chi c'era e dei delegati restano scritti nelle righe delle presenze: se dopo
     * un'anagrafica viene eliminata, presenze e voti dell'assemblea approvata restano, con il loro nome.
     */
    private function fotografaINomi(Assemblea $assemblea): void
    {
        $presenze = AssembleaPresenza::with(['anagrafica:id,nome', 'delegato:id,nome'])->where('assemblea_id', $assemblea->id)->get();
        foreach ($presenze as $p) {
            $nomi = array_filter([
                'nome' => filled($p->anagrafica?->nome) ? trim((string) $p->anagrafica->nome) : null,
                'delegato_nome' => filled($p->delegato?->nome) ? mb_substr(trim((string) $p->delegato->nome), 0, 150) : null,
            ]);
            if ($nomi !== []) {
                $p->forceFill($nomi)->save();
            }
        }
    }

    /**
     * Una nuova versione di un verbale approvato: torna in bozza con il numero di versione che sale. Il PDF approvato
     * resta nell'archivio; alla prossima approvazione se ne aggiunge un altro.
     */
    public function riapri(Assemblea $assemblea): Verbale
    {
        $verbale = $this->verbaleDi($assemblea);
        if (! $verbale->approvato()) {
            throw ValidationException::withMessages(['verbale' => 'Si apre una nuova versione solo di un verbale approvato.']);
        }

        $verbale->forceFill([
            'stato' => Verbale::BOZZA,
            'versione' => (int) $verbale->versione + 1,
            'inviato_in_approvazione_at' => null,
            'approvato_at' => null,
            'approvato_by' => null,
        ])->save();

        return $verbale;
    }

    // --- La stampa -----------------------------------------------------------------------------------------------

    /** Il PDF del testo del verbale, con la carta dello studio del gruppo «Rendiconto e assemblea»; in bozza con la filigrana. */
    public function pdf(Assemblea $assemblea, bool $bozza): string
    {
        $assemblea->loadMissing('condominio');
        $verbale = $this->verbaleDi($assemblea);

        $config = ['orientation' => 'P', 'margin_left' => 18, 'margin_right' => 18];
        if ($bozza) {
            $config += ['showWatermarkText' => true, 'watermarkText' => 'BOZZA', 'watermarkTextAlpha' => 0.08];
        }

        $mpdf = app(PdfService::class)->generate('pdf.gestionale.verbale_assemblea', [
            'condominio' => $assemblea->condominio,
            'assemblea' => $assemblea,
            'verbale' => $verbale,
            'testo' => $this->testoDaStampare($assemblea),
            'bozza' => $bozza,
            'titolo_stampa' => 'Verbale dell\'assemblea '.($assemblea->tipo === 'straordinaria' ? 'straordinaria' : 'ordinaria'),
            'senza_firma' => true,
            'gruppo_carta' => GruppoCarta::RENDICONTO_E_ASSEMBLEA,
        ], $config);

        return $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    }

    public function nomeFile(Assemblea $assemblea, bool $bozza): string
    {
        $assemblea->loadMissing('condominio');

        return PdfService::nomeFile($bozza ? 'bozza-verbale-assemblea' : 'verbale-assemblea', (string) $assemblea->condominio->nome, $assemblea->giorno()?->format('Y-m-d'));
    }

    private function nomeDocumento(Assemblea $assemblea, Verbale $verbale): string
    {
        $giorno = $assemblea->giorno();
        $nome = 'Verbale assemblea '.($assemblea->tipo === 'straordinaria' ? 'straordinaria' : 'ordinaria')
            .($giorno ? ' del '.$giorno->format('d/m/Y') : '');

        return $verbale->versione > 1 ? $nome.' — versione '.$verbale->versione : $nome;
    }

    /** «Condominio Aurora» con la parola davanti una volta sola. */
    public static function condominioConNome(string $nome): string
    {
        $nome = trim($nome);

        return preg_match('/^(condominio|supercondominio)\b/iu', $nome) ? $nome : 'Condominio '.$nome;
    }
}
