<?php

namespace App\Services;

use App\Support\GeojsonStream;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Outils spatiaux PostGIS — LOCAL UNIQUEMENT (conteneur Docker).
 *
 * La production (MySQL mutualisé, sans PostGIS) ne fait jamais appel à
 * cette classe : elle consomme le SQL portable et les GeoJSON générés ici.
 * Reprend le calibrage de l'Observatoire des ABC : tables temporaires,
 * index GIST AVANT jointure (sinon test séquentiel de centaines de
 * millions de paires), ST_MakeValid (polygones INPN parfois invalides),
 * reprojection unique.
 *
 * Toutes les mesures se font en Lambert-93 (EPSG:2154), superficies en
 * hectares — écart de −0,3 à −0,7 % constaté avec les superficies
 * officielles INSEE, affiché « ≈ ± 1 % » sur le site.
 */
class SpatialService
{
    /**
     * Vrai si le pilote est PostgreSQL ET l'extension PostGIS active.
     */
    public function disponible(): bool
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return false;
        }

        try {
            return (bool) DB::select("SELECT 1 FROM pg_extension WHERE extname = 'postgis'");
        } catch (\Throwable) {
            return false;
        }
    }

    // ------------------------------------------------------------------
    // Table temporaire des communes (AdminExpress)
    // ------------------------------------------------------------------

    /**
     * portail_tmp_communes(insee, nom, dept, reg_code, population,
     * superf_insee_ha, geom4326_json, geom2154, center, superf_calc_ha).
     *
     * @param  callable(int): void  $tick  appelé tous les 100 enregistrements
     * @return array{nb: int, ignores: int}
     */
    public function construireCommunesTemp(?string $dept = null, ?callable $tick = null): array
    {
        $file = $this->adminExpressFile();
        if ($file === null) {
            throw new RuntimeException('GeoJSON AdminExpress (communes) introuvable — voir config portail.inpn.admin_express_dir.');
        }

        DB::statement('DROP TABLE IF EXISTS portail_tmp_communes');
        DB::statement(
            'CREATE TEMP TABLE portail_tmp_communes ('
            .' insee char(5) PRIMARY KEY, nom text, dept text, reg_code text,'
            .' population int, superf_insee_ha double precision,'
            .' geom4326_json text, geom2154 geometry, center geometry,'
            .' superf_calc_ha double precision)'
        );

        $codes = [];
        $props = [];
        $geometries = [];
        $ignores = 0;
        $nb = 0;

        foreach (GeojsonStream::features($file) as $feature) {
            $code = $this->codeCommune(
                (string) ($feature['properties']['codgeo']
                    ?? $feature['properties']['insee_com']
                    ?? $feature['properties']['code_insee']
                    ?? $feature['properties']['INSEE_COM']
                    ?? '')
            );
            $geometry = json_encode($feature['geometry']);
            if ($code === null || $geometry === false) {
                $ignores++;

                continue;
            }
            if ($dept !== null && ! str_starts_with($code, $dept)) {
                continue;
            }
            $codes[] = $code;
            $props[] = [
                'nom' => (string) ($feature['properties']['libgeo'] ?? ''),
                'dept' => $this->deptDuCode($code),
                'reg' => (string) ($feature['properties']['reg'] ?? ''),
                'population' => (int) ($feature['properties']['population'] ?? 0),
                'superf' => (float) ($feature['properties']['superf'] ?? 0),
            ];
            $geometries[] = $geometry;

            if (count($codes) >= 100) {
                $this->insererCommunesTemp($codes, $props, $geometries);
                if ($tick !== null) {
                    $tick(100);
                }
                $codes = [];
                $props = [];
                $geometries = [];
            }
            $nb++;
        }
        if ($codes !== []) {
            $this->insererCommunesTemp($codes, $props, $geometries);
        }

        // Reprojection unique + centroïdes, puis index AVANT toute jointure.
        DB::statement(
            'UPDATE portail_tmp_communes SET'
            .' geom2154 = ST_Transform(ST_SetSRID(ST_GeomFromGeoJSON(geom4326_json), 4326), 2154),'
            .' superf_calc_ha = ST_Area(ST_Transform(ST_SetSRID(ST_GeomFromGeoJSON(geom4326_json), 4326), 2154)) / 10000.0,'
            .' center = ST_Centroid(ST_Transform(ST_SetSRID(ST_GeomFromGeoJSON(geom4326_json), 4326), 2154))'
        );
        DB::statement('CREATE INDEX portail_tmp_communes_geom_idx ON portail_tmp_communes USING GIST (geom2154)');
        DB::statement('ANALYZE portail_tmp_communes');

        return ['nb' => $nb, 'ignores' => $ignores];
    }

    // ------------------------------------------------------------------
    // Tables temporaires de zones (ZNIEFF / Natura / aires protégées)
    // ------------------------------------------------------------------

    /**
     * Crée la table de staging des zones d'une famille.
     * $table ∈ {portail_tmp_znieff, portail_tmp_natura, portail_tmp_apa}.
     * Les géométries sont stockées avec leur SRS d'origine (champ srs),
     * la reprojection en 2154 est faite une fois pour toutes ensuite.
     */
    public function creerStaging(string $table): void
    {
        DB::statement("DROP TABLE IF EXISTS {$table}");
        DB::statement(
            "CREATE TEMP TABLE {$table} ("
            .' id varchar(20) NOT NULL, famille varchar(4) NOT NULL, nom text,'
            .' superficie_ref double precision, srs int NOT NULL, geom geometry,'
            .' PRIMARY KEY (id, famille))'
        );
    }

    /**
     * Insère un lot de zones lues d'un shapefile (WKT + SRS d'origine).
     *
     * @param  array<int, array{id: string, famille: string, nom: ?string, superficie_ref: ?float, srs: int, wkt: string}>  $lot
     */
    public function insererStaging(string $table, array $lot): void
    {
        $lignes = [];
        $bindings = [];
        foreach ($lot as $zone) {
            // CAST explicite du WKT : sans contexte, Postgres infère text
            // (leçon de l'ABC — révélée par un test réel SQLite vs Postgres).
            $lignes[] = '(?, ?, ?, ?, ?, ST_SetSRID(ST_GeomFromText(CAST(? AS TEXT)), ?))';
            $bindings[] = $zone['id'];
            $bindings[] = $zone['famille'];
            $bindings[] = $zone['nom'];
            $bindings[] = $zone['superficie_ref'];
            $bindings[] = $zone['srs'];
            $bindings[] = $zone['wkt'];
            $bindings[] = $zone['srs'];
        }
        DB::insert("INSERT INTO {$table} (id, famille, nom, superficie_ref, srs, geom) VALUES ".implode(', ', $lignes), $bindings);
    }

    /**
     * Insère un lot de zones lues d'un GeoPackage (WKB hexadécimal + SRS).
     *
     * @param  array<int, array{id: string, famille: string, nom: ?string, superficie_ref: ?float, srs: int, wkb_hex: string}>  $lot
     */
    public function insererStagingWkb(string $table, array $lot): void
    {
        $lignes = [];
        $bindings = [];
        foreach ($lot as $zone) {
            // Le WKB passe en hexa : un binding binaire n'est pas
            // transmettable en PARAM_STR sur la connexion pgsql (ABC).
            $lignes[] = '(?, ?, ?, ?, ?, ST_SetSRID(ST_GeomFromWKB(decode(?, \'hex\')), ?))';
            $bindings[] = $zone['id'];
            $bindings[] = $zone['famille'];
            $bindings[] = $zone['nom'];
            $bindings[] = $zone['superficie_ref'];
            $bindings[] = $zone['srs'];
            $bindings[] = $zone['wkb_hex'];
            $bindings[] = $zone['srs'];
        }
        DB::insert("INSERT INTO {$table} (id, famille, nom, superficie_ref, srs, geom) VALUES ".implode(', ', $lignes), $bindings);
    }

    /**
     * Reprojection finale en 2154 + validation : MakeValide (certains
     * polygones INPN sont invalides : auto-intersections → TopologyException
     * GEOS), ST_CollectionExtract(.., 3) ne garde que les polygones, et
     * index GIST AVANT toute jointure.
     */
    public function finaliserStaging(string $table, ?callable $tick = null): void
    {
        if ($tick !== null) {
            $tick(1);
        }
        DB::statement(
            "UPDATE {$table} SET geom = ST_CollectionExtract(ST_MakeValid(ST_Transform(geom, 2154)), 3)"
        );
        DB::statement("DELETE FROM {$table} WHERE geom IS NULL OR ST_Area(geom) <= 0");
        DB::statement("CREATE INDEX {$table}_geom_idx ON {$table} USING GIST (geom)");
        DB::statement("ALTER TABLE {$table} ADD COLUMN superficie_calc_ha double precision");
        DB::statement("UPDATE {$table} SET superficie_calc_ha = ST_Area(geom) / 10000.0");
        DB::statement("ANALYZE {$table}");
    }

    // ------------------------------------------------------------------
    // Croisements et mesures
    // ------------------------------------------------------------------

    /**
     * Croisement zones × communes en Lambert-93 : la table pivot reçoit
     * surface d'intersection (ha), part du zonage (%) et part du territoire
     * communal (%). STRICTEMENT précalculé — la production ne recalcule
     * jamais rien (cf. portail:export-sql).
     *
     * Ne garde que les communes déjà en table (le référentiel communal doit
     * être rafraîchi AVANT l'import INPN : portail:refresh-communes).
     *
     * @return array{nb: int, communes_manquantes: int}
     */
    public function croiser(string $pivotTable, string $pivotIdColumn, string $zonesTable): array
    {
        // Communes du référentiel absentes de la table réelle (éditions
        // décalées, communes fusionnées) : elles ne participent pas au
        // croisement — le compte est journalisé pour diagnostic.
        $communesManquantes = (int) DB::scalar(
            'SELECT COUNT(*) FROM ('
            .' SELECT DISTINCT c.insee FROM portail_tmp_communes c'
            .' LEFT JOIN communes co ON co.insee = c.insee'
            .' WHERE co.insee IS NULL'
            .') t'
        );
        if ($communesManquantes > 0) {
            Log::warning('Communes AdminExpress absentes de la base (communes.json rafraîchi ?)', [
                'nb' => $communesManquantes,
            ]);
        }

        // Le pivot porte une clé étrangère vers la table desservie : ne
        // croiser que les objets RÉELLEMENT écrits dans cette table (le
        // staging peut porter des zones que le référentiel d'attributs
        // écarte — périmètre actif du CSV INPN). Sans cette jointure, le
        // croisement France viole la FK sur les zones non actives.
        $cible = match ($pivotTable) {
            'zonage_communes' => ['zonages', 'id'],
            'natura_communes' => ['natura_sites', 'code'],
            'ap_communes' => ['aires_protegees', 'id'],
            default => throw new \InvalidArgumentException("Pivot inconnu : {$pivotTable}"),
        };

        DB::table($pivotTable)->delete();
        DB::statement(
            "INSERT INTO {$pivotTable} ({$pivotIdColumn}, insee, surface_ha, part_zonage, part_commune)"
            .' SELECT z.id, c.insee,'
            .' ROUND((a.ha)::numeric, 4),'
            .' ROUND((100.0 * a.ha / NULLIF(zs.zha, 0))::numeric, 3),'
            .' ROUND((100.0 * a.ha / NULLIF(c.superf_calc_ha, 0))::numeric, 3)'
            ." FROM {$zonesTable} z"
            .' JOIN LATERAL (SELECT ST_Area(z.geom) / 10000.0 AS zha) zs ON true'
            .' JOIN portail_tmp_communes c ON ST_Intersects(z.geom, c.geom2154)'
            .' JOIN LATERAL (SELECT ST_Area(ST_Intersection(z.geom, c.geom2154)) / 10000.0 AS ha) a ON a.ha > 0'
            .' JOIN communes co ON co.insee = c.insee'
            ." JOIN {$cible[0]} r ON r.{$cible[1]} = z.id"
        );

        return ['nb' => (int) DB::scalar("SELECT COUNT(*) FROM {$pivotTable}"), 'communes_manquantes' => $communesManquantes];
    }

    /**
     * Reporte sur la table définitive les mesures calculées en staging :
     * superficie (géométrie, affichée) et bbox WGS84 (numérique — jamais
     * de géométrie stockée).
     *
     * Signature générique : table, colonne clé, colonne superficie.
     */
    public function reporterMesures(string $table, string $keyColumn, string $zonesTable): int
    {
        return DB::affectingStatement(
            "UPDATE {$table} t SET"
            .' superficie_ha = ROUND(z.superficie_calc_ha::numeric, 2),'
            .' bbox_min_lng = ROUND(ST_XMin(e)::numeric, 6),'
            .' bbox_max_lng = ROUND(ST_XMax(e)::numeric, 6),'
            .' bbox_min_lat = ROUND(ST_YMin(e)::numeric, 6),'
            .' bbox_max_lat = ROUND(ST_YMax(e)::numeric, 6)'
            ." FROM {$zonesTable} z"
            .' CROSS JOIN LATERAL (SELECT ST_Transform(ST_Envelope(z.geom), 4326) AS e) env'
            ." WHERE z.id = t.{$keyColumn}"
        );
    }

    /**
     * Paires de communes voisines (≤ rayon config) sur centroïdes en
     * Lambert-93 : centroïdes déjà matérialisés et indexés, paires
     * normalisées (insee_a < insee_b).
     */
    public function pairesVoisines(): int
    {
        $rayon = (int) config('portail.voisines.rayon_m');

        DB::statement('CREATE INDEX IF NOT EXISTS portail_tmp_communes_center_idx ON portail_tmp_communes USING GIST (center)');
        DB::statement('ANALYZE portail_tmp_communes');

        DB::table('commune_communes')->delete();
        DB::statement(
            'INSERT INTO commune_communes (insee_a, insee_b, distance_m)'
            .' SELECT c1.insee, c2.insee, ROUND(ST_Distance(c1.center, c2.center))::int'
            .' FROM portail_tmp_communes c1'
            ." JOIN portail_tmp_communes c2 ON c1.insee < c2.insee AND ST_DWithin(c1.center, c2.center, {$rayon})"
        );

        return (int) DB::scalar('SELECT COUNT(*) FROM commune_communes');
    }

    // ------------------------------------------------------------------
    // Référentiels
    // ------------------------------------------------------------------

    /**
     * Normalise un code commune : INSEEC07331 ou 07331 → 07331.
     * Refuse les codes non COG (ex. « NR » des éditions AdminExpress pour
     * Saint-Martin/Saint-Barthélemy : hors périmètre MVP, documenté).
     */
    public function codeCommune(?string $raw): ?string
    {
        $code = strtoupper(trim((string) $raw));
        if (str_starts_with($code, 'INSEEC')) {
            $code = substr($code, 6);
        } elseif (str_starts_with($code, 'COM')) {
            $code = substr($code, 3);
        }
        if (! preg_match('/^([0-9]{2}|2A|2B)[0-9]{3}$/', $code)) {
            return null;
        }

        return $code;
    }

    /**
     * Département du code INSEE (2A/2B et 971–976 sur 3 caractères).
     */
    public function deptDuCode(string $insee): string
    {
        if (str_starts_with($insee, '97')) {
            return substr($insee, 0, 3);
        }

        return substr($insee, 0, 2);
    }

    /**
     * Fichier GeoJSON AdminExpress (communes) — heuristique reprise de
     * l'Observatoire des ABC.
     */
    public function adminExpressFile(): ?string
    {
        $candidates = [];
        foreach ([
            (string) config('portail.inpn.admin_express_dir'),
            (string) config('portail.inpn.dir').'/admin-express',
        ] as $folder) {
            if (is_dir($folder)) {
                foreach (glob($folder.'/*.{geojson,json}', GLOB_BRACE) ?: [] as $f) {
                    if (! str_contains(mb_strtolower(basename($f)), 'topo')) {
                        $candidates[] = $f;
                    }
                }
            }
        }
        foreach ($candidates as $f) {
            if (str_contains(mb_strtolower(basename($f)), 'commune')) {
                return $f;
            }
        }
        foreach ($candidates as $f) {
            if (preg_match('/(^|[^a-z])com([0-9_.]|$)/i', basename($f))) {
                return $f; // a-com2025.geojson…
            }
        }
        foreach ($candidates as $f) {
            if (str_ends_with(mb_strtolower($f), '.geojson')) {
                return $f;
            }
        }

        return $candidates[0] ?? null;
    }

    /**
     * @param  array<int, string>  $codes
     * @param  array<int, array{nom: string, dept: string, reg: string, population: int, superf: float}>  $props
     * @param  array<int, string>  $geometries
     */
    private function insererCommunesTemp(array $codes, array $props, array $geometries): void
    {
        $lignes = [];
        $bindings = [];
        foreach ($codes as $i => $code) {
            $lignes[] = '(?, ?, ?, ?, ?, ?, ?)';
            $bindings[] = $code;
            $bindings[] = $props[$i]['nom'];
            $bindings[] = $props[$i]['dept'];
            $bindings[] = $props[$i]['reg'];
            $bindings[] = $props[$i]['population'];
            $bindings[] = $props[$i]['superf'];
            $bindings[] = $geometries[$i];
        }
        DB::insert(
            'INSERT INTO portail_tmp_communes (insee, nom, dept, reg_code, population, superf_insee_ha, geom4326_json)'
            .' VALUES '.implode(', ', $lignes),
            $bindings
        );
    }
}
