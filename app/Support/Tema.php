<?php

namespace App\Support;

use App\Settings\AspettoSettings;

/**
 * Palette dell'aspetto (Web Pro Italia, 09/11/2026). Ogni colore ridefinisce solo le variabili di
 * resources/css/app.css (primario, anello del focus, barra laterale, sfondo dei passaggi del mouse),
 * in chiaro e in scuro. «grafite» è l'aspetto originale di Kondomanager e non scrive niente.
 * I primari sono scuri abbastanza da tenere leggibile il testo bianco sui pulsanti.
 */
class Tema
{
    /** @var array<string, array{nome: string, campione: string, chiaro: array<string,string>, scuro: array<string,string>}> */
    public const COLORI = [
        'grafite'  => ['nome' => 'Grafite (originale)', 'campione' => '#171717', 'chiaro' => [], 'scuro' => []],
        'turchese' => ['nome' => 'Turchese', 'campione' => '#0fb3ad',
            'chiaro' => ['p' => '177 80% 30%', 'a' => '177 55% 94%', 'af' => '177 80% 20%'],
            'scuro'  => ['p' => '177 70% 45%', 'pf' => '180 40% 8%', 'a' => '177 35% 16%', 'af' => '177 60% 85%']],
        'blu'      => ['nome' => 'Blu', 'campione' => '#2563eb',
            'chiaro' => ['p' => '221 75% 45%', 'a' => '221 80% 95%', 'af' => '221 75% 30%'],
            'scuro'  => ['p' => '217 85% 62%', 'pf' => '222 47% 8%', 'a' => '221 35% 18%', 'af' => '217 85% 88%']],
        'indaco'   => ['nome' => 'Indaco', 'campione' => '#4f46e5',
            'chiaro' => ['p' => '243 62% 52%', 'a' => '243 80% 96%', 'af' => '243 55% 35%'],
            'scuro'  => ['p' => '239 84% 72%', 'pf' => '243 47% 10%', 'a' => '243 30% 20%', 'af' => '239 84% 90%']],
        'viola'    => ['nome' => 'Viola', 'campione' => '#7c3aed',
            'chiaro' => ['p' => '262 60% 48%', 'a' => '262 80% 96%', 'af' => '262 55% 32%'],
            'scuro'  => ['p' => '262 80% 72%', 'pf' => '262 47% 10%', 'a' => '262 30% 20%', 'af' => '262 80% 90%']],
        'verde'    => ['nome' => 'Verde', 'campione' => '#15803d',
            'chiaro' => ['p' => '146 64% 30%', 'a' => '146 50% 94%', 'af' => '146 64% 20%'],
            'scuro'  => ['p' => '146 55% 48%', 'pf' => '146 40% 8%', 'a' => '146 30% 15%', 'af' => '146 55% 85%']],
        'bordeaux' => ['nome' => 'Bordeaux', 'campione' => '#9f1239',
            'chiaro' => ['p' => '345 70% 36%', 'a' => '345 70% 96%', 'af' => '345 70% 26%'],
            'scuro'  => ['p' => '345 75% 65%', 'pf' => '345 40% 8%', 'a' => '345 30% 18%', 'af' => '345 75% 90%']],
        'arancio'  => ['nome' => 'Arancio', 'campione' => '#c2410c',
            'chiaro' => ['p' => '21 88% 40%', 'a' => '24 90% 95%', 'af' => '21 88% 28%'],
            'scuro'  => ['p' => '24 90% 60%', 'pf' => '21 40% 8%', 'a' => '21 35% 16%', 'af' => '24 90% 88%']],
    ];

    /** @var array<string, array{nome: string, raggio: ?string}> */
    public const ANGOLI = [
        'standard'  => ['nome' => 'Standard', 'raggio' => null],
        'morbidi'   => ['nome' => 'Morbidi', 'raggio' => '0.85rem'],
        'squadrati' => ['nome' => 'Squadrati', 'raggio' => '0.25rem'],
    ];

    /** Il CSS dell'aspetto salvato (per il layout). */
    public static function css(): string
    {
        try {
            $s = app(AspettoSettings::class);
        } catch (\Throwable) {
            return ''; // prima della migrazione delle impostazioni, o a database spento: aspetto originale
        }

        return self::cssPer($s->colore, $s->angoli);
    }

    /** Il CSS di una combinazione qualsiasi (anche per l'anteprima nella pagina Aspetto). */
    public static function cssPer(?string $chiaveColore, ?string $chiaveAngoli): string
    {
        $colore = self::COLORI[$chiaveColore] ?? self::COLORI['grafite'];
        $angoli = self::ANGOLI[$chiaveAngoli] ?? self::ANGOLI['standard'];

        $chiaro = self::variabili($colore['chiaro'], '0 0% 98%');
        $scuro  = self::variabili($colore['scuro'], '0 0% 9%');
        if ($angoli['raggio']) {
            $chiaro .= "--radius:{$angoli['raggio']};";
        }

        $out = '';
        if ($chiaro !== '') {
            $out .= ":root{{$chiaro}}";
        }
        if ($scuro !== '') {
            $out .= ".dark{{$scuro}}";
        }

        return $out;
    }

    /** @param array<string,string> $v */
    private static function variabili(array $v, string $testoSulPrimario): string
    {
        if (! isset($v['p'])) {
            return '';
        }
        $pf = $v['pf'] ?? $testoSulPrimario;

        return "--primary:hsl({$v['p']});--primary-foreground:hsl({$pf});--ring:hsl({$v['p']});"
            ."--sidebar-primary:hsl({$v['p']});--sidebar-primary-foreground:hsl({$pf});--sidebar-ring:hsl({$v['p']});"
            ."--accent:hsl({$v['a']});--accent-foreground:hsl({$v['af']});"
            ."--sidebar-accent:hsl({$v['a']});--sidebar-accent-foreground:hsl({$v['af']});";
    }

    /** Per la pagina delle impostazioni: chiave, nome e campione di ogni scelta. */
    public static function scelte(): array
    {
        return [
            'colori' => collect(self::COLORI)->map(fn ($c, $k) => ['valore' => $k, 'nome' => $c['nome'], 'campione' => $c['campione']])->values(),
            'angoli' => collect(self::ANGOLI)->map(fn ($a, $k) => ['valore' => $k, 'nome' => $a['nome']])->values(),
            // anteprima immediata: il CSS di ogni combinazione colore/angoli
            'anteprime' => collect(self::COLORI)->keys()->mapWithKeys(fn ($c) => [
                $c => collect(self::ANGOLI)->keys()->mapWithKeys(fn ($a) => [$a => self::cssPer($c, $a)]),
            ]),
        ];
    }
}
