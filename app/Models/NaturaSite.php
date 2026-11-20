<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * Site Natura 2000 (SIC/ZSC ou ZPS), référentiel INPN.
 *
 * @property string $code ex. FR8212001
 * @property-read int $nb_communes compteur withCount de la liste (pivot)
 * @property Carbon|null $date_designation
 * @property Carbon|null $date_modif_geo
 */
class NaturaSite extends Model
{
    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'code', 'type', 'nom', 'superficie_ha', 'superficie_ref_ha',
        'precision_saisie', 'date_designation', 'date_modif_geo', 'territoire',
        'bbox_min_lat', 'bbox_max_lat', 'bbox_min_lng', 'bbox_max_lng', 'url_inpn',
    ];

    protected function casts(): array
    {
        return [
            'superficie_ha' => 'float',
            'superficie_ref_ha' => 'float',
            'date_designation' => 'date',
            'date_modif_geo' => 'date',
        ];
    }

    public function communes(): BelongsToMany
    {
        return $this->belongsToMany(Commune::class, 'natura_communes', 'site_code', 'insee')
            ->withPivot(['surface_ha', 'part_zonage', 'part_commune']);
    }
}
