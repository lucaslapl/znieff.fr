<?php

namespace App\Http\Controllers;

use App\Models\Commune;
use App\Support\Chapeaux;
use App\Support\Croisements;
use Inertia\Inertia;
use Inertia\Response;

class CommuneController extends Controller
{
    /**
     * Fiche d'une commune — toutes les communes de France en ont une,
     * y compris sans aucun zonage (leur fiche le dit explicitement,
     * jamais de page vide). Aucun calcul spatial : pivots précalculés.
     */
    public function show(string $slug): Response
    {
        $commune = Commune::query()->where('slug', $slug)->firstOrFail();

        $zonages = Croisements::zonagesCommune($commune->insee);
        $natura = Croisements::naturaCommune($commune->insee);
        $aires = Croisements::airesCommune($commune->insee);
        $voisines = $commune->voisinesAvecDistance();

        $url = route('commune.show', $commune->slug);
        $meta = sprintf(
            'Commune de %s (%s) : %d ZNIEFF, %d site(s) Natura 2000, %d aire(s) protégée(s) recensé(s) par l\'INPN sur le territoire communal.',
            $commune->nom,
            $commune->dept,
            count($zonages),
            count($natura),
            count($aires),
        );

        return Inertia::render('Communes/Show', [
            'meta' => ['title' => sprintf('Patrimoine naturel à %s (%s)', $commune->nom, $commune->dept), 'description' => Chapeaux::meta($meta), 'canonical' => $url],
            'commune' => [
                'insee' => $commune->insee,
                'slug' => $commune->slug,
                'nom' => $commune->nom,
                'dept' => $commune->dept,
                'dept_nom' => $commune->dept_nom,
                'reg_nom' => $commune->reg_nom,
                'population' => $commune->population !== null ? Chapeaux::nombre($commune->population, 0) : null,
                'superficie_ha' => Chapeaux::ha($commune->superficie_ha),
                'codes_postaux' => $commune->codes_postaux,
                'has_abc' => (bool) $commune->has_abc,
                'geojson' => '/geo/communes/'.$commune->insee.'.geojson',
            ],
            'zonages' => array_map(fn (array $z) => [
                'id' => $z['id'],
                'famille' => $z['famille'],
                'badge' => $z['famille'] === 'z1' ? 'znieff1' : 'znieff2',
                'nom' => $z['nom'],
                'superficie_ha' => Chapeaux::ha($z['superficie_ha']),
                'surface_ha' => Chapeaux::ha($z['surface_ha']),
                'part_commune' => Chapeaux::pct($z['part_commune']),
            ], $zonages),
            'natura' => array_map(fn (array $s) => [
                'code' => $s['code'],
                'type' => strtoupper($s['type']),
                'nom' => $s['nom'],
                'superficie_ha' => Chapeaux::ha($s['superficie_ha']),
                'surface_ha' => Chapeaux::ha($s['surface_ha']),
                'part_commune' => Chapeaux::pct($s['part_commune']),
            ], $natura),
            'aires' => array_map(fn (array $a) => [
                'id' => $a['id'],
                'type_nom' => $a['type_nom'] ?? $a['type_code'],
                'nom' => $a['nom'],
                'superficie_ha' => Chapeaux::ha($a['superficie_ha']),
                'surface_ha' => Chapeaux::ha($a['surface_ha']),
                'part_commune' => Chapeaux::pct($a['part_commune']),
            ], $aires),
            'voisines' => $voisines->map(fn (array $v) => [
                'slug' => (string) $v['commune']->slug,
                'nom' => (string) $v['commune']->nom,
                'distance_m' => (int) $v['distance_m'],
            ])->all(),
            'nbZonages' => count($zonages),
            'nbNatura' => count($natura),
            'nbAires' => count($aires),
        ]);
    }
}
