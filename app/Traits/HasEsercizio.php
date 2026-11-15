<?php

namespace App\Traits;

use App\Models\Condominio;


/**
 * Trait for retrieving current esercizio (fiscal period) for condominio
 * 
 * Provides a standardized method to get the currently active and open
 * esercizio (fiscal period) for a given condominio. This is essential
 * for financial operations and period-based data management.
 *
 * @package App\Traits
 */
trait HasEsercizio
{
    /**
     * Get the current open esercizio for the condominio
     *
     * Retrieves the active fiscal period (esercizio) that is currently
     * in 'aperto' (open) state for the specified condominio. This is
     * typically used for financial transactions, reporting, and
     * period-based operations.
     *
     * @param \App\Models\Condominio $condominio The condominio to get the esercizio for
     * @return \App\Models\Esercizio|null The open esercizio or null if none found
     *
     * @example
     * // Usage in controller
     * $esercizio = $this->getEsercizioCorrente($condominio);
     * 
     * if ($esercizio) {
     *     // Use the open esercizio for financial operations
     *     $transactions = $esercizio->transazioni()->get();
     * } else {
     *     // Handle case where no esercizio is open
     *     return back()->with($this->flashError('No open fiscal period'));
     * }
     *
     * @see \App\Models\Esercizio
     * @see \App\Models\Condominio::esercizi()
     */
    protected function getEsercizioCorrente(Condominio $condominio)
    {
        // ⚠️ **Con un ordine esplicito: il primo aperto** (Web Pro Italia, 15/11/2026). La regola è «al più un
        // esercizio aperto», ma l'importazione fino a oggi ne apriva un secondo da sola, e con due aperti il solo
        // `first()` prendeva quello che il database restituiva per primo — su MySQL, probabilmente, nell'ordine
        // dell'indice (condominio, nome) — mentre l'importazione prendeva quello che inizia più tardi. Ora è il
        // primo aperto per id, cioè quello aperto da più tempo: è ciò che il Wallet e le sue prove davano già per
        // inteso («il primo con lo stato aperto»), e `ImportVerificaService::esercizioApertoDi()` fa lo stesso.
        // Con un esercizio aperto solo non cambia niente.
        return $condominio->esercizi()
            ->where('stato', 'aperto')
            ->orderBy('id')
            ->first();
    }
}