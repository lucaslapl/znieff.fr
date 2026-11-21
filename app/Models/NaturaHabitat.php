<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Habitat d'intérêt communautaire d'un site Natura 2000 (FSD habit1.csv.csv,
 * enrichi de dhff_habitats). `conservation` = structure & fonctions (A-C),
 * `evaluation_globale` = champ « global » du FSD (A-D).
 *
 * @property int $id
 * @property string $site_code → natura_sites.code
 * @property string $cd_ue code EUR annexe I, ex. 2110
 * @property string|null $nom
 * @property bool $prioritaire
 * @property string|null $cd_hab Corine Biotopes
 * @property string|null $representativite A-D
 * @property string|null $conservation A-C
 * @property string|null $evaluation_globale A-D
 */
class NaturaHabitat extends Model
{
    protected $table = 'natura_habitats';

    protected $fillable = [
        'site_code', 'cd_ue', 'nom', 'prioritaire', 'cd_hab', 'cover', 'area',
        'representativite', 'conservation', 'evaluation_globale',
    ];

    protected function casts(): array
    {
        return [
            'prioritaire' => 'bool',
            'cover' => 'float',
            'area' => 'float',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(NaturaSite::class, 'site_code', 'code');
    }
}
