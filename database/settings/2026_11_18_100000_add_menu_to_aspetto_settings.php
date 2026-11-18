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
            $this->migrator->add('aspetto.menu', 'laterale');
        }
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('aspetto.menu');
    }
};
