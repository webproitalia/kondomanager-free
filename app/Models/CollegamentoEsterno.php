<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Il legame fra un oggetto di un sistema esterno e uno del gestionale. Vedi la migrazione `create_collegamenti_esterni_table`. */
class CollegamentoEsterno extends Model
{
    protected $table = 'collegamenti_esterni';

    protected $fillable = ['sorgente', 'riferimento', 'collegabile_type', 'collegabile_id', 'dati', 'eliminato_at'];

    protected $casts = ['dati' => 'array', 'eliminato_at' => 'datetime'];

    public static function trova(string $sorgente, string $tipo, string $riferimento): ?self
    {
        return self::where('sorgente', $sorgente)->where('collegabile_type', $tipo)->where('riferimento', $riferimento)->first();
    }

    /** I collegamenti attivi di un oggetto del gestionale. */
    public static function di(Model $oggetto)
    {
        return self::where('collegabile_type', $oggetto->getMorphClass())->where('collegabile_id', $oggetto->getKey())->whereNull('eliminato_at')->get();
    }
}
