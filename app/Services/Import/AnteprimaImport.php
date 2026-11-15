<?php

namespace App\Services\Import;

use App\Services\Import\Canonical\CanonicalCondominio;
use App\Services\Import\Canonical\CanonicalSaldiApertura;
use App\Services\Import\Canonical\CanonicalSaldo;
use App\Services\Import\Canonical\CanonicalSoggetto;
use App\Services\Import\Canonical\CanonicalTabella;
use App\Services\Import\Canonical\CanonicalTitolarita;
use App\Services\Import\Livelli\LivelloCondominio;
use App\Services\Import\Livelli\LivelloEsercizi;
use App\Services\Import\Livelli\LivelloSaldi;
use App\Services\Import\Livelli\LivelloSoggetti;
use App\Services\Import\Livelli\LivelloTabelle;
use App\Services\Import\Livelli\LivelloTitolarita;
use App\Services\Import\Livelli\LivelloUnita;
use App\Helpers\MoneyHelper;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Gestione;
use App\Models\Immobile;
use App\Services\Import\Canonical\CanonicalEsercizio;
use App\Services\Import\Canonical\CanonicalImmobile;

/**
 * Traduce i dati canonici nella forma che la schermata di conferma mostra — S4 (§14.1).
 *
 * ## L'ordine delle sezioni non è casuale
 *
 * **La titolarità viene per prima**, prima delle persone e prima dei numeri. È la cosa che
 * rende una migrazione utile o inutile: un'unità senza titolare non riceve rate, non compare in
 * morosità e non entra in nessun riparto. Il concorrente, dopo dodici livelli importati, lascia
 * le unità con proprietario e inquilino vuoti (§0.3-D) — e nessuna schermata glielo dice.
 *
 * Subito dopo vengono i **saldi con la riga della quadratura**, che è il momento in cui
 * l'amministratore smette di avere paura: il totale non gliel'abbiamo chiesto, l'abbiamo letto
 * nel suo file.
 */
final class AnteprimaImport
{
    /**
     * @param  array<string, mixed>  $canonici
     * @param  array<string, mixed>|null  $decisioni  quelle già prese sul lotto. Con queste l'anteprima confronta
     *         anche tabelle e saldi con l'archivio (le decisioni dicono in quale esercizio andrebbero i saldi); senza —
     *         il conteggio delle domande aperte, il rapporto dopo il commit — il confronto non serve e non si fa,
     *         perché costa una ricerca per ogni unità
     * @return array<string, mixed>
     */
    public function costruisci(array $canonici, ?array $decisioni = null): array
    {
        $archivio = $decisioni === null ? null : $this->archivio($canonici);

        return [
            'condominio' => $this->condominio($canonici),
            'collisioni' => $this->collisioni($canonici),
            'titolarita' => $this->titolarita($canonici),
            'persone' => $this->persone($canonici),
            'unita' => $this->unita($canonici),
            'tabelle' => $this->tabelle($canonici, $archivio),
            'saldi' => $this->saldi($canonici, $archivio, $decisioni ?? []),
            'totale_record' => $this->totaleRecord($canonici),
        ];
    }

    /**
     * Il condominio di arrivo, **se esiste già**, con le sue unità abbinate a quelle del file — chiesto una volta sola
     * per le tabelle e per i saldi, che si confrontano con ciò che c'è (Web Pro Italia, 15/11/2026).
     *
     * Le unità che il commit creerebbe stanno nella mappa senza chiave primaria: così le ricerche del commit, rifatte
     * qui, le trovano come le troverebbe lui, e nessuna le scambia per un'unità che c'è già.
     *
     * @param  array<string, mixed>  $canonici
     * @return array{condominio: Condominio, unita: array<string, Immobile>}|null
     */
    private function archivio(array $canonici): ?array
    {
        $condominio = $canonici[LivelloCondominio::CHIAVE] ?? null;

        if (! $condominio instanceof CanonicalCondominio
            || (($canonici[LivelloTabelle::CHIAVE] ?? []) === [] && ! ($canonici[LivelloSaldi::CHIAVE] ?? null) instanceof CanonicalSaldiApertura)
        ) {
            return null;
        }

        $esistente = (new RicercaEsistenti)->condominio($condominio);

        if ($esistente === null) {
            return null;
        }

        /** @var array<string, CanonicalImmobile> $unita */
        $unita = $canonici[LivelloUnita::CHIAVE] ?? [];
        $abbinate = app(LivelloUnita::class)->abbinamenti($esistente, $unita);
        $mappa = [];

        foreach ($unita as $chiave => $u) {
            $mappa[$chiave] = $abbinate[$chiave] ?? (new Immobile)->forceFill(['nome' => $u->denominazione()]);
        }

        return ['condominio' => $esistente, 'unita' => $mappa];
    }

    /**
     * Chi, fra ciò che sta per entrare, **esiste già in archivio**.
     *
     * È l'unica sezione dell'anteprima che interroga il database invece di guardare solo il file,
     * e c'è per un motivo preciso: prima, il duplicato lo scopriva il commit. Il motore emetteva
     * un rilievo «da decidere» che nessuna schermata sapeva mostrare, e alla seconda importazione
     * sullo stesso archivio l'utente sbatteva contro un «Si è fermata a Persone» senza niente da
     * cliccare. La decisione va offerta **prima** di scrivere, che è anche l'unico momento in cui
     * ha senso porla.
     *
     * `crea_nuovo` non compare per il condominio: lo schema ha un indice unico sul codice
     * fiscale, quindi mostrarlo riporterebbe l'utente contro un errore di database.
     *
     * @param  array<string, mixed>  $canonici
     * @return array<string, mixed>
     */
    private function collisioni(array $canonici): array
    {
        $ricerca = new RicercaEsistenti;
        $voci = [];

        $condominio = $canonici[LivelloCondominio::CHIAVE] ?? null;
        $condominioEsistente = $condominio instanceof CanonicalCondominio
            ? $ricerca->condominio($condominio)
            : null;

        if ($condominio instanceof CanonicalCondominio) {
            $esistente = $condominioEsistente;

            if ($esistente !== null) {
                $voci[] = [
                    'chiave_decisione' => LivelloCondominio::CHIAVE.':'.$condominio->chiave(),
                    'cosa' => 'condominio',
                    'nome' => $condominio->nome,
                    'esistente' => $esistente->nome,
                    'motivo' => $ricerca->motivoCondominio($condominio, $esistente),
                    'scelte' => ['unisci', 'salta'],
                ];
            }
        }

        $esercizio = $canonici[LivelloEsercizi::CHIAVE] ?? null;

        if ($esercizio !== null) {
            $esistente = $ricerca->esercizio($esercizio, $condominioEsistente);

            if ($esistente !== null) {
                $voci[] = [
                    'chiave_decisione' => LivelloEsercizi::CHIAVE.':'.$esercizio->etichetta,
                    'cosa' => 'esercizio',
                    'nome' => $esercizio->etichetta,
                    'esistente' => $esistente->nome,
                    'motivo' => RicercaEsistenti::PER_PERIODO,
                    // «unisci» non c'è: due esercizi sullo stesso periodo sdoppierebbero i saldi,
                    // e il livello accetta solo «salta». Offrire l'altra porterebbe al muro.
                    'scelte' => ['salta'],
                ];
            }

            // Un **altro** esercizio aperto (Web Pro Italia, 15/11/2026): il livello non ne apre un secondo da
            // solo, chiede. La domanda va posta qui, prima della conferma, o l'importazione si fermerebbe a
            // «Esercizi» con una scelta che nessuna schermata offre.
            $aperto = $esistente === null && $condominioEsistente !== null
                ? app(LivelloEsercizi::class)->apertoDaDecidere($condominioEsistente, $esercizio)
                : null;

            if ($aperto !== null) {
                $voci[] = [
                    'chiave_decisione' => LivelloEsercizi::CHIAVE.':'.$esercizio->etichetta,
                    'cosa' => 'esercizio',
                    'nome' => $esercizio->etichetta,
                    'esistente' => $aperto->nome,
                    'motivo' => RicercaEsistenti::PER_APERTO,
                    // «salta» = usa l'esercizio aperto che c'è; «crea_nuovo» = apri anche quello del file.
                    'scelte' => ['salta', 'crea_nuovo'],
                    'spiegazione' => LivelloEsercizi::spiegazioneAperto($esercizio, $aperto)
                        .' «Usa quello che c\'è già» mette i dati del file in «'.$aperto->nome.'»; «crea il nuovo '
                        .'esercizio» apre anche «'.$esercizio->etichetta.'», e il gestionale continua a lavorare su «'.$aperto->nome.'» finché non lo chiudi.',
                ];
            }
        }

        /** @var array<string, CanonicalSoggetto> $soggetti */
        $soggetti = $canonici[LivelloSoggetti::CHIAVE] ?? [];

        foreach ($soggetti as $chiave => $s) {
            $esistente = $ricerca->soggetto($s);

            if ($esistente === null) {
                continue;
            }

            $voci[] = [
                'chiave_decisione' => LivelloSoggetti::CHIAVE.':'.$chiave,
                'cosa' => 'persona',
                'nome' => $s->nome,
                'esistente' => $esistente->nome,
                'motivo' => $ricerca->motivoSoggetto($s),
                'scelte' => ['unisci', 'salta'],
            ];
        }

        return [
            'totale' => count($voci),
            'voci' => $voci,
        ];
    }

    /**
     * @param  array<string, mixed>  $canonici
     */
    private function condominio(array $canonici): ?array
    {
        $c = $canonici[LivelloCondominio::CHIAVE] ?? null;
        $e = $canonici[LivelloEsercizi::CHIAVE] ?? null;

        if ($c === null) {
            return null;
        }

        return [
            'nome' => $c->nome,
            'codice_fiscale' => $c->codiceFiscale,
            'indirizzo' => trim(($c->indirizzo ?? '').' '.($c->cap ?? '').' '.($c->comune ?? '')),
            'esercizio' => $e === null ? null : [
                'etichetta' => $e->etichetta,
                'dal' => $e->dataInizio->format('d/m/Y'),
                'al' => $e->dataFine->format('d/m/Y'),
                'solare' => $e->isSolare(),
            ],
        ];
    }

    /**
     * Chi possiede cosa — e, in evidenza, **le unità che resterebbero senza nessuno**.
     *
     * @param  array<string, mixed>  $canonici
     */
    private function titolarita(array $canonici): array
    {
        /** @var list<CanonicalTitolarita> $righe */
        $righe = $canonici[LivelloTitolarita::CHIAVE] ?? [];
        $unita = $canonici[LivelloUnita::CHIAVE] ?? [];
        $soggetti = $canonici[LivelloSoggetti::CHIAVE] ?? [];

        $conTitolare = [];
        foreach ($righe as $t) {
            $conTitolare[$t->immobileRef] = true;
        }

        $orfane = array_values(array_diff(array_keys($unita), array_keys($conTitolare)));

        $elenco = [];
        // L'elenco esce **intero**: il taglio è di presentazione e lo fa la schermata, che ha
        // un «Vedile tutte» per toglierlo. Tagliare qui rendeva quel pulsante impossibile —
        // le righe oltre la dodicesima non arrivavano mai al client — e trasformava
        // un'anteprima in un campione senza modo di guardare il resto. Una migrazione tipica
        // è qualche centinaio di righe: non è un peso.
        foreach ($righe as $t) {
            /** @var CanonicalSoggetto|null $soggetto */
            $soggetto = $soggetti[$t->soggettoRef] ?? null;

            $elenco[] = [
                'unita' => $t->immobileRef,
                'persona' => $soggetto?->nome ?? $t->soggettoRef,
                'ruolo' => $t->ruolo->label(),
                'nota' => $soggetto?->note !== null && str_contains($soggetto->note, '%')
                    ? 'quota nelle note del file'
                    : null,
            ];
        }

        return [
            'totale' => count($righe),
            'unita_coinvolte' => count($conTitolare),
            'unita_senza_titolare' => $orfane,
            'elenco' => $elenco,
        ];
    }

    /**
     * @param  array<string, mixed>  $canonici
     */
    private function persone(array $canonici): array
    {
        /** @var array<string, CanonicalSoggetto> $soggetti */
        $soggetti = $canonici[LivelloSoggetti::CHIAVE] ?? [];

        $daDividere = [];

        foreach ($soggetti as $chiave => $s) {
            if (preg_match('#\s/\s#', $s->nome) === 1) {
                $daDividere[] = [
                    'chiave' => $chiave,
                    'nome' => $s->nome,
                    'pezzi' => array_map('trim', preg_split('#\s*/\s*#', $s->nome) ?: []),
                ];
            }
        }

        return [
            'totale' => count($soggetti),
            'senza_codice_fiscale' => count(array_filter($soggetti, fn (CanonicalSoggetto $s) => ! $s->haCodiceFiscale())),
            'da_dividere' => $daDividere,
        ];
    }

    /**
     * @param  array<string, mixed>  $canonici
     */
    private function unita(array $canonici): array
    {
        $unita = $canonici[LivelloUnita::CHIAVE] ?? [];

        // Se il condominio di arrivo esiste già: quante di queste unità ci sono già e quante entrerebbero nuove
        // (Web Pro Italia, 13/11/2026). Prima l'anteprima diceva solo «245 unità», e chi importava sopra un
        // condominio esistente non sapeva se le avrebbe ritrovate o raddoppiate.
        $riconoscimento = null;
        $condominio = $canonici[LivelloCondominio::CHIAVE] ?? null;

        if ($unita !== [] && $condominio instanceof CanonicalCondominio) {
            $esistente = (new RicercaEsistenti)->condominio($condominio);

            if ($esistente !== null) {
                $riconoscimento = app(LivelloUnita::class)->riconoscimento($esistente, $unita);
            }
        }

        return [
            'totale' => count($unita),
            'gia_presenti' => $riconoscimento['gia_presenti'] ?? null,
            'nuove' => $riconoscimento['nuove'] ?? null,
            'in_archivio' => $riconoscimento['in_archivio'] ?? null,
            'elenco' => array_map(
                fn ($u) => ['chiave' => $u->chiave(), 'nome' => $u->denominazione()],
                array_values($unita),
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $canonici
     */
    private function tabelle(array $canonici, ?array $archivio = null): array
    {
        /** @var array<string, CanonicalTabella> $tabelle */
        $tabelle = $canonici[LivelloTabelle::CHIAVE] ?? [];
        $totaleUnita = count($canonici[LivelloUnita::CHIAVE] ?? []);

        // Le tabelle che il condominio ha già: il commit le salta, e se sono diverse dal file restano quelle
        // dell'archivio. Va detto qui, prima di confermare, con che cosa è diverso.
        $confronto = $archivio === null || $tabelle === []
            ? []
            : app(LivelloTabelle::class)->confronto($archivio['condominio'], $tabelle, $archivio['unita']);

        $righe = [];

        foreach ($tabelle as $chiave => $t) {
            $righe[] = [
                'nome' => $t->nome,
                'partecipanti' => $t->partecipanti(),
                'somma' => round($t->somma(), 4),
                'parziale' => $t->partecipanti() < $totaleUnita,
                'parti_uguali' => $t->isPartiUguali(),
                // «nuova» entra; «uguale» c'è già com'è nel file; «diversa» c'è già con altri valori, resta quella
                // dell'archivio e `differenze` dice che cosa cambia.
                'in_archivio' => $confronto[$chiave]['stato'] ?? 'nuova',
                'differenze' => $confronto[$chiave]['differenze'] ?? [],
            ];
        }

        return $righe;
    }

    /**
     * Il riquadro della quadratura: il totale **letto nel file**, la somma delle righe, lo scarto.
     *
     * @param  array<string, mixed>  $canonici
     */
    private function saldi(array $canonici, ?array $archivio = null, array $decisioni = []): ?array
    {
        /** @var CanonicalSaldiApertura|null $saldi */
        $saldi = $canonici[LivelloSaldi::CHIAVE] ?? null;

        if ($saldi === null) {
            return null;
        }

        $cessati = array_filter($saldi->righe, fn (CanonicalSaldo $s) => $s->daTitolareCessato);

        return [
            'righe' => count($saldi->righe),
            'fonte' => $saldi->fonte ?? 'riparto consuntivo',
            // Tre stati e non due: quadra, non quadra, **non c'era niente da quadrare**.
            'verificabile' => $saldi->verificabile(),
            'totale_riferimento' => $saldi->totaleRiferimentoCents === null
                ? null
                : MoneyHelper::format($saldi->totaleRiferimentoCents),
            'somma_righe' => MoneyHelper::format($saldi->sommaRigheCents()),
            'arrotondamenti' => MoneyHelper::format($saldi->arrotondamentiCents),
            'scarto' => $saldi->scartoCents() === null ? null : MoneyHelper::format($saldi->scartoCents()),
            'quadra' => $saldi->quadra(),
            'da_titolari_cessati' => count($cessati),
            'importo_cessati' => MoneyHelper::format(
                array_sum(array_map(fn (CanonicalSaldo $s) => $s->importoCents, $cessati)),
            ),
            'in_archivio' => $this->saldiInArchivio($saldi, $canonici, $archivio, $decisioni),
        ];
    }

    /**
     * I saldi del file che il condominio ha già (Web Pro Italia, 15/11/2026): quanti uguali, quanti con un importo
     * diverso — che restano quelli dell'archivio — e quali. `null` quando non c'è niente con cui confrontarli (il
     * condominio o l'esercizio di arrivo nascono con questa importazione) o quando manca ancora la risposta su
     * quale esercizio usare.
     *
     * @param  array<string, mixed>  $canonici
     * @param  array<string, mixed>  $decisioni
     * @return array{uguali: int, diversi: int, nuove: int, differenze: list<string>}|null
     */
    private function saldiInArchivio(CanonicalSaldiApertura $saldi, array $canonici, ?array $archivio, array $decisioni): ?array
    {
        $esercizio = $canonici[LivelloEsercizi::CHIAVE] ?? null;

        if ($archivio === null || ! $esercizio instanceof CanonicalEsercizio) {
            return null;
        }

        $condominio = $archivio['condominio'];
        $usato = app(LivelloEsercizi::class)->esistenteCheUserebbe(
            $condominio,
            $esercizio,
            $decisioni[LivelloEsercizi::CHIAVE.':'.$esercizio->etichetta] ?? null,
        );

        if ($usato === null) {
            return null;
        }

        // La gestione che `CondominioService::createDefaultGestione()` riuserebbe: la prima del condominio di quel tipo.
        $gestione = Gestione::where('condominio_id', $condominio->id)
            ->where('tipo', $esercizio->eStraordinario() ? 'straordinaria' : 'ordinaria')
            ->first();

        $ricerca = new RicercaEsistenti;
        $soggetti = [];

        /** @var CanonicalSoggetto $s */
        foreach ($canonici[LivelloSoggetti::CHIAVE] ?? [] as $chiave => $s) {
            $esistente = $ricerca->soggetto($s);

            if ($esistente === null) {
                $soggetti[$chiave] = (new Anagrafica)->forceFill(['nome' => $s->nome]);

                continue;
            }

            // Una copia, che non si salva mai: con «unisci» la persona prende il nome del file, con «lascia com'è»
            // tiene il suo, ed è su quel nome che il commit cerca a chi è intestato il saldo.
            $copia = clone $esistente;

            if (($decisioni[LivelloSoggetti::CHIAVE.':'.$chiave] ?? null) === 'unisci') {
                $copia->nome = $s->nome;
            }

            $soggetti[$chiave] = $copia;
        }

        return app(LivelloSaldi::class)->confronto($saldi, $usato, $gestione, $archivio['unita'], $soggetti);
    }

    /**
     * Il numero che finisce **dentro** il pulsante, non accanto.
     *
     * @param  array<string, mixed>  $canonici
     */
    private function totaleRecord(array $canonici): int
    {
        $tabelle = $canonici[LivelloTabelle::CHIAVE] ?? [];
        $quote = array_sum(array_map(fn (CanonicalTabella $t) => $t->partecipanti(), $tabelle));

        // ⚠️ `?? null` e non accesso diretto: se **nessun** file caricato dichiara il condominio
        // — succede con gli export «Import/Export tabelle» di Danea, che non hanno la testata —
        // la chiave non esiste affatto, e leggerla qui faceva morire l'anteprima con un
        // «Undefined array key "condominio"» al posto di una spiegazione. Le sette righe qui
        // sotto si difendevano già; questa no. Segnalato il 28/08/2026 da un amministratore.
        return (($canonici[LivelloCondominio::CHIAVE] ?? null) !== null ? 1 : 0)
            + (isset($canonici[LivelloEsercizi::CHIAVE]) ? 1 : 0)
            + count($canonici[LivelloSoggetti::CHIAVE] ?? [])
            + count($canonici[LivelloUnita::CHIAVE] ?? [])
            + count($canonici[LivelloTitolarita::CHIAVE] ?? [])
            + count($tabelle)
            + $quote
            + count(($canonici[LivelloSaldi::CHIAVE] ?? null)?->righe ?? []);
    }
}
