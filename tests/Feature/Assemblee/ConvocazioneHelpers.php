<?php

/**
 * Gli aiuti dei test della convocazione e degli invii dell'assemblea (Web Pro Italia, 17/11/2026). Nessun test qui
 * dentro. Prefisso `cnv`: Pest carica tutti i file nello stesso processo.
 */

use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\AssembleaPunto;
use App\Services\PDF\PdfService;

require_once __DIR__.'/AssembleeHelpers.php';

/**
 * Un'assemblea da convocare: Anna (Int. 1), Bruno e Carla comproprietari dell'Int. 2 (Bruno ha la quota più alta), Dino
 * (Int. 3, senza email), e due punti all'ordine del giorno.
 */
function cnvAssemblea(array $assemblea = []): array
{
    $s = asmCondominio([
        'Int. 1' => ['millesimi' => '450', 'titolari' => ['Anna Alberti' => 'proprietario']],
        'Int. 2' => ['millesimi' => '350', 'titolari' => ['Bruno Bassi' => ['proprietario', 60], 'Carla Conti' => ['proprietario', 40]]],
        'Int. 3' => ['millesimi' => '200', 'titolari' => ['Dino Dei' => 'proprietario']],
    ], array_merge(['stato' => 'bozza'], $assemblea));
    $s['persone']['Dino Dei']->forceFill(['email' => null])->save();
    $s['persone']['Anna Alberti']->forceFill(['indirizzo' => 'Via Roma 1, 03100 Frosinone'])->save();

    AssembleaPunto::create(['assemblea_id' => $s['assemblea']->id, 'numero' => 1, 'titolo' => 'Approvazione del rendiconto 2025', 'descrizione' => "Esame del rendiconto\ne del riparto", 'maggioranza' => 'ordinaria']);
    AssembleaPunto::create(['assemblea_id' => $s['assemblea']->id, 'numero' => 2, 'titolo' => 'Varie ed eventuali', 'maggioranza' => 'informativa']);

    return $s;
}

function cnvIndirizzo(string $nome, Assemblea $a, array $altri = []): string
{
    return route('admin.gestionale.assemblee.'.$nome, ['condominio' => $a->condominio_id, 'assemblea' => $a->id] + $altri);
}

/** Un servizio dei PDF che tiene la vista e i dati di ogni stampa, nell'ordine. */
function cnvSpia(): PdfService
{
    $spia = new class extends PdfService
    {
        public array $stampe = [];

        public function generate(string $view, array $data = [], array $config = []): \Mpdf\Mpdf
        {
            $this->stampe[] = ['vista' => $view, 'dati' => $data, 'config' => $config];

            return parent::generate($view, $data, $config);
        }
    };
    app()->instance(PdfService::class, $spia);

    return $spia;
}

/** I messaggi partiti dal trasporto di prova (`array`), nell'ordine. */
function cnvMessaggi(): \Illuminate\Support\Collection
{
    return collect(app('mailer')->getSymfonyTransport()->messages())->map->getOriginalMessage();
}

/** Il testo di un messaggio partito, senza i tag e con gli apostrofi veri. */
function cnvTesto(\Symfony\Component\Mime\Email $email): string
{
    return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $email->getHtmlBody()), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
}
