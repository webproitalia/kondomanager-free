<?php

use App\Http\Controllers\Agenti\CatalogoAgentiController;
use App\Http\Controllers\Agenti\SegnalazioniEsterneController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Agenti AI (Web Pro Italia, 09/11/2026)
|--------------------------------------------------------------------------
| Il catalogo delle funzioni che un agente può chiamare con la sua chiave (vedi AutenticaAgente). Le chiavi si
| gestiscono in Impostazioni → Agenti AI (routes/settings.php), che agli agenti è vietata.
*/
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('agenti/catalogo', CatalogoAgentiController::class)->name('agenti.catalogo');
});

/*
| Sistemi esterni collegati (10/11/2026): un'app o un programma che tiene allineate le sue segnalazioni con il
| gestionale, con una chiave di agente «Consultare e modificare». Vedi App\Services\Agenti\SegnalazioniEsterne.
*/
Route::middleware(['auth', 'verified', 'role_or_permission:amministratore|collaboratore|Accesso pannello amministratore'])
    ->prefix('agenti/esterni')->name('agenti.esterni.')->group(function () {
        Route::get('condomini', [SegnalazioniEsterneController::class, 'condomini'])->name('condomini');
        Route::get('condomini/{condominio}/persone', [SegnalazioniEsterneController::class, 'persone'])->whereNumber('condominio')->name('persone');
        Route::put('{sorgente}/comunicazioni/{riferimento}', [SegnalazioniEsterneController::class, 'comunicazione'])
            ->where(['sorgente' => '[a-z0-9_-]{2,50}', 'riferimento' => '[A-Za-z0-9_.:-]{1,100}'])
            ->name('comunicazione');
        Route::put('{sorgente}/segnalazioni/{riferimento}', [SegnalazioniEsterneController::class, 'sincronizza'])
            ->where(['sorgente' => '[a-z0-9_-]{2,50}', 'riferimento' => '[A-Za-z0-9_.:-]{1,100}'])
            ->name('segnalazione');
    });
