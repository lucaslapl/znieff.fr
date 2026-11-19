<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Écrit les surcouches GeoJSON précalculées (public/geo), SERVIES
 * STATIQUEMENT côté site — jamais calculées à la volée. Génération en
 * local uniquement, lors de l'import/rafraîchissement annuel, à partir
 * des tables temporaires PostGIS :
 *
 *   public/geo/dept-{dept}/znieff-z1.geojson   ZNIEFF I du département
 *   public/geo/dept-{dept}/znieff-z2.geojson   ZNIEFF II
 *   public/geo/dept-{dept}/natura.geojson      sites Natura 2000
 *   public/geo/dept-{dept}/ap.geojson          aires protégées
 *   public/geo/dept-{dept}/communes.geojson    contours communaux
 *   public/geo/zonages/z1/{id}.geojson         fiche d'un zonage
 *   public/geo/zonages/z2/{id}.geojson
 *   public/geo/natura/{code}.geojson
 *   public/geo/ap/{id}.geojson
 *   public/geo/communes/{insee}.geojson        contour d'une commune (fiche)
 *   public/geo/manifest.json                   versions + inventaire
 *
 * Tolérances de simplification calibrées sur la précision de saisie
 * (config portail.geojson) : ZNIEFF II 55 m, ZNIEFF I 20 m, Natura 25 m,
 * AP ~5 m, communes 100 m. Zones < 1 ha : rendu par point (superficie
 * conservée en propriétés) — pas de perte visible au 1/25 000.
 */
class GeojsonExportService
{
    /** familles (clés de la maquette, FAMSTYLE) → table de staging */
    private const STAGING = [
        'z1' => 'portail_tmp_znieff',
        'z2' => 'portail_tmp_znieff',
        'nat' => 'portail_tmp_natura',
        'ap' => 'portail_tmp_apa',
    ];

    /** familles → [table pivot, colonne id] (adhésion aux départements) */
    private const PIVOTS = [
        'z1' => ['zonage_communes', 'zonage_id'],
        'z2' => ['zonage_communes', 'zonage_id'],
        'nat' => ['natura_communes', 'site_code'],
        'ap' => ['ap_communes', 'ap_id'],
    ];

    /** familles → nom de fichier départemental */
    private const FICHIER_DEPT = [
        'z1' => 'znieff-z1.geojson',
        'z2' => 'znieff-z2.geojson',
        'nat' => 'natura.geojson',
        'ap' => 'ap.geojson',
    ];

    /** familles → dossier des fichiers objet */
    private const DOSSIER_OBJET = [
        'z1' => 'zonages/z1',
        'z2' => 'zonages/z2',
        'nat' => 'natura',
        'ap' => 'ap',
    ];

    /**
     * Surcouche d'une famille pour un département : uniquement les zones
     * qui intersectent au moins une commune du département (via la table
     * pivot, codes INSEE — pas de calcul spatial ici).
     */
    public function departementZones(string $famille, string $dept): int
    {
        $this->familleValide($famille);
        [$pivot, $colonneId] = self::PIVOTS[$famille];
        $staging = self::STAGING[$famille];

        $tolerance = (float) config("portail.geojson.simplification.{$this->cleTolerance($famille)}");
        $seuilPoint = (float) config('portail.geojson.seuil_point_ha');
        $decimales = (int) config('portail.geojson.decimales');

        // Une requête par famille : les zones du dept, chaque géométrie
        // simplifiée à sa tolérance, les petites zones en point.
        $lignes = DB::select(
            'SELECT z.id, z.nom, ROUND(z.superficie_calc_ha::numeric, 2) AS superficie_ha,'
            ." CASE WHEN z.superficie_calc_ha < {$seuilPoint}"
            ."   THEN ST_AsGeoJSON(ST_Transform(ST_PointOnSurface(z.geom), 4326), {$decimales})"
            ."   ELSE ST_AsGeoJSON(ST_Transform(ST_SimplifyPreserveTopology(z.geom, {$tolerance}), 4326), {$decimales})"
            .' END AS geometry'
            ." FROM {$staging} z"
            .' WHERE z.famille = ?'
            .'   AND z.superficie_calc_ha >= 0'
            .'   AND z.id IN ('
            ."     SELECT p.{$colonneId} FROM {$pivot} p"
            .'     JOIN communes c ON c.insee = p.insee'
            .'     WHERE c.dept = ?)'
            .' ORDER BY z.superficie_calc_ha DESC',
            [$famille, $dept]
        );

        $features = [];
        foreach ($lignes as $ligne) {
            $features[] = [
                'type' => 'Feature',
                'properties' => [
                    'famille' => $famille,
                    'id' => (string) $ligne->id,
                    'nom' => (string) $ligne->nom,
                    'superficie_ha' => (float) $ligne->superficie_ha,
                ],
                'geometry' => json_decode((string) $ligne->geometry, true),
            ];
        }

        return $this->ecrire("dept-{$dept}/".self::FICHIER_DEPT[$famille], [
            'type' => 'FeatureCollection',
            'features' => $features,
        ]);
    }

    /**
     * Contours communaux d'un département (couche de repérage de la carte,
     * style #41704f sans remplissage côté client).
     */
    public function departementCommunes(string $dept): int
    {
        $tolerance = (int) config('portail.geojson.simplification.communes');
        $decimales = (int) config('portail.geojson.decimales');

        $lignes = DB::select(
            'SELECT z.insee, z.nom, co.slug, ROUND(z.superf_calc_ha::numeric, 2) AS superficie_ha,'
            ." ST_AsGeoJSON(ST_Transform(ST_SimplifyPreserveTopology(z.geom2154, {$tolerance}), 4326), {$decimales}) AS geometry"
            .' FROM portail_tmp_communes z'
            .' JOIN communes co ON co.insee = z.insee'
            .' WHERE z.dept = ?'
            .' ORDER BY z.insee',
            [$dept]
        );

        $features = [];
        foreach ($lignes as $ligne) {
            $features[] = [
                'type' => 'Feature',
                'properties' => [
                    'insee' => (string) $ligne->insee,
                    'slug' => (string) $ligne->slug,
                    'nom' => (string) $ligne->nom,
                ],
                'geometry' => json_decode((string) $ligne->geometry, true),
            ];
        }

        return $this->ecrire("dept-{$dept}/communes.geojson", [
            'type' => 'FeatureCollection',
            'features' => $features,
        ]);
    }

    /**
     * Fichiers contour individuels des communes (mini-cartes des fiches) :
     * public/geo/communes/{insee}.geojson — géométrie simplifiée + centre.
     */
    public function contoursCommunes(string $dept): int
    {
        $tolerance = (int) config('portail.geojson.simplification.communes');
        $decimales = (int) config('portail.geojson.decimales');

        $lignes = DB::select(
            'SELECT z.insee, z.nom, co.slug, ROUND(z.superf_calc_ha::numeric, 2) AS superficie_ha,'
            ." ST_AsGeoJSON(ST_Transform(ST_SimplifyPreserveTopology(z.geom2154, {$tolerance}), 4326), {$decimales}) AS geometry,"
            ." ST_AsGeoJSON(ST_Transform(z.center, 4326), {$decimales}) AS center"
            .' FROM portail_tmp_communes z'
            .' JOIN communes co ON co.insee = z.insee'
            .' WHERE z.dept = ? ORDER BY z.insee',
            [$dept]
        );

        $nb = 0;
        foreach ($lignes as $ligne) {
            $this->ecrire('communes/'.$ligne->insee.'.geojson', [
                'type' => 'Feature',
                'properties' => [
                    'insee' => (string) $ligne->insee,
                    'slug' => (string) $ligne->slug,
                    'nom' => (string) $ligne->nom,
                    'superficie_ha' => (float) $ligne->superficie_ha,
                    'center' => json_decode((string) $ligne->center, true),
                ],
                'geometry' => json_decode((string) $ligne->geometry, true),
            ]);
            $nb++;
        }

        return $nb;
    }

    /**
     * Fichier objet d'une zone (fiches ZNIEFF/Natura/AP).
     */
    public function objet(string $famille, string $id): bool
    {
        $this->familleValide($famille);
        $staging = self::STAGING[$famille];
        $tolerance = (float) config("portail.geojson.simplification.{$this->cleTolerance($famille)}");
        $seuilPoint = (float) config('portail.geojson.seuil_point_ha');
        $decimales = (int) config('portail.geojson.decimales');

        $ligne = DB::selectOne(
            'SELECT z.id, z.nom, ROUND(z.superficie_calc_ha::numeric, 2) AS superficie_ha,'
            ." CASE WHEN z.superficie_calc_ha < {$seuilPoint}"
            ."   THEN ST_AsGeoJSON(ST_Transform(ST_PointOnSurface(z.geom), 4326), {$decimales})"
            ."   ELSE ST_AsGeoJSON(ST_Transform(ST_SimplifyPreserveTopology(z.geom, {$tolerance}), 4326), {$decimales})"
            .' END AS geometry'
            ." FROM {$staging} z WHERE z.famille = ? AND z.id = ?",
            [$famille, $id]
        );
        if ($ligne === null) {
            return false;
        }

        return $this->ecrire(self::DOSSIER_OBJET[$famille]."/{$id}.geojson", [
            'type' => 'Feature',
            'properties' => [
                'famille' => $famille,
                'id' => (string) $ligne->id,
                'nom' => (string) $ligne->nom,
                'superficie_ha' => (float) $ligne->superficie_ha,
            ],
            'geometry' => json_decode((string) $ligne->geometry, true),
        ]) === 1;
    }

    /**
     * manifest.json : versions des référentiels, volumes, date de
     * génération — la page Carte s'en sert pour invalider le cache
     * navigateur au rafraîchissement annuel.
     */
    public function manifest(): void
    {
        $compter = function (string $table): int {
            try {
                return Schema::hasTable($table) ? (int) DB::table($table)->count() : 0;
            } catch (\Throwable) {
                return 0;
            }
        };

        $fichiers = [];
        foreach (glob($this->dir().'/*.geojson') ?: [] as $f) {
            $fichiers[] = basename($f);
        }

        $this->ecrire('manifest.json', [
            'genere_le' => now()->toIso8601String(),
            'referentiels' => (array) config('portail.inpn.versions'),
            'volumes' => [
                'communes' => $compter('communes'),
                'zonages' => $compter('zonages'),
                'natura_sites' => $compter('natura_sites'),
                'aires_protegees' => $compter('aires_protegees'),
                'zonage_communes' => $compter('zonage_communes'),
                'natura_communes' => $compter('natura_communes'),
                'ap_communes' => $compter('ap_communes'),
            ],
            'actions' => ['departs' => array_map('basename', glob($this->dir().'/dept-*') ?: [])],
        ], 'manifest');
    }

    // ------------------------------------------------------------------

    private function dir(): string
    {
        $dir = (string) config('portail.geojson.dir');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return $dir;
    }

    /**
     * Écrit un fichier GeoJSON (retour : nombre de « features » écrites,
     * 1 pour un objet isolé).
     *
     * @param  array<string, mixed>  $contenu
     */
    private function ecrire(string $relatif, array $contenu, ?string $cle = null): int
    {
        $chemin = $this->dir().'/'.ltrim($relatif, '/');
        if (! is_dir(dirname($chemin))) {
            mkdir(dirname($chemin), 0775, true);
        }
        $json = json_encode($contenu, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return 0;
        }
        file_put_contents($chemin, $json);

        if ($cle === 'manifest') {
            return 1;
        }

        return isset($contenu['features']) ? count($contenu['features'])
            : ($contenu['type'] === 'Feature' ? 1 : 0);
    }

    private function familleValide(string $famille): void
    {
        if (! isset(self::STAGING[$famille])) {
            throw new \InvalidArgumentException("Famille inconnue : {$famille} (z1|z2|nat|ap)");
        }
    }

    private function cleTolerance(string $famille): string
    {
        return ['z1' => 'z1', 'z2' => 'z2', 'nat' => 'natura', 'ap' => 'ap'][$famille];
    }
}
