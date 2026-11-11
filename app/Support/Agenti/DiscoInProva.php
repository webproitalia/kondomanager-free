<?php

namespace App\Support\Agenti;

use Illuminate\Filesystem\FilesystemAdapter;

/**
 * Un disco dei file durante l'ANTEPRIMA di un agente (Web Pro Italia, 11/11/2026).
 *
 * L'anteprima esegue la scrittura dentro una transazione che poi si annulla: il database torna com'era. I file no:
 * `ArchivioDocumenti::elimina()` e `ImportUploadService::eliminaFile()` cancellano subito dal disco, e così
 * «eliminare un documento» o «sostituire il file» in anteprima toglieva il file VERO e lasciava la riga, tornata al suo
 * posto, a puntare al nulla. Trovato leggendo il codice per il dizionario degli agenti, non da un cliente.
 *
 * Qui dentro, per la durata della richiesta in anteprima: non si cancella, non si sposta (si copia) e non si
 * sovrascrive un file che esiste già. I file NUOVI caricati durante un'anteprima si scrivono davvero (servono alla
 * richiesta stessa) e possono restare sul disco: è il limite già dichiarato in docs/agenti_ai.it.md.
 */
class DiscoInProva extends FilesystemAdapter
{
    public function delete($paths)
    {
        return true;
    }

    public function deleteDirectory($directory)
    {
        return true;
    }

    public function move($from, $to)
    {
        return $this->exists($to) ? true : $this->copy($from, $to);
    }

    public function put($path, $contents, $options = [])
    {
        return $this->exists($path) ? true : parent::put($path, $contents, $options);
    }

    public function writeStream($path, $resource, array $options = [])
    {
        return $this->exists($path) ? true : parent::writeStream($path, $resource, $options);
    }
}
