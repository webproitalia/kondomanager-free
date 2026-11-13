<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/** La copertina del fascicolo di bilancio: il nome dello studio, la riga sopra il titolo e la foto (Web Pro Italia, 13/11/2026). */
return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('print.fascicolo_studio')) {
            $this->migrator->add('print.fascicolo_studio', null);
        }
        if (! $this->migrator->exists('print.fascicolo_sopratitolo')) {
            $this->migrator->add('print.fascicolo_sopratitolo', 'Amministrazione condominiale');
        }
        if (! $this->migrator->exists('print.fascicolo_copertina_path')) {
            $this->migrator->add('print.fascicolo_copertina_path', null);
        }
    }
};
