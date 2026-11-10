<?php

namespace App\Providers;

use App\Models\Anagrafica;
use App\Models\Commento;
use App\Models\Comunicazione;
use App\Models\Segnalazione;
use App\Observers\Agenti\SegnalazioneCollegataObserver;
use Illuminate\Support\ServiceProvider;

/** Agenti AI e sistemi esterni (Web Pro Italia): quello che segue segnalazioni, bacheca e anagrafiche. Vedi l'observer. */
class AgentiServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Segnalazione::observe(SegnalazioneCollegataObserver::class);
        Commento::created(fn (Commento $commento) => app(SegnalazioneCollegataObserver::class)->commentoCreato($commento));
        Comunicazione::saved(fn (Comunicazione $c) => app(SegnalazioneCollegataObserver::class)->comunicazioneSalvata($c));
        Comunicazione::deleted(fn (Comunicazione $c) => app(SegnalazioneCollegataObserver::class)->comunicazioneEliminata($c));
        Anagrafica::saved(fn () => app(SegnalazioneCollegataObserver::class)->rubricaCambiata());
        Anagrafica::deleted(fn () => app(SegnalazioneCollegataObserver::class)->rubricaCambiata());
    }
}
