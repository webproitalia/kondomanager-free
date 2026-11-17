<?php

namespace App\Http\Controllers\Gestionale\Assemblee;

use App\Http\Resources\Condominio\CondominioResource;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\AssembleaPresenza;
use App\Models\Assemblee\AssembleaPunto;
use App\Models\Condominio;

/**
 * Le guardie e i dati comuni delle pagine delle assemblee (Web Pro Italia, 17/11/2026).
 *
 * Le rotte hanno già il vincolo sul condominio (`scopeBindings()`); queste guardie sono la difesa in profondità che
 * `RotteAnnidateSenzaGuardiaTest` chiede a ogni metodo dietro una rotta con un figlio: un'assemblea, un punto o una
 * presenza che non sono di questo condominio rispondono 404.
 */
trait GuardieAssemblea
{
    protected function assicuraAssemblea(Condominio $condominio, Assemblea $assemblea): void
    {
        abort_unless((int) $assemblea->condominio_id === (int) $condominio->id, 404);
    }

    protected function assicuraPunto(Condominio $condominio, Assemblea $assemblea, AssembleaPunto $punto): void
    {
        $this->assicuraAssemblea($condominio, $assemblea);
        abort_unless((int) $punto->assemblea_id === (int) $assemblea->id, 404);
    }

    protected function assicuraPresenza(Condominio $condominio, Assemblea $assemblea, AssembleaPresenza $presenza): void
    {
        $this->assicuraAssemblea($condominio, $assemblea);
        abort_unless((int) $presenza->assemblea_id === (int) $assemblea->id, 404);
    }

    /** Quello che ogni pagina del gestionale passa alla testata: il condominio, l'elenco dei condomini, l'esercizio aperto. */
    protected function datiDellaTestata(Condominio $condominio): array
    {
        return [
            'condominio' => $condominio,
            'condomini' => CondominioResource::collection($this->getCondomini())->resolve(),
            'esercizio' => $this->getEsercizioCorrente($condominio),
        ];
    }
}
