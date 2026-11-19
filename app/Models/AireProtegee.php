<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Aire protégée du catalogue APA-INPN : un zonage juridique (à la
 * différence d'une ZNIEFF, inventaire scientifique).
 *
 * @property string $id ex. FR3800583
 */
class AireProtegee extends Model
{
    protected $primaryKey = 'id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id', 'type_code', 'type_nom', 'nom',
        'superficie_ha', 'superficie_ref_ha', 'acte', 'gestionnaire',
        'objectif_protection', 'doc_gestion', 'date_crea_sign', 'date_modif_geo',
        'territoire', 'bbox_min_lat', 'bbox_max_lat', 'bbox_min_lng', 'bbox_max_lng',
        'url_inpn',
    ];

    protected function casts(): array
    {
        return [
            'superficie_ha' => 'float',
            'superficie_ref_ha' => 'float',
            'doc_gestion' => 'boolean',
            'date_crea_sign' => 'date',
            'date_modif_geo' => 'date',
        ];
    }

    public function communes(): BelongsToMany
    {
        return $this->belongsToMany(Commune::class, 'ap_communes', 'ap_id', 'insee')
            ->withPivot(['surface_ha', 'part_zonage', 'part_commune']);
    }
}
