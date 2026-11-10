<?php

namespace App\Providers;

use App\Models\Commento;
use App\Models\Segnalazione;
use App\Observers\Agenti\SegnalazioneCollegataObserver;
use Illuminate\Support\ServiceProvider;

/** Agenti AI e sistemi esterni (Web Pro Italia): quello che segue la vita delle segnalazioni. Vedi l'observer. */
class AgentiServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Segnalazione::observe(SegnalazioneCollegataObserver::class);
        Commento::created(fn (Commento $commento) => app(SegnalazioneCollegataObserver::class)->commentoCreato($commento));
    }
}
