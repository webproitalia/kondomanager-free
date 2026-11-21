<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Dove sta il menu (Web Pro Italia, 18/11/2026): «laterale», l'impalcatura a menu laterale, o «alto», la testata di sempre.
 * Predefinito «laterale»; si cambia in Impostazioni → Aspetto.
 *
 * Rieseguibile: `add()` lancia `SettingAlreadyExists` se la proprietà c'è già; una scelta già scritta non si tocca.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('aspetto.menu')) {
            // Dal 21/11/2026 un'installazione nuova nasce con «barra» (la barra dello studio in alto); chi ha già la riga la tiene.
            $this->migrator->add('aspetto.menu', 'barra');
        }
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('aspetto.menu');
    }
};
