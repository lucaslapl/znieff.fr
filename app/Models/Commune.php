<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;

/**
 * Commune du référentiel AdminExpress (COG). Aucune géométrie stockée :
 * centroïde, superficie calculée et GeoJSON précalculé (public/geo/).
 *
 * @property string $insee
 * @property string $slug
 * @property-read int $nb_zonages compteur withCount de la liste (pivots)
 * @property-read int $nb_natura
 * @property-read int $nb_ap
 */
class Commune extends Model
{
    protected $primaryKey = 'insee';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'insee', 'slug', 'nom', 'dept', 'dept_nom', 'reg_code', 'reg_nom',
        'population', 'superficie_ha', 'superficie_calc_ha',
        'lat', 'lng', 'codes_postaux', 'has_abc',
    ];

    protected function casts(): array
    {
        return [
            'population' => 'integer',
            'superficie_ha' => 'float',
            'superficie_calc_ha' => 'float',
            'lat' => 'float',
            'lng' => 'float',
            'has_abc' => 'boolean',
        ];
    }

    /** Zonages ZNIEFF touchant la commune (avec surfaces précalculées). */
    public function zonages(): BelongsToMany
    {
        return $this->belongsToMany(Zonage::class, 'zonage_communes', 'insee', 'zonage_id')
            ->withPivot(['surface_ha', 'part_zonage', 'part_commune']);
    }

    /** Sites Natura 2000 touchant la commune. */
    public function naturaSites(): BelongsToMany
    {
        return $this->belongsToMany(NaturaSite::class, 'natura_communes', 'insee', 'site_code')
            ->withPivot(['surface_ha', 'part_zonage', 'part_commune']);
    }

    /** Aires protégées touchant la commune. */
    public function airesProtegees(): BelongsToMany
    {
        return $this->belongsToMany(AireProtegee::class, 'ap_communes', 'insee', 'ap_id')
            ->withPivot(['surface_ha', 'part_zonage', 'part_commune']);
    }

    /**
     * Communes voisines dans un rayon de 15 km (table précalculée
     * `commune_communes`, paires ordonnées) : les deux sens de la paire.
     *
     * @return Collection<int, array{commune: self, distance_m: int}>
     */
    public function voisinesAvecDistance(): Collection
    {
        $paires = CommuneCommune::query()
            ->where(fn (Builder $q) => $q->where('insee_a', $this->insee)->orWhere('insee_b', $this->insee))
            ->orderBy('distance_m')
            ->get();

        $liens = $paires->map(fn (CommuneCommune $p) => [
            'insee' => $p->insee_a === $this->insee ? $p->insee_b : $p->insee_a,
            'distance_m' => (int) $p->distance_m,
        ])->unique('insee');

        /** @var Collection<string, self> $communes */
        $communes = static::query()->whereIn('insee', $liens->pluck('insee')->all())->get()->keyBy->insee;

        return $liens
            ->sortBy('distance_m')
            ->map(fn (array $l) => ['commune' => $communes->get($l['insee']), 'distance_m' => $l['distance_m']])
            ->filter(fn (array $e) => $e['commune'] !== null)
            ->values();
    }

    /** Filtre de listing par département (ex. --dept=27). */
    public function scopeDuDepartement(Builder $query, string $dept): Builder
    {
        return $query->where('dept', $dept);
    }
}
