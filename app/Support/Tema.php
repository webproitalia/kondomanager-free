<?php

namespace App\Support;

use App\Settings\AspettoSettings;

/**
 * Palette dell'aspetto (Web Pro Italia, 09/11/2026). Ogni colore ridefinisce solo le variabili di
 * resources/css/app.css (primario, anello del focus, barra laterale, sfondo dei passaggi del mouse),
 * in chiaro e in scuro. «grafite» è l'aspetto originale di Kondomanager e non scrive niente.
 * I primari sono scuri abbastanza da tenere leggibile il testo bianco sui pulsanti.
 *
 * Dal 18/11/2026 il colore dà anche la tinta del menu laterale (`--km-tinta`, `--km-menu-*`): il fondo del menu è una versione
 * molto scura del colore, la voce attiva lo usa pieno. Le variabili e i loro valori di «grafite» stanno in resources/css/menu-laterale.css.
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
        // Il viola spento dei gestionali da ufficio: il colore principale di Odoo 16 Community (#71639e).
        'glicine'  => ['nome' => 'Glicine', 'campione' => '#71639e',
            'chiaro' => ['p' => '254 23.3% 50.4%', 'a' => '254 30% 95%', 'af' => '254 25% 34%'],
            'scuro'  => ['p' => '254 36% 72%', 'pf' => '254 40% 10%', 'a' => '254 20% 20%', 'af' => '254 40% 90%']],
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

    /** Dove sta il menu: l'impalcatura a menu laterale (predefinita) o la testata di sempre. */
    public const MENU = [
        // Dal 21/11/2026, predefinita: la barra dello studio in alto, sempre; il menu laterale solo dentro un condominio.
        'barra'    => ['nome' => 'Barra in alto'],
        'laterale' => ['nome' => 'Laterale'],
        'alto'     => ['nome' => 'In alto, com\'era prima'],
    ];

    /**
     * Dove si aprono la scheda di una persona, di un'unità e i moduli: in una scheda che entra da destra, sopra la pagina in cui
     * si è (predefinito), o a pagina intera come sempre. La scheda laterale c'è solo con il menu laterale.
     */
    public const SCHEDE = [
        'lato'   => ['nome' => 'Di lato'],
        'pagina' => ['nome' => 'A pagina intera'],
    ];

    /**
     * Lo stile dell'area di lavoro: «professionale» (superfici piane, righe sottili, angoli piccoli, il carattere del sistema:
     * predefinito) o «morbido» (quello di prima). Lo stile professionale veste l'impalcatura a menu laterale: con il menu in
     * alto resta quello di prima. Le regole stanno in resources/css/stile-professionale.css.
     */
    public const STILE = [
        'professionale' => ['nome' => 'Professionale'],
        'morbido'       => ['nome' => 'Morbido'],
    ];

    /** Lo stile salvato (per il layout): «morbido» con il menu in alto, e «professionale» anche prima della migrazione delle impostazioni. */
    public static function stile(): string
    {
        if (self::menu() === 'alto') {
            return 'morbido';
        }
        try {
            $stile = app(AspettoSettings::class)->stile;
        } catch (\Throwable) {
            return 'professionale';
        }

        return isset(self::STILE[$stile]) ? $stile : 'professionale';
    }

    /** Le schede salvate (per il layout): «pagina» con il menu in alto, e «lato» anche prima della migrazione delle impostazioni. */
    public static function schede(): string
    {
        if (self::menu() === 'alto') {
            return 'pagina';
        }
        try {
            $schede = app(AspettoSettings::class)->schede;
        } catch (\Throwable) {
            return 'lato';
        }

        return isset(self::SCHEDE[$schede]) ? $schede : 'lato';
    }

    /** Il menu salvato (per il layout): «laterale» anche prima della migrazione delle impostazioni, o a database spento. */
    public static function menu(): string
    {
        try {
            $menu = app(AspettoSettings::class)->menu;
        } catch (\Throwable) {
            return 'barra';
        }

        return isset(self::MENU[$menu]) ? $menu : 'barra';
    }

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

        $chiaro = self::variabili($colore['chiaro'], '0 0% 98%').self::variabiliMenu($colore['chiaro']);
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

    /**
     * Il menu laterale prende la tinta del colore dello studio: il fondo è il colore molto scuro, la voce attiva il colore pieno
     * (quello dei pulsanti in chiaro, anche nel tema scuro: il menu è scuro in tutti e due). «grafite» non scrive niente.
     *
     * @param array<string,string> $v
     */
    private static function variabiliMenu(array $v): string
    {
        if (! isset($v['p'])) {
            return '';
        }
        $tinta = (int) strtok($v['p'], ' ');

        return "--km-tinta:{$tinta};--km-menu-sat:42%;--km-menu-attiva:hsl({$v['p']});";
    }

    /** Per la pagina delle impostazioni: chiave, nome e campione di ogni scelta. */
    public static function scelte(): array
    {
        return [
            'colori' => collect(self::COLORI)->map(fn ($c, $k) => ['valore' => $k, 'nome' => $c['nome'], 'campione' => $c['campione']])->values(),
            'angoli' => collect(self::ANGOLI)->map(fn ($a, $k) => ['valore' => $k, 'nome' => $a['nome']])->values(),
            'menu' => collect(self::MENU)->map(fn ($m, $k) => ['valore' => $k, 'nome' => $m['nome']])->values(),
            'schede' => collect(self::SCHEDE)->map(fn ($m, $k) => ['valore' => $k, 'nome' => $m['nome']])->values(),
            'stile' => collect(self::STILE)->map(fn ($m, $k) => ['valore' => $k, 'nome' => $m['nome']])->values(),
            // anteprima immediata: il CSS di ogni combinazione colore/angoli
            'anteprime' => collect(self::COLORI)->keys()->mapWithKeys(fn ($c) => [
                $c => collect(self::ANGOLI)->keys()->mapWithKeys(fn ($a) => [$a => self::cssPer($c, $a)]),
            ]),
        ];
    }
}
