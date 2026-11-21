<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Espèce d'un site Natura 2000 (formulaire standard de données INPN),
 * enrichie du référentiel de directive (annexe II/prioritaire ou annexe I
 * oiseaux). `presences` = statuts biologiques FSD fusionnés (c,r,w,p).
 *
 * @property int $id
 * @property string $site_code → natura_sites.code
 * @property string $code_n2000 ex. A140 (oiseaux) ou 1065 (habitats)
 * @property int|null $cd_ref
 * @property string|null $nom_scientifique
 * @property string|null $nom_vernaculaire
 * @property string|null $taxgroup
 * @property bool $annexe_ii directive Habitats
 * @property bool $annexe_1 directive Oiseaux
 * @property bool $prioritaire
 * @property string|null $presences
 */
class NaturaEspece extends Model
{
    protected $table = 'natura_especes';

    protected $fillable = [
        'site_code', 'code_n2000', 'cd_ref', 'nom_scientifique', 'nom_vernaculaire',
        'taxgroup', 'annexe_ii', 'annexe_1', 'prioritaire', 'presences',
    ];

    protected function casts(): array
    {
        return [
            'cd_ref' => 'int',
            'annexe_ii' => 'bool',
            'annexe_1' => 'bool',
            'prioritaire' => 'bool',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(NaturaSite::class, 'site_code', 'code');
    }
}
