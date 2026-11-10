<?php

use App\Providers\AgentiServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\AuditServiceProvider;
use App\Providers\BackupServiceProvider;
use App\Providers\MailConfigServiceProvider;
use App\Providers\UpgradePatchServiceProvider;

return [
    AppServiceProvider::class,
    AgentiServiceProvider::class,
    AuditServiceProvider::class,
    BackupServiceProvider::class,
    MailConfigServiceProvider::class,
    UpgradePatchServiceProvider::class,
];
