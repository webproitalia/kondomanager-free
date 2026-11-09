<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('aspetto.colore')) {
            $this->migrator->add('aspetto.colore', 'grafite');
        }
        if (! $this->migrator->exists('aspetto.angoli')) {
            $this->migrator->add('aspetto.angoli', 'standard');
        }
    }
};
