<?php

namespace App\Notifications\Assemblee;

use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\Verbale;
use App\Models\Documento;
use App\Models\Gestionale\RegistroInvio;
use App\Notifications\Concerns\TestoScrittoDaAltri;
use App\Notifications\LocalizedNotification;
use App\Services\Assemblee\InviiAssemblea;
use App\Services\Documenti\ArchivioDocumenti;
use App\Services\Gestionale\Lettere\EmissioneLettere;
use App\Services\Gestionale\Lettere\TestiLettere;
use App\Services\Gestionale\Ricevute\EmissioneRicevute;
use App\Services\Gestionale\Ricevute\ErroreDiPosta;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Una comunicazione dell'assemblea per email (Web Pro Italia, 17/11/2026): l'avviso di convocazione o il verbale
 * approvato, a un indirizzo, con il PDF allegato. È la sorella del foglio del conguaglio, sullo stesso disegno: la riga
 * del registro degli invii porta un gettone, che è l'identificativo della notifica, e il registro delle email
 * (`LogSentMessage`) la porta a «inviata» quando il server accetta il messaggio; se parte con errore la riga è «non
 * partita», con l'errore.
 *
 * - **Il PDF** è quello scritto all'invio, lo stesso a ogni tentativo: per la convocazione l'avviso di quella persona,
 *   conservato come documento della riga; per il verbale il PDF approvato dell'archivio del condominio.
 * - **Il testo** è l'oggetto e il testo scelti nella finestra (dal modello dello studio), con i segnaposto già
 *   riempiti per la persona. Il saluto e la chiusura li mette il programma, come le lettere; la risposta va all'email
 *   dello studio, se c'è nelle impostazioni di stampa.
 * - **Un verbale riaperto** fra l'accodamento e la partenza non parte: la riga dice perché
 *   ({@see InviiAssemblea::VERBALE_CAMBIATO}).
 * - Senza un server di posta il messaggio non parte.
 */
class ComunicazioneAssembleaNotification extends LocalizedNotification implements ShouldQueue
{
    use Queueable;
    use TestoScrittoDaAltri;

    /** @param  array<int, int>  $invii  la riga del registro che il messaggio porta */
    public function __construct(public array $invii = [])
    {
        parent::__construct();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        if (EmissioneRicevute::senzaServerPosta()) {
            RegistroInvio::whereIn('id', $this->invii ?: [0])->update(['stato' => RegistroInvio::STATO_NON_PARTITA, 'errore' => EmissioneRicevute::NON_PARTITO_SENZA_SERVER]);

            return false;
        }

        $righe = RegistroInvio::whereIn('id', $this->invii ?: [0])->where('stato', RegistroInvio::STATO_IN_CODA)->get();
        // Il verbale riaperto dopo l'accodamento: quello in coda non è più il verbale approvato, e non parte.
        $superate = $righe->filter(fn (RegistroInvio $r) => $r->tipo === RegistroInvio::TIPO_VERBALE_ASSEMBLEA && ! self::verbaleAncoraQuello($r))->pluck('id');
        if ($superate->isNotEmpty()) {
            RegistroInvio::whereIn('id', $superate)->update(['stato' => RegistroInvio::STATO_NON_PARTITA, 'errore' => InviiAssemblea::VERBALE_CAMBIATO]);
        }
        $this->invii = $righe->pluck('id')->diff($superate)->values()->all();

        return $this->invii !== [];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $riga = RegistroInvio::with('condominio')->findOrFail($this->invii[0]);
        $dati = (array) $riga->dati;
        $testo = self::testo((string) $riga->condominio?->nome, (string) ($dati['nome'] ?? $dati['persona']['nome'] ?? ''), $dati);
        $messaggio = (new MailMessage)->subject($testo['oggetto'])->greeting($testo['saluto']);
        foreach ($testo['righe'] as $r) {
            $messaggio->line($r);
        }
        $studio = EmissioneLettere::studio();
        if ($studio['email'] !== null && filter_var($studio['email'], FILTER_VALIDATE_EMAIL) !== false) {
            $messaggio->replyTo($studio['email'], $studio['nome']);
        }

        return $messaggio->salutation($testo['chiusura'])
            ->attachData($this->pdfDellaRiga($riga), (string) ($dati['allegato'] ?? 'assemblea.pdf'), ['mime' => 'application/pdf']);
    }

    /**
     * Il messaggio: l'oggetto, il saluto, le righe del testo, la chiusura con chi scrive. L'avviso (o il verbale) sta nel PDF.
     *
     * @param  array{oggetto?: string, testo?: string}  $dati
     * @return array{oggetto: string, saluto: string, righe: list<string>, chiusura: string}
     */
    public static function testo(string $condominio, string $nome, array $dati): array
    {
        $studio = EmissioneLettere::studio();
        // Le righe del testo senza i segni di un blocco di codice (l'accento grave, una riga che comincia con «~~~»): la mail è Markdown.
        $righe = array_values(array_filter(array_map(fn ($r) => preg_match('/^\s*~~~/u', $r) === 1 ? '' : self::testoComeTesto(str_replace('`', '', $r)),
            explode("\n", (string) ($dati['testo'] ?? ''))), fn ($r) => $r !== ''));

        return [
            'oggetto' => trim((string) preg_replace('/\s+/u', ' ', (string) ($dati['oggetto'] ?? ''))),
            'saluto' => trim($nome) !== '' ? 'Gentile '.self::nomeComeTesto($nome).',' : 'Buongiorno,',
            'righe' => $righe,
            // Chi scrive: lo studio, amministratore del condominio; senza lo studio nelle impostazioni di stampa, l'amministratore.
            'chiusura' => 'Cordiali saluti, '.self::testoComeTesto(($studio['nome'] !== null ? $studio['nome'].', amministratore' : 'l\'amministratore')
                .' del '.TestiLettere::condominioConNome($condominio, 'condominio')),
        ];
    }

    /** Il verbale della riga è ancora quello approvato, con lo stesso PDF nell'archivio. */
    private static function verbaleAncoraQuello(RegistroInvio $riga): bool
    {
        $verbale = Verbale::where('assemblea_id', $riga->documentabile_id)->first();

        return $riga->documentabile_type === Assemblea::class && $verbale !== null && $verbale->approvato()
            && (int) $verbale->documento_id === (int) ($riga->dati['documento_id'] ?? 0);
    }

    /** Il PDF della riga: quello conservato all'invio (la convocazione) o quello dell'archivio (il verbale). Lo stesso a ogni tentativo. */
    private function pdfDellaRiga(RegistroInvio $riga): string
    {
        $documento = isset($riga->dati['documento_id']) ? Documento::find((int) $riga->dati['documento_id']) : null;
        $contenuto = $documento === null ? null : app(ArchivioDocumenti::class)->leggi($documento->path);
        if ($contenuto === null) {
            throw new \RuntimeException('Il PDF da allegare non si trova sul disco dei documenti.');
        }

        return $contenuto;
    }

    public function failed(\Throwable $e): void
    {
        RegistroInvio::whereIn('id', $this->invii ?: [0])->where('stato', RegistroInvio::STATO_IN_CODA)->update([
            'stato' => RegistroInvio::STATO_NON_PARTITA,
            'errore' => ErroreDiPosta::daEccezione($e),
        ]);
    }
}
