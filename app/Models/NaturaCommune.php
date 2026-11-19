<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Pivot Natura 2000 × commune (croisement précalculé).
 *
 * @property string $site_code
 * @property string $insee
 * @property float $surface_ha
 * @property float $part_zonage
 * @property float $part_commune
 */
class NaturaCommune extends Pivot
{
    protected $table = 'natura_communes';

    protected $primaryKey = null;

    public $incrementing = false;

    protected $fillable = ['site_code', 'insee', 'surface_ha', 'part_zonage', 'part_commune'];

    protected function casts(): array
    {
        return [
            'surface_ha' => 'float',
            'part_zonage' => 'float',
            'part_commune' => 'float',
        ];
    }
}
