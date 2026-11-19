<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Journal des imports : source, version datée, volume — affiché en pied de
 * page et sur la page Données & méthodologie.
 */
class ReferentielImport extends Model
{
    protected $fillable = ['source', 'version_source', 'nb_entites', 'importe_le'];

    protected function casts(): array
    {
        return [
            'nb_entites' => 'integer',
            'importe_le' => 'datetime',
        ];
    }
}
