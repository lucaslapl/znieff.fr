<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Zonage ZNIEFF (type I ou II) du référentiel INPN.
 *
 * @property string $id identifiant national (NM_SFFZN), ex. 230009093
 * @property string $famille z1 | z2
 */
class Zonage extends Model
{
    protected $primaryKey = 'id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id', 'num_regional', 'famille', 'id_parent', 'nom',
        'superficie_ha', 'superficie_ref_ha', 'precision_saisie',
        'date_crea', 'date_modif_geo', 'territoire', 'volet',
        'bbox_min_lat', 'bbox_max_lat', 'bbox_min_lng', 'bbox_max_lng',
        'url_inpn',
    ];

    protected function casts(): array
    {
        return [
            'superficie_ha' => 'float',
            'superficie_ref_ha' => 'float',
            'date_crea' => 'date',
            'date_modif_geo' => 'date',
            'bbox_min_lat' => 'float',
            'bbox_max_lat' => 'float',
            'bbox_min_lng' => 'float',
            'bbox_max_lng' => 'float',
        ];
    }

    /** ZNIEFF II de rattachement (pour une ZNIEFF I). */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'id_parent');
    }

    /** ZNIEFF I rattachées (pour une ZNIEFF II). */
    public function enfants(): HasMany
    {
        return $this->hasMany(self::class, 'id_parent');
    }

    /** Communes intersectées, avec surfaces et parts précalculées. */
    public function communes(): BelongsToMany
    {
        return $this->belongsToMany(Commune::class, 'zonage_communes', 'zonage_id', 'insee')
            ->withPivot(['surface_ha', 'part_zonage', 'part_commune']);
    }

    /** Classe CSS du badge (charte herbier). */
    public function badgeClasse(): string
    {
        return $this->famille === 'z1' ? 'znieff1' : 'znieff2';
    }
}
