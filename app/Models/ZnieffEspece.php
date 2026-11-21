<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Espèce citée par une ZNIEFF (REF_ESPECE.csv INPN).
 *
 * @property int $id
 * @property string $zonage_id NM_SFFZN → zonages.id
 * @property int $cd_ref TAXREF
 * @property string $nom_cite nom cité (peut contenir de l'italique HTML INPN)
 * @property string|null $groupe_taxo
 * @property bool $determinante FG_ESP = D
 * @property bool $confidentielle FG_CONF=true ou FG_ESP=C — JAMAIS affichée :
 *                                toutes les lectures passent par App\Support\Especes
 *                                qui filtre `confidentielle = false`
 */
class ZnieffEspece extends Model
{
    protected $table = 'znieff_especes'; // Eloquent ne pluralise pas bien

    protected $fillable = [
        'zonage_id', 'cd_ref', 'nom_cite', 'groupe_taxo',
        'determinante', 'confidentielle',
    ];

    protected function casts(): array
    {
        return [
            'cd_ref' => 'int',
            'determinante' => 'bool',
            'confidentielle' => 'bool',
        ];
    }

    public function zonage(): BelongsTo
    {
        return $this->belongsTo(Zonage::class, 'zonage_id', 'id');
    }
}
