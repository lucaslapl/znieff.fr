<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Pivot aire protégée × commune (croisement précalculé).
 *
 * @property string $ap_id
 * @property string $insee
 * @property float $surface_ha
 * @property float $part_zonage
 * @property float $part_commune
 */
class ApCommune extends Pivot
{
    protected $table = 'ap_communes';

    protected $primaryKey = null;

    public $incrementing = false;

    protected $fillable = ['ap_id', 'insee', 'surface_ha', 'part_zonage', 'part_commune'];

    protected function casts(): array
    {
        return [
            'surface_ha' => 'float',
            'part_zonage' => 'float',
            'part_commune' => 'float',
        ];
    }
}
