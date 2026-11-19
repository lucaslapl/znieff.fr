<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Paire de communes voisines (≤ 15 km, centroïdes), précalculée au
 * rafraîchissement des référentiels. Normalisée : insee_a < insee_b.
 *
 * @property string $insee_a
 * @property string $insee_b
 * @property int $distance_m
 */
class CommuneCommune extends Pivot
{
    protected $table = 'commune_communes';

    protected $primaryKey = null;

    public $incrementing = false;

    protected $fillable = ['insee_a', 'insee_b', 'distance_m'];

    protected function casts(): array
    {
        return [
            'distance_m' => 'integer',
        ];
    }
}
