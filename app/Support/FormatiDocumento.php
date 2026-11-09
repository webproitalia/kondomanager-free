<?php

namespace App\Support;

/**
 * I formati che l'archivio documenti accetta (Web Pro Italia, 09/11/2026). Prima solo PDF: un verbale fotografato,
 * un preventivo in Word, un Excel di riparto o una mail salvata andavano convertiti a mano. Il controllo resta sul
 * contenuto vero del file (`mimes`, non l'estensione), e restano fuori i formati che un browser eseguirebbe (HTML,
 * SVG, script): i documenti si scaricano come allegato, non si aprono nella pagina.
 */
class FormatiDocumento
{
    public const ESTENSIONI = [
        'pdf', 'jpg', 'jpeg', 'png', 'webp', 'heic', 'tif', 'tiff',
        'doc', 'docx', 'odt', 'rtf', 'txt',
        'xls', 'xlsx', 'ods', 'csv',
        'xml', 'p7m', 'zip', 'eml', 'msg',
    ];

    public static function regola(): string
    {
        return 'mimes:'.implode(',', self::ESTENSIONI);
    }
}
