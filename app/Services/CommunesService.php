<?php

namespace App\Services;

use App\Models\ReferentielImport;
use App\Support\GeojsonStream;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Rafraîchissement du référentiel communal (AdminExpress COG — IGN) :
 * communes, slugs, population, superficies, centroïdes, codes postaux
 * (geo.api.gouv.fr), communes voisines (15 km), badge ABC (API de
 * l'Observatoire des ABC) et surcouches GeoJSON communales.
 *
 * Idempotent (upserts par code INSEE), journalisé dans
 * referentiel_imports ('adminexpress'). À rejouer chaque année, avant
 * portail:import-inpn — les pivots pointent sur les codes INSEE.
 */
class CommunesService
{
    public function __construct(
        private readonly SpatialService $spatial,
        private readonly GeojsonExportService $geojson,
        private readonly AbcClient $abc,
    ) {}

    /**
     * @param  callable(int): void  $tick
     * @return array<string, int|bool>
     */
    public function refresh(
        ?string $dept = null,
        ?callable $tick = null,
        bool $avecGeojson = true,
        bool $avecCodesPostaux = true,
        bool $avecAbc = true,
    ): array {
        if ($this->spatial->adminExpressFile() === null) {
            throw new RuntimeException('GeoJSON AdminExpress (communes) introuvable — voir PORTAIL_ADMIN_EXPRESS_DIR.');
        }

        if ($this->spatial->disponible()) {
            $temp = $this->spatial->construireCommunesTemp($dept, $tick);
            $this->upsertDepuisTemp();
            $voisines = $this->spatial->pairesVoisines();
        } else {
            // Mode dégradé (tests sans PostGIS) : attributs seuls, pas de
            // superficies calculées, centroïdes ni voisines.
            Log::warning('Refresh des communes sans PostGIS : mode attributs seuls.');
            $temp = $this->upsertSansGeometrie($dept, $tick);
            $voisines = 0;
        }

        $codesPostaux = $avecCodesPostaux ? $this->majCodesPostaux() : 0;
        $nbAbc = $avecAbc ? $this->majHasAbc() : -1;

        $nb = (int) DB::table('communes')->count();
        $this->registrer($nb);

        $geojsonFichiers = 0;
        if ($avecGeojson && $this->spatial->disponible()) {
            foreach ($this->departs($dept) as $d) {
                $geojsonFichiers += $this->geojson->departementCommunes($d);
                $geojsonFichiers += $this->geojson->contoursCommunes($d);
            }
            $this->geojson->manifest();
        }

        return [
            'communes' => $temp['nb'],
            'ignores' => $temp['ignores'],
            'total' => $nb,
            'voisines' => $voisines,
            'codes_postaux' => $codesPostaux,
            'abc' => $nbAbc,
            'geojson' => $geojsonFichiers,
            'spatial' => $this->spatial->disponible(),
        ];
    }

    /**
     * Communes depuis la table temporaire (géométries 2154) : centroides
     * WGS84 + superficies calculées reportées dans la table réelle.
     */
    private function upsertDepuisTemp(): void
    {
        $colonnes = ['insee', 'slug', 'nom', 'dept', 'dept_nom', 'reg_code', 'reg_nom',
            'population', 'superficie_ha', 'superficie_calc_ha', 'lat', 'lng', 'has_abc'];
        $pris = [];
        $maj = [];

        $lignes = DB::select(
            'SELECT t.insee, t.nom, t.dept, t.reg_code, t.population, t.superf_insee_ha,'
            .' ROUND(t.superf_calc_ha::numeric, 2) AS superf_calc_ha,'
            .' ST_X(ST_Transform(t.center, 4326)) AS lng, ST_Y(ST_Transform(t.center, 4326)) AS lat'
            .' FROM portail_tmp_communes t ORDER BY t.insee'
        );
        foreach ($lignes as $ligne) {
            $insee = (string) $ligne->insee;
            $slug = $this->slugUnique((string) $ligne->nom, $pris, $insee);
            $pris[$slug] = $insee;
            $maj[] = [
                'insee' => $insee,
                'slug' => $slug,
                'nom' => (string) $ligne->nom,
                'dept' => (string) $ligne->dept,
                'dept_nom' => (string) config("portail.departements.{$ligne->dept}"),
                'reg_code' => (string) $ligne->reg_code,
                'reg_nom' => (string) config("portail.regions.{$ligne->reg_code}"),
                'population' => (int) $ligne->population,
                'superficie_ha' => $ligne->superf_insee_ha !== null ? round((float) $ligne->superf_insee_ha, 2) : null,
                'superficie_calc_ha' => (float) $ligne->superf_calc_ha,
                'lat' => round((float) $ligne->lat, 6),
                'lng' => round((float) $ligne->lng, 6),
                'has_abc' => false,
            ];
        }
        $this->upsertParLots($maj, $colonnes);

        // Reset des voisines obsolètes si le référentiel change de contour.
    }

    /**
     * Mode sans PostGIS : lecture directe du GeoJSON (attributs seulement).
     *
     * @return array{nb: int, ignores: int}
     */
    private function upsertSansGeometrie(?string $dept, ?callable $tick): array
    {
        $file = $this->spatial->adminExpressFile();
        if ($file === null) {
            throw new RuntimeException('AdminExpress introuvable.');
        }
        $colonnes = ['insee', 'slug', 'nom', 'dept', 'dept_nom', 'reg_code', 'reg_nom', 'population', 'superficie_ha', 'has_abc'];

        $nb = 0;
        $ignores = 0;
        $pris = [];
        $lots = [];
        foreach (GeojsonStream::features($file) as $feature) {
            $props = $feature['properties'];
            $insee = $this->spatial->codeCommune(
                (string) ($props['codgeo'] ?? $props['insee_com'] ?? $props['code_insee'] ?? '')
            );
            if ($insee === null) {
                $ignores++;

                continue;
            }
            if ($dept !== null && ! str_starts_with($insee, $dept)) {
                continue;
            }
            $nom = (string) ($props['libgeo'] ?? '');
            $codeDept = $this->spatial->deptDuCode($insee);
            $slug = $this->slugUnique($nom, $pris, $insee);
            $pris[$slug] = $insee;
            $lots[] = [
                'insee' => $insee,
                'slug' => $slug,
                'nom' => $nom,
                'dept' => $codeDept,
                'dept_nom' => (string) config("portail.departements.{$codeDept}"),
                'reg_code' => (string) ($props['reg'] ?? ''),
                'reg_nom' => (string) config('portail.regions.'.($props['reg'] ?? 'x'), ''),
                'population' => (int) ($props['population'] ?? 0),
                'superficie_ha' => isset($props['superf']) ? round((float) $props['superf'], 2) : null,
                'has_abc' => false,
            ];
            $nb++;
            if ($tick !== null && $nb % 200 === 0) {
                $tick(200);
            }
        }
        $this->upsertParLots($lots, $colonnes);

        return ['nb' => $nb, 'ignores' => $ignores];
    }

    /**
     * @param  array<int, array<string, mixed>>  $lignes
     * @param  array<int, string>  $colonnes
     */
    private function upsertParLots(array $lignes, array $colonnes): void
    {
        foreach (array_chunk($lignes, 500) as $lot) {
            DB::table('communes')->upsert($lot, 'insee', $colonnes);
        }
    }

    /**
     * Slug français : l'Abergement-Clémenciat → l-abergement-clemenciat
     * (Str::slug gère accents, apostrophes, tirets). Collision réelle
     * entre communes : suffixe du code INSEE.
     *
     * @param  array<string, string>  $pris  slug => insee (mis à jour)
     */
    private function slugUnique(string $nom, array &$pris, string $insee): string
    {
        $base = Str::slug($nom);
        if ($base === '') {
            $base = 'commune';
        }
        if (! isset($pris[$base])) {
            $pris[$base] = $insee;

            return $base;
        }

        $slug = $base.'-'.$insee;
        $pris[$slug] = $insee;

        return $slug;
    }

    /**
     * Codes postaux : UNE requête massive geo.api.gouv.fr (les requêtes
     * unitaires seraient une rafale — à proscrire), mise au cache 7 jours.
     */
    private function majCodesPostaux(): int
    {
        try {
            $reponse = Http::timeout(120)
                ->connectTimeout(30)
                ->acceptJson()
                ->get(rtrim((string) config('portail.geo_api.base'), '/').'/communes', [
                    'fields' => 'code,codesPostaux',
                ]);
            if ($reponse->failed()) {
                Log::warning('geo.api.gouv.fr indisponible : codes postaux non mis à jour.', ['status' => $reponse->status()]);

                return 0;
            }
            $maj = 0;
            foreach ($reponse->json() as $commune) {
                $codes = $commune['codesPostaux'] ?? [];
                if ($codes === []) {
                    continue;
                }
                $maj += DB::table('communes')
                    ->where('insee', strtoupper((string) ($commune['code'] ?? '')))
                    ->update(['codes_postaux' => implode(',', array_unique($codes))]);
            }

            return $maj;
        } catch (\Throwable $e) {
            Log::warning('Codes postaux : échec de la requête geo.api.gouv.fr.', ['erreur' => $e->getMessage()]);

            return 0;
        }
    }

    /**
     * Badge « ABC » : P UNE requête vers l'API de l'Observatoire des ABC.
     * Retour -1 = indisponible (has_abc laissé en l'état).
     */
    private function majHasAbc(): int
    {
        $codes = $this->abc->communesAvecAbc();
        if ($codes === null) {
            return -1;
        }

        DB::table('communes')->update(['has_abc' => false]);
        foreach (array_chunk($codes, 500) as $lot) {
            DB::table('communes')->whereIn('insee', $lot)->update(['has_abc' => true]);
        }

        return count($codes);
    }

    /**
     * @return array<int, string>
     */
    private function departs(?string $dept): array
    {
        if ($dept !== null) {
            return [$dept];
        }
        $depts = DB::table('communes')->distinct()->pluck('dept')->all();
        sort($depts);

        return $depts;
    }

    private function registrer(int $nb): void
    {
        ReferentielImport::updateOrCreate(
            ['source' => 'adminexpress'],
            [
                'version_source' => (string) config('portail.inpn.versions.adminexpress'),
                'nb_entites' => $nb,
                'importe_le' => now(),
            ],
        );
    }
}
