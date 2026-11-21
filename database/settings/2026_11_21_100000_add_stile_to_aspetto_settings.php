<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Lo stile dell'area di lavoro (Web Pro Italia, 21/11/2026): «professionale» — superfici piane, una riga sola e sottile, angoli
 * piccoli, il carattere del sistema — o «morbido», quello di prima. Predefinito «professionale»; si cambia in
 * Impostazioni → Aspetto, e vale solo con il menu laterale.
 *
 * Rieseguibile: `add()` lancia `SettingAlreadyExists` se la proprietà c'è già; una scelta già scritta non si tocca.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('aspetto.stile')) {
            $this->migrator->add('aspetto.stile', 'professionale');
        }
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('aspetto.stile');
    }
};
