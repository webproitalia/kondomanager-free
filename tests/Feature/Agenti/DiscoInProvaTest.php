<?php

use App\Support\Agenti\DiscoInProva;
use Illuminate\Support\Facades\Storage;

/*
 * L'anteprima di un agente annulla il database ma non può annullare il disco: per questo, durante l'anteprima, i
 * dischi vengono sostituiti da `DiscoInProva` (vedi AutenticaAgente::dischiInProva). Web Pro Italia, 11/11/2026.
 */

function discoInProva(): DiscoInProva
{
    $vero = Storage::fake('local');

    return new DiscoInProva($vero->getDriver(), $vero->getAdapter(), $vero->getConfig());
}

it('in anteprima un file che esiste non viene cancellato', function () {
    $prova = discoInProva();
    Storage::disk('local')->put('documenti/verbale.pdf', 'contenuto vero');

    expect($prova->delete('documenti/verbale.pdf'))->toBeTrue()
        ->and($prova->deleteDirectory('documenti'))->toBeTrue()
        ->and(Storage::disk('local')->get('documenti/verbale.pdf'))->toBe('contenuto vero');
});

it('in anteprima un file che esiste non viene sovrascritto né spostato', function () {
    $prova = discoInProva();
    Storage::disk('local')->put('documenti/verbale.pdf', 'contenuto vero');

    $prova->put('documenti/verbale.pdf', 'contenuto di prova');
    $prova->move('documenti/verbale.pdf', 'documenti/altrove.pdf');

    expect(Storage::disk('local')->get('documenti/verbale.pdf'))->toBe('contenuto vero')
        ->and(Storage::disk('local')->exists('documenti/altrove.pdf'))->toBeTrue();
});

it('in anteprima un file nuovo si scrive, perché la richiesta stessa può doverlo leggere', function () {
    $prova = discoInProva();

    $prova->put('import/lotto/unita.xls', 'righe');

    expect(Storage::disk('local')->get('import/lotto/unita.xls'))->toBe('righe');
});
