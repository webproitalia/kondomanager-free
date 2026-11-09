<?php

use App\Http\Controllers\Agenti\CatalogoAgentiController;
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
