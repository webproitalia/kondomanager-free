<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Dove si aprono la scheda di una persona, di un'unità e i moduli (Web Pro Italia, 18/11/2026): «lato», in una scheda che entra
 * da destra sopra la pagina in cui si è, o «pagina», a pagina intera come sempre. Predefinito «lato»; si cambia in
 * Impostazioni → Aspetto, e vale solo con il menu laterale.
 *
 * Rieseguibile: `add()` lancia `SettingAlreadyExists` se la proprietà c'è già; una scelta già scritta non si tocca.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('aspetto.schede')) {
            $this->migrator->add('aspetto.schede', 'lato');
        }
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('aspetto.schede');
    }
};
