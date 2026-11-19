<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Pivot ZNIEFF × commune : une ligne = un croisement précalculé
 * (surface d'intersection et parts dans les deux sens).
 *
 * @property string $zonage_id
 * @property string $insee
 * @property float $surface_ha
 * @property float $part_zonage
 * @property float $part_commune
 */
class ZonageCommune extends Pivot
{
    protected $table = 'zonage_communes';

    protected $primaryKey = null;

    public $incrementing = false;

    protected $fillable = ['zonage_id', 'insee', 'surface_ha', 'part_zonage', 'part_commune'];

    protected function casts(): array
    {
        return [
            'surface_ha' => 'float',
            'part_zonage' => 'float',
            'part_commune' => 'float',
        ];
    }
}
