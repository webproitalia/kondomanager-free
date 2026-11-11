<?php

namespace App\Services\Import\Livelli;

use App\Models\Condominio;
use App\Models\Immobile;
use App\Models\ImportBatchItem;
use App\Models\Palazzina;
use App\Models\TipologiaImmobile;
use App\Services\Import\Canonical\CanonicalImmobile;
use App\Services\Import\EsitoCommit;
use App\Services\Import\ImportContext;
use App\Services\Import\LivelloImport;
use App\Services\Import\PrerequisitoMancante;
use App\Services\Import\Rilievo;

/**
 * Livello 5 — le unità immobiliari.
 *
 * ## Due vocabolari che non coincidono
 *
 * Danea scrive `Appartamento`; il nostro elenco di tipologie ha `Abitazione`, `Box`, `Garage`,
 * `Cantina` e altre ventisei voci, ma **non** «Appartamento». Le corrispondenze ovvie si fanno,
 * le altre no: una tipologia inventata è peggio di una tipologia mancante, perché sparisce
 * dalla vista e resta sbagliata.
 *
 * ## Le palazzine si importano, non si buttano
 *
 * `palazzine` chiede solo `condominio_id` e `name`: costa quasi niente crearle, e conserva
 * un'informazione che altrimenti finirebbe in una nota di testo.
 */
final class LivelloUnita implements LivelloImport
{
    public const CHIAVE = 'unita';

    /**
     * Le equivalenze fra il vocabolario di Danea e il nostro. Solo quelle di cui si è certi:
     * il resto resta senza tipologia, con un avviso.
     */
    private const TIPOLOGIE = [
        'appartamento' => 'Abitazione',
        'abitazione' => 'Abitazione',
        'box' => 'Box',
        'garage' => 'Garage',
        'cantina' => 'Cantina',
        'negozio' => 'Negozio',
        'ufficio' => 'Ufficio',
        'magazzino' => 'Magazzino',
        'posto auto' => 'Posto auto',
        'soffitta' => 'Soffitta',
        'sottotetto' => 'Sottotetto',
        'taverna' => 'Taverna',
    ];

    public function chiave(): string
    {
        return self::CHIAVE;
    }

    public function etichetta(): string
    {
        return 'Unità immobiliari';
    }

    public function dipendeDa(): array
    {
        return [LivelloCondominio::CHIAVE];
    }

    public function verificaPrerequisiti(ImportContext $ctx): array
    {
        $mancanti = [];

        $condominio = $ctx->risolto(LivelloCondominio::CHIAVE);

        if (! $condominio instanceof Condominio || ! Condominio::whereKey($condominio->getKey())->exists()) {
            $mancanti[] = new PrerequisitoMancante(
                'unita.condominio_mancante',
                'Le unità non possono entrare: il condominio a cui appartengono non è in archivio.',
                'Importa prima il livello «Condominio».',
            );
        }

        $unita = $ctx->canonico(self::CHIAVE);

        if (! is_array($unita) || $unita === []) {
            $mancanti[] = new PrerequisitoMancante(
                'unita.dati_assenti',
                'Nessuno dei file caricati contiene le unità immobiliari.',
                'Serve l\'export «Elenco unità» di Danea, oppure il modello «Unità immobiliari».',
                // Un file che non c'è non è un'incoerenza: è una scelta di chi importa.
                bloccante: false,
            );
        }

        return $mancanti;
    }

    public function commit(ImportContext $ctx): EsitoCommit
    {
        /** @var array<string, CanonicalImmobile> $unita */
        $unita = $ctx->canonico(self::CHIAVE);
        /** @var Condominio $condominio */
        $condominio = $ctx->risolto(LivelloCondominio::CHIAVE);

        $creati = 0;
        $saltati = 0;
        $avvisi = [];
        $risolti = [];
        $tipiNonMappati = [];

        // ⚠️ **Due unità diverse non devono mai diventare la stessa** (Web Pro Italia, 11/11/2026).
        //
        // L'unità già in archivio si riconosce da palazzina e interno: serve a non creare doppioni quando
        // si reimporta, o quando l'unità era stata inserita a mano. Ma l'interno in un condominio non è
        // unico: nei grandi stabili si ripete a ogni piano (l'interno 5 del primo e quello del secondo), e
        // quando Danea lo lascia vuoto il lettore usa il progressivo, che può coincidere con l'interno vero
        // di un'altra unità. Prima, la seconda unità «trovava» la prima e veniva saltata: le sue persone
        // finivano sull'unità sbagliata (sei proprietari su un appartamento) e i suoi millesimi andavano in
        // conflitto con quelli della prima (`Duplicate entry … quote_tabella`), e l'importazione cadeva a
        // metà. Misurato su un condominio vero: 245 unità, 46 confuse.
        //
        // Ora: (1) un'unità già in archivio si usa una volta sola per importazione; (2) se il file ha il piano
        // e l'unità in archivio pure, devono coincidere; (3) se il file porta più unità con la stessa palazzina
        // e lo stesso interno, nessuna si aggancia a un'unità esistente: si creano tutte, e quella creata qui
        // porta il piano nell'interno quando serve a distinguerla («5 · p. 2»).
        $ripetuti = [];
        foreach ($unita as $d) {
            $k = mb_strtoupper(trim($d->palazzina)).'|'.mb_strtoupper($d->interno ?? '');
            $ripetuti[$k] = ($ripetuti[$k] ?? 0) + 1;
        }
        $presi = [];
        $giaPresenti = Immobile::where('condominio_id', $condominio->id)->count();
        $nuoveInMezzo = 0;

        foreach ($unita as $chiave => $dati) {
            $palazzina = $this->palazzina($condominio, $dati->palazzina);
            $interno = mb_strtoupper($dati->interno ?? '');
            $ripetuto = ($ripetuti[mb_strtoupper(trim($dati->palazzina)).'|'.$interno] ?? 0) > 1;

            // ⚠️ Prima il codice di Danea, poi l'interno (Web Pro Italia, 11/11/2026). Il file «Anagrafica e
            // millesimi» non ha la colonna dell'interno e il lettore ci mette il progressivo: caricato da solo
            // sopra unità già importate, l'interno 17 dei millesimi (che è il progressivo 17) trovava l'unità
            // con interno vero 17, cioè un'altra, e i millesimi finivano sulle unità sbagliate. Il codice
            // palazzina · gruppo · progressivo è scritto nella descrizione di ogni unità entrata da Danea.
            $esistente = $giaPresenti === 0 ? null : $this->perCodiceDanea($condominio, $palazzina, $dati, $presi);

            if ($esistente === null && ! $ripetuto && $giaPresenti > 0) {
                $esistente = Immobile::where('condominio_id', $condominio->id)
                    ->where('interno', $interno)
                    ->when($palazzina !== null, fn ($q) => $q->where('palazzina_id', $palazzina->id))
                    ->when($dati->piano !== null && $dati->piano !== '', fn ($q) => $q->where(fn ($p) => $p->whereNull('piano')->orWhere('piano', '')->orWhere('piano', $dati->piano)))
                    ->whereNotIn('id', $presi ?: [0])
                    ->first();

                // Stesso interno ma un altro progressivo di Danea: è un'altra unità, non questa.
                if ($esistente !== null && ($altro = self::progressivoDanea($esistente)) !== null && $altro !== trim($dati->progressivo)) {
                    $esistente = null;
                }
            }

            if ($esistente !== null) {
                $presi[] = $esistente->id;
                $risolti[$chiave] = $esistente;
                $ctx->registra(self::CHIAVE, ImportBatchItem::AZIONE_SALTATO, $esistente);
                $saltati++;

                continue;
            }

            $tipologia = $this->tipologia($dati->tipo);

            if ($dati->tipo !== null && $tipologia === null) {
                $tipiNonMappati[mb_strtolower($dati->tipo)] = $dati->tipo;
            }

            $immobile = Immobile::create([
                'condominio_id' => $condominio->id,
                'palazzina_id' => $palazzina?->id,
                'tipologia_id' => $tipologia?->id,
                'nome' => $dati->denominazione(),
                // `nome` e `descrizione` sono **NOT NULL** nella migrazione, e la schermata di
                // creazione li valida entrambi come `required`. Il database di sviluppo di
                // questa macchina li ha nullable — è stato alterato fuori dalle migrazioni — e
                // per questo un import che li ometteva passava qui e sarebbe fallito su ogni
                // installazione nuova. L'ha trovato lo schema dei test, che è costruito dalle
                // migrazioni e quindi dice la verità.
                'descrizione' => $dati->descrizione(),
                // La schermata di creazione fa `strtoupper` sull'interno. Se l'import non lo
                // facesse, la stessa unità creata a mano e importata risulterebbe diversa, e
                // il riconoscimento dei duplicati fallirebbe proprio fra le due strade che
                // devono incontrarsi.
                'interno' => $ripetuto && $dati->piano !== null && $dati->piano !== '' ? $interno.' · P. '.mb_strtoupper($dati->piano) : $interno,
                'piano' => $dati->piano,
                'subalterno_catasto' => $dati->datiCatastali,
                'note' => $dati->note,
                'attivo' => true,
            ]);

            $presi[] = $immobile->id;
            $risolti[$chiave] = $immobile;
            $ctx->registra(self::CHIAVE, ImportBatchItem::AZIONE_CREATO, $immobile);
            $creati++;
            $nuoveInMezzo += $giaPresenti > 0 ? 1 : 0;
        }

        if ($nuoveInMezzo > 0) {
            $avvisi[] = Rilievo::avviso(
                'unita.nuove_fra_esistenti',
                sprintf('%d unità del file non corrispondevano a nessuna di quelle già nel condominio e sono entrate come nuove.', $nuoveInMezzo),
                'Se il condominio aveva già queste unità (inserite a mano o con un altro programma), controlla l\'elenco delle unità: potrebbero essere doppie.',
            );
        }

        // Un avviso per tipologia sconosciuta, non uno per unità: sedici righe identiche
        // sarebbero rumore, e il rumore fa saltare anche gli avvisi che contano.
        foreach ($tipiNonMappati as $tipo) {
            $avvisi[] = Rilievo::avviso(
                'unita.tipologia_non_mappata',
                sprintf('La tipologia «%s» non esiste nell\'elenco di Kondomanager.', $tipo),
                'Le unità entrano senza tipologia: puoi assegnarla dopo, oppure aggiungere la voce '
                .'all\'elenco delle tipologie. Inventarne una equivalente sarebbe peggio, perché '
                .'sparirebbe dalla vista restando sbagliata.',
            );
        }

        $ctx->risolviMolti(self::CHIAVE, $risolti);

        return new EsitoCommit(creati: $creati, saltati: $saltati, avvisi: $avvisi);
    }

    /**
     * L'unità già in archivio con lo stesso codice di Danea, se ce n'è una sola. Il codice si legge dalla
     * descrizione che l'importazione scrive («Palazzina 1 · gruppo A · piano 2 · n. 37», vedi
     * CanonicalImmobile::descrizione()); il gruppo `0` o vuoto non compare, e un export porta la lettera
     * dove un altro porta lo zero: se uno dei due non ha il gruppo, conta palazzina e progressivo.
     */
    private function perCodiceDanea(Condominio $condominio, ?Palazzina $palazzina, CanonicalImmobile $dati, array $presi): ?Immobile
    {
        $progressivo = trim($dati->progressivo);

        if ($progressivo === '') {
            return null;
        }

        $gruppo = trim($dati->gruppo) === '0' ? '' : trim($dati->gruppo);
        $inizio = 'Palazzina '.trim($dati->palazzina).' · ';

        $trovati = Immobile::where('condominio_id', $condominio->id)
            ->when($palazzina !== null, fn ($q) => $q->where('palazzina_id', $palazzina->id))
            ->whereNotIn('id', $presi ?: [0])
            ->where('descrizione', 'like', addcslashes($inizio, '%_\\').'%n. '.addcslashes($progressivo, '%_\\'))
            ->get()
            ->filter(function (Immobile $i) use ($inizio, $gruppo, $progressivo) {
                $resto = mb_substr((string) $i->descrizione, mb_strlen($inizio));

                if (! preg_match('/^(?:gruppo (?<g>[^·]+?) · )?(?:piano [^·]+? · )?n\. (?<n>\S+)$/u', $resto, $m) || $m['n'] !== $progressivo) {
                    return false;
                }

                $suo = trim($m['g'] ?? '');

                return $gruppo === '' || $suo === '' || $suo === $gruppo;
            });

        return $trovati->count() === 1 ? $trovati->first() : null;
    }

    /** Il progressivo di Danea scritto nella descrizione di un'unità importata, se c'è. */
    private static function progressivoDanea(Immobile $immobile): ?string
    {
        return preg_match('/^Palazzina .+ · n\. (\S+)$/u', (string) $immobile->descrizione, $m) ? $m[1] : null;
    }

    private function palazzina(Condominio $condominio, string $nome): ?Palazzina
    {
        if (trim($nome) === '') {
            return null;
        }

        return Palazzina::firstOrCreate(
            ['condominio_id' => $condominio->id, 'name' => $nome],
        );
    }

    private function tipologia(?string $tipo): ?TipologiaImmobile
    {
        if ($tipo === null || trim($tipo) === '') {
            return null;
        }

        $nome = self::TIPOLOGIE[mb_strtolower(trim($tipo))] ?? null;

        if ($nome === null) {
            return null;
        }

        return TipologiaImmobile::whereRaw('LOWER(nome) = ?', [mb_strtolower($nome)])->first();
    }
}
