<?php

namespace App\Services;

use App\Models\ReferentielImport;
use App\Support\ShapefileReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDO;
use RuntimeException;

/**
 * Import des référentiels INPN (data/inpn-data, jamais dans git) vers les
 * tables du portail. LOCAL UNIQUEMENT (Docker + PostGIS, croisements en
 * Lambert-93 EPSG:2154) : la production reçoit le SQL portable
 * (portail:export-sql) et les GeoJSON précalculés — aucune géométrie ne
 * quitte la machine locale, aucun calcul ne se fait à la volée côté site.
 *
 * Méthodes idempotentes (les FK cascadent), journalisées dans
 * referentiel_imports (versions affichées en pied de page).
 *
 * Enchaînement attendu :
 *   1. portail:refresh-communes   (référentiel communal d'abord : les pivots pointent dessus)
 *   2. portail:import-inpn
 *
 * Superficies ZNIEFF : celle affichée est l'aire géométrique mesurée en
 * 2154 (cohérente avec les parts de recouvrement) ; la superficie
 * officielle INPN (SU_ZN) est conservée en référence — écart constaté
 * −0,3 à −0,7 %, affiché « ≈ ± 1 % » sur le site.
 */
class InpnImportService
{
    private const TERRITOIRES = ['GLP', 'GUF', 'MYT', 'REU', 'SPM', 'NCL', 'MTQ', 'MAF', 'PYF', 'TADL', 'SUBANT', 'BLM', 'CLI', 'EPA'];

    /** Taille des lots d'insertion (REF_ESPECE.csv fait ~2,1 M de lignes). */
    private const BATCH = 500;

    public function __construct(
        private readonly CsvReaderService $csv,
        private readonly SpatialService $spatial,
        private readonly GeojsonExportService $geojson,
    ) {}

    // ==================================================================
    // ZNIEFF
    // ==================================================================

    /**
     * @param  callable(int): void  $tick
     * @return array<string, int|bool>
     */
    public function importZnieff(?string $dept = null, ?callable $tick = null, bool $avecGeojson = true): array
    {
        $dir = $this->dir();
        $attrs = $this->attrsZnieff($dir);
        if ($attrs === []) {
            throw new RuntimeException('Aucune ZNIEFF active trouvée dans REF_ZNIEFF.csv.');
        }

        if (! $this->spatial->disponible()) {
            Log::warning('Import ZNIEFF sans PostGIS : attributs du référentiel seuls (ni surfaces ni GeoJSON).');
            $origines = []; // pas de géométries lues
            $this->ecrireZonages($attrs, array_keys($attrs), $origines);
            $nbEsp = $this->importZnieffEspeces($dir, array_keys($attrs), $tick);
            $this->registrer('znieff', count($attrs));

            return ['zones' => count($attrs), 'especes' => $nbEsp, 'geometries' => 0, 'intersections' => 0, 'spatial' => false];
        }

        $this->exigerReferentielCommunal();
        $this->spatial->construireCommunesTemp($dept, $tick);

        [$nbGeom, $origines] = $this->stagingZnieff($dir, $tick);
        $this->spatial->finaliserStaging('portail_tmp_znieff', $tick);

        $ids = $this->perimetre('portail_tmp_znieff', $dept, array_keys($attrs));
        $this->ecrireZonages($attrs, $ids, $origines);
        $nbEsp = $this->importZnieffEspeces($dir, $ids, $tick);
        $croisement = $this->spatial->croiser('zonage_communes', 'zonage_id', 'portail_tmp_znieff');
        $this->spatial->reporterMesures('zonages', 'id', 'portail_tmp_znieff');
        $this->registrer('znieff', count($ids));

        $fichiersGeojson = 0;
        if ($avecGeojson) {
            foreach ($this->departsTouchees($dept) as $d) {
                $fichiersGeojson += $this->geojson->departementZones('z1', $d);
                $fichiersGeojson += $this->geojson->departementZones('z2', $d);
            }
            foreach ($ids as $id) {
                $this->geojson->objet($attrs[$id]['famille'], $id);
                $fichiersGeojson++;
            }
            $this->geojson->manifest();
        }

        return [
            'zones' => count($ids),
            'especes' => $nbEsp,
            'geometries' => $nbGeom,
            'intersections' => $croisement['nb'],
            'communes_manquantes' => $croisement['communes_manquantes'],
            'geojson' => $fichiersGeojson,
            'spatial' => true,
        ];
    }

    /**
     * Espèces citées par les ZNIEFF du périmètre (REF_ESPECE.csv, ~2,1 M de
     * lignes France entière) : lecture en flux, insertion par lots.
     * REF_ESPECE.csv porte le NM_SFFZN (clé de zonages.id) — filtre direct.
     *
     * Une espèce CONFIDENTIELLE reste importée avec son flag : elle ne doit
     * JAMAIS être affichée ni comptée (filtre absolu posé côté lecture dans
     * App\Support\Especes, cf. AGENTS.md).
     *
     * @param  array<int, string>  $idsPerimetre  NM_SFFZN écrits dans zonages
     * @return int nombre de lignes insérées
     */
    private function importZnieffEspeces(string $dir, array $idsPerimetre, ?callable $tick): int
    {
        $fichier = $dir.'/ZNIEFF/REF_ESPECE.csv';
        DB::table('znieff_especes')->delete(); // rechargement intégral (idempotent)
        if (! is_file($fichier)) {
            Log::warning('REF_ESPECE.csv absent : aucune espèce ZNIEFF importée.', ['fichier' => $fichier]);

            return 0;
        }

        $perimetre = array_flip($idsPerimetre);
        // Repli : si NM_SFFZN est vide dans REF_ESPECE, retomber sur le
        // mapping ID_ZNIEFF (numérique) → NM_SFFZN lu dans REF_ZNIEFF.csv.
        $versNational = [];
        foreach ($this->csv->iterate($dir.'/ZNIEFF/REF_ZNIEFF.csv') as $rec) {
            $num = trim((string) ($rec['id_znieff'] ?? ''));
            $nat = trim((string) ($rec['nm_sffzn'] ?? ''));
            if ($num !== '' && $nat !== '') {
                $versNational[$num] = $nat;
            }
        }

        $count = 0;
        $chunk = [];
        foreach ($this->csv->iterate($fichier) as $rec) {
            if (($rec['fg_supp'] ?? '') === 'true') {
                continue;
            }
            $nat = trim((string) ($rec['nm_sffzn'] ?? ''));
            if ($nat === '') {
                $nat = $versNational[trim((string) ($rec['id_znieff'] ?? ''))] ?? '';
            }
            if ($nat === '' || ! isset($perimetre[$nat])) {
                continue;
            }
            $chunk[] = [
                'zonage_id' => $nat,
                'cd_ref' => (int) ($rec['cd_ref'] ?? 0),
                'nom_cite' => (string) ($rec['nom_cite'] ?? ''),
                'groupe_taxo' => $this->nonVide($rec['groupe_taxo'] ?? null),
                // Dictionnaire INPN (FG_ESP) : A autre espèce ; E autre espèce
                // à enjeux ; D déterminante ; C confidentielle.
                'determinante' => ($rec['fg_esp'] ?? '') === 'D',
                'confidentielle' => ($rec['fg_conf'] ?? '') === 'true' || ($rec['fg_esp'] ?? '') === 'C',
            ];
            $count++;
            if (count($chunk) >= self::BATCH) {
                DB::table('znieff_especes')->insert($chunk);
                $chunk = [];
                if ($tick !== null) {
                    $tick(self::BATCH);
                }
            }
        }
        if ($chunk !== []) {
            DB::table('znieff_especes')->insert($chunk);
        }

        return $count;
    }

    /**
     * Attributs du référentiel ZNIEFF (REF_ZNIEFF.csv), zones actives.
     *
     * @return array<string, array{famille: string, nom: string, num_regional: ?string, superficie_ref_ha: ?float, date_crea: ?string, date_modif_geo: ?string, parent: ?string, volet: ?string}>
     */
    private function attrsZnieff(string $dir): array
    {
        $fichier = $dir.'/ZNIEFF/REF_ZNIEFF.csv';
        if (! is_file($fichier)) {
            throw new RuntimeException("Référentiel INPN introuvable : {$fichier}");
        }

        $attrs = [];
        foreach ($this->csv->iterate($fichier) as $rec) {
            if (($rec['fg_supp'] ?? '') === 'true') {
                continue;
            }
            $id = trim((string) ($rec['nm_sffzn'] ?? ''));
            if ($id === '') {
                continue;
            }
            $attrs[$id] = [
                'famille' => ((int) ($rec['ty_zone'] ?? 2)) === 1 ? 'z1' : 'z2',
                'nom' => trim((string) ($rec['lb_zn'] ?? '')),
                'num_regional' => $this->nonVide($rec['nm_regzn'] ?? null),
                'superficie_ref_ha' => $this->decimal($rec['su_zn'] ?? null),
                'date_crea' => $this->dateInpn($rec['date_crea'] ?? null),
                'date_modif_geo' => $this->dateInpn($rec['date_modif'] ?? null),
                'parent' => $this->nonVide($rec['nm_sffzn_parent'] ?? null),
                'volet' => $this->nonVide($rec['volet_znieff'] ?? null),
            ];
        }

        return $attrs;
    }

    /**
     * Charge tous les shapefiles ZNIEFF (métropole + outre-mer, terre et
     * mer) vers portail_tmp_znieff. Rend le nombre de géométries lues et
     * la carte id => [territoire, volet] (origine du fichier).
     *
     * @return array{0: int, 1: array<string, array{territoire: string, volet: string}>}
     */
    private function stagingZnieff(string $dir, ?callable $tick): array
    {
        $files = glob($dir.'/ZNIEFF/SIG_ZNIEFF/*.shp') ?: [];
        if ($files === []) {
            throw new RuntimeException('Aucun shapefile ZNIEFF (SIG_ZNIEFF/*.shp).');
        }

        $this->spatial->creerStaging('portail_tmp_znieff');
        $nb = 0;
        $lot = [];
        $origines = [];
        foreach ($files as $file) {
            $base = pathinfo($file, PATHINFO_FILENAME);
            $famille = str_contains($base, 'ZNIEFF1') ? 'z1' : 'z2';
            $volet = str_contains($base, '_MER') ? 'MER' : 'CONT';
            $territoire = $this->territoireDeFichier($base);

            $reader = new ShapefileReader($file);
            if (! in_array($reader->shapeType(), [0, 1, 3, 5, 11, 13, 15, 18, 23, 25], true)) {
                Log::warning('Shapefile ignoré (type non géré).', ['fichier' => $base, 'type' => $reader->shapeType()]);

                continue;
            }
            $srs = $this->srsDetecte($reader);

            foreach ($reader->records() as $enregistrement) {
                $id = trim($enregistrement['attrs']['ID_MNHN'] ?? '');
                if ($id === '' || $enregistrement['wkt'] === null) {
                    continue;
                }
                $lot[] = [
                    'id' => $id,
                    'famille' => $famille,
                    'nom' => $this->nonVide($enregistrement['attrs']['NOM'] ?? null),
                    'superficie_ref' => isset($enregistrement['attrs']['SUPERFICIE']) && is_numeric($enregistrement['attrs']['SUPERFICIE'])
                        ? round(((float) $enregistrement['attrs']['SUPERFICIE']) / 10000.0, 4)
                        : null,
                    'srs' => $srs,
                    'wkt' => $enregistrement['wkt'],
                ];
                $origines[$id] = ['territoire' => $territoire, 'volet' => $volet];
                $nb++;
                if (count($lot) >= 50) {
                    $this->spatial->insererStaging('portail_tmp_znieff', $lot);
                    $lot = [];
                    if ($tick !== null) {
                        $tick(50);
                    }
                }
            }
        }
        if ($lot !== []) {
            $this->spatial->insererStaging('portail_tmp_znieff', $lot);
        }

        return [$nb, $origines];
    }

    // ==================================================================
    // Natura 2000
    // ==================================================================

    /**
     * @return array<string, int|bool>
     */
    public function importNatura(?string $dept = null, ?callable $tick = null, bool $avecGeojson = true): array
    {
        $base = $this->dossierNatura();
        $infos = $this->attrsNatura($base);
        if ($infos === []) {
            throw new RuntimeException('Aucun site Natura 2000 dans la base (biotop.csv.csv / info_sig.csv.csv).');
        }

        if (! $this->spatial->disponible()) {
            Log::warning('Import Natura sans PostGIS : attributs seuls (ni surfaces ni GeoJSON).');
            $this->ecrireNaturaSites($infos, array_keys($infos));
            $nbEsp = $this->importNaturaEspeces($base, array_keys($infos), $tick);
            $nbHab = $this->importNaturaHabitats($base, array_keys($infos), $tick);
            $this->registrer('natura', count($infos));

            return ['sites' => count($infos), 'especes' => $nbEsp, 'habitats' => $nbHab, 'geometries' => 0, 'intersections' => 0, 'spatial' => false];
        }

        $this->exigerReferentielCommunal();
        $this->spatial->construireCommunesTemp($dept, $tick);

        $nbGeom = $this->stagingNatura($base, $infos, $tick);
        $this->spatial->finaliserStaging('portail_tmp_natura', $tick);

        $ids = $this->perimetre('portail_tmp_natura', $dept, array_keys($infos));
        $this->ecrireNaturaSites($infos, $ids);
        $nbEsp = $this->importNaturaEspeces($base, $ids, $tick);
        $nbHab = $this->importNaturaHabitats($base, $ids, $tick);
        $croisement = $this->spatial->croiser('natura_communes', 'site_code', 'portail_tmp_natura');
        $this->spatial->reporterMesures('natura_sites', 'code', 'portail_tmp_natura');
        $this->registrer('natura', count($ids));

        $fichiersGeojson = 0;
        if ($avecGeojson) {
            foreach ($this->departsTouchees($dept) as $d) {
                $fichiersGeojson += $this->geojson->departementZones('nat', $d);
            }
            foreach ($ids as $id) {
                $this->geojson->objet('nat', $id);
                $fichiersGeojson++;
            }
            $this->geojson->manifest();
        }

        return [
            'sites' => count($ids),
            'especes' => $nbEsp,
            'habitats' => $nbHab,
            'geometries' => $nbGeom,
            'intersections' => $croisement['nb'],
            'communes_manquantes' => $croisement['communes_manquantes'],
            'geojson' => $fichiersGeojson,
            'spatial' => true,
        ];
    }

    /**
     * Espèces des formulaires standards Natura 2000 (species.csv.csv),
     * enrichies des référentiels de directives (dhff_especes : noms, annexe
     * II, prioritaire ; code_do_oiseaux : annexe I). Le FSD compte une ligne
     * par statut biologique (r/c/w/p) : on dédoublonne par (site, espèce) en
     * fusionnant les statuts dans `presences` (ex. « c,r,w »).
     *
     * @param  array<int, string>  $idsPerimetre  codes de sites écrits dans natura_sites
     * @return int nombre de lignes insérées
     */
    private function importNaturaEspeces(string $base, array $idsPerimetre, ?callable $tick): int
    {
        $file = $base.'/species.csv.csv';
        DB::table('natura_especes')->delete(); // rechargement intégral (idempotent)
        if (! is_file($file)) {
            Log::warning('species.csv.csv absent : aucune espèce Natura 2000 importée.', ['fichier' => $file]);

            return 0;
        }

        $perimetre = array_flip(array_map('strval', $idsPerimetre));
        $referentiel = $this->dhffEspeces($base) + $this->oiseauxEspeces($base);

        $count = 0;
        $chunk = [];
        $uniques = [];
        foreach ($this->csv->iterate($file) as $rec) {
            if (! empty($rec['date_supp'] ?? '')) {
                continue;
            }
            $sitecode = strtoupper(trim((string) ($rec['sitecode'] ?? '')));
            if (! isset($perimetre[$sitecode])) {
                continue;
            }
            $codeN2000 = trim((string) ($rec['code_n2000'] ?? ''));
            $cdRef = $this->intOuNull($rec['cd_ref'] ?? null);
            $key = $sitecode.'|'.$codeN2000.'|'.(string) $cdRef;
            $statut = $this->nonVide($rec['type'] ?? null);
            if (isset($uniques[$key])) {
                if ($statut !== null && ! in_array($statut, $uniques[$key]['statuts'], true)) {
                    $uniques[$key]['statuts'][] = $statut;
                }

                continue;
            }
            $uniques[$key] = [
                'row' => [
                    'sitecode' => $sitecode,
                    'code_n2000' => $codeN2000,
                    'cd_ref' => $cdRef,
                    'taxgroup' => $this->nonVide($rec['taxgroup'] ?? null),
                    'annexe_ii' => $this->nonVide($rec['annexe_ii'] ?? null),
                ],
                'statuts' => $statut !== null ? [$statut] : [],
            ];
        }

        foreach ($uniques as $u) {
            $ref = $referentiel[$u['row']['code_n2000']] ?? [];
            $annexeIi = $u['row']['annexe_ii'] ?? $ref['annexe_ii'] ?? '';
            $statuts = $u['statuts'];
            sort($statuts);
            $chunk[] = [
                'site_code' => $u['row']['sitecode'],
                'code_n2000' => $u['row']['code_n2000'],
                'cd_ref' => $u['row']['cd_ref'],
                'nom_scientifique' => $ref['nom'] ?? null,
                'nom_vernaculaire' => $ref['vern'] ?? null,
                'taxgroup' => $u['row']['taxgroup'],
                'annexe_ii' => in_array($annexeIi, ['Y', 'true'], true),
                'annexe_1' => ($ref['annexe_1'] ?? '') === 'Y',
                'prioritaire' => ($ref['prioritaire'] ?? '') === 'true',
                'presences' => $statuts !== [] ? implode(',', $statuts) : null,
            ];
            $count++;
            if (count($chunk) >= self::BATCH) {
                DB::table('natura_especes')->insert($chunk);
                $chunk = [];
                if ($tick !== null) {
                    $tick(self::BATCH);
                }
            }
        }
        if ($chunk !== []) {
            DB::table('natura_especes')->insert($chunk);
        }

        return $count;
    }

    /**
     * Habitats d'intérêt communautaire des sites (FSD habit1.csv.csv),
     * enrichis du référentiel dhff_habitats (nom français, prioritaire).
     * Rares doublons INPN dédoublonnés (première occurrence).
     *
     * @param  array<int, string>  $idsPerimetre
     * @return int nombre de lignes insérées
     */
    private function importNaturaHabitats(string $base, array $idsPerimetre, ?callable $tick): int
    {
        $file = $base.'/habit1.csv.csv';
        DB::table('natura_habitats')->delete();
        if (! is_file($file)) {
            Log::warning('habit1.csv.csv absent : aucun habitat Natura 2000 importé.', ['fichier' => $file]);

            return 0;
        }

        $perimetre = array_flip(array_map('strval', $idsPerimetre));
        $referentielHabitats = $this->dhffHabitats($base);

        $count = 0;
        $chunk = [];
        $seen = [];
        foreach ($this->csv->iterate($file) as $rec) {
            if (! empty($rec['date_supp'] ?? '')) {
                continue;
            }
            $sitecode = strtoupper(trim((string) ($rec['sitecode'] ?? '')));
            if (! isset($perimetre[$sitecode])) {
                continue;
            }
            $key = $sitecode.'|'.($rec['cd_ue'] ?? '').'|'.($rec['cd_hab'] ?? '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $refHab = $referentielHabitats[$rec['cd_ue'] ?? ''] ?? [];
            $chunk[] = [
                'site_code' => $sitecode,
                'cd_ue' => (string) ($rec['cd_ue'] ?? ''),
                'nom' => $refHab['nom'] ?? null,
                'prioritaire' => $refHab['prioritaire'] ?? false,
                'cd_hab' => $this->nonVide($rec['cd_hab'] ?? null),
                'cover' => $this->decimal($rec['cover'] ?? null),
                'area' => $this->decimal($rec['area'] ?? null),
                'representativite' => $this->char1($rec['represent'] ?? null),
                // conserve = structure et fonctions (A/B/C) ; l'évaluation
                // GLOBALE (champ « global ») est distincte.
                'conservation' => $this->char1($rec['conserve'] ?? null),
                'evaluation_globale' => $this->char1($rec['global'] ?? null),
            ];
            $count++;
            if (count($chunk) >= self::BATCH) {
                DB::table('natura_habitats')->insert($chunk);
                $chunk = [];
                if ($tick !== null) {
                    $tick(self::BATCH);
                }
            }
        }
        if ($chunk !== []) {
            DB::table('natura_habitats')->insert($chunk);
        }

        return $count;
    }

    /**
     * Référentiel de la directive Habitats (dhff_especes.csv.csv) :
     * code N2000 → noms, annexe II, prioritaire.
     *
     * @return array<string, array{nom: string, vern: string, annexe_ii: string, prioritaire: string}>
     */
    private function dhffEspeces(string $base): array
    {
        $file = $base.'/dhff_especes.csv.csv';
        if (! is_file($file)) {
            return []; // absent des extractions plus anciennes
        }
        $ref = [];
        foreach ($this->csv->iterate($file) as $rec) {
            $code = trim((string) ($rec['code_n2000'] ?? ''));
            if ($code === '') {
                continue;
            }
            $ref[$code] = [
                'nom' => (string) ($rec['lb_nom'] ?? ''),
                'vern' => (string) ($rec['nom_vern'] ?? ''),
                'annexe_ii' => (string) ($rec['annexe_ii'] ?? ''),
                'prioritaire' => (string) ($rec['prioritaire'] ?? ''),
            ];
        }

        return $ref;
    }

    /**
     * Référentiel de la directive Oiseaux (code_do_oiseaux.csv.csv) : les
     * codes « A### » des formulaires (ZPS) n'existent pas dans le
     * référentiel Habitats.
     *
     * @return array<string, array{nom: string, vern: string, annexe_1: string}>
     */
    private function oiseauxEspeces(string $base): array
    {
        $file = $base.'/code_do_oiseaux.csv.csv';
        if (! is_file($file)) {
            return [];
        }
        $ref = [];
        foreach ($this->csv->iterate($file) as $rec) {
            $code = trim((string) ($rec['code_n2000'] ?? ''));
            if ($code === '') {
                continue;
            }
            $ref[$code] = [
                'nom' => (string) ($rec['nom_n2000'] ?? $rec['lb_nom'] ?? ''),
                'vern' => (string) ($rec['nom_vern'] ?? ''),
                'annexe_1' => (string) ($rec['annexe_i'] ?? ''),
            ];
        }

        return $ref;
    }

    /**
     * Référentiel de la directive Habitats (dhff_habitats.csv.csv) : code
     * EUR → nom français (annexe I) et caractère prioritaire.
     *
     * @return array<string, array{nom: string, prioritaire: bool}>
     */
    private function dhffHabitats(string $base): array
    {
        $file = $base.'/dhff_habitats.csv.csv';
        if (! is_file($file)) {
            return [];
        }
        $ref = [];
        foreach ($this->csv->iterate($file) as $rec) {
            $code = trim((string) ($rec['cd_ue'] ?? ''));
            if ($code === '' || isset($ref[$code])) {
                continue;
            }
            $ref[$code] = [
                'nom' => (string) ($rec['lb_habdh_fr'] ?? $rec['hdname'] ?? ''),
                'prioritaire' => ($rec['prioritaire'] ?? '') === 'true',
            ];
        }

        return $ref;
    }

    /**
     * Sites Natura 2000 (base INPN 12/2024) : registre biotop (noms,
     * superficies officielles FSD, dates de désignation) + info_sig
     * (type de couche SIC/ZPS, échelle de saisie, maj géométrique).
     *
     * @return array<string, array{type: ?string, nom: ?string, superficie_ref_ha: ?float, date_designation: ?string, date_modif_geo: ?string, precision_saisie: ?string}>
     */
    private function attrsNatura(string $base): array
    {
        $infos = [];

        foreach ($this->csv->iterate($base.'/biotop.csv.csv') as $rec) {
            $code = strtoupper(trim((string) ($rec['sitecode'] ?? '')));
            if ($code === '') {
                continue;
            }
            $infos[$code] = [
                'type' => $this->nonVide($rec['type'] ?? null),
                'nom' => $this->nonVide($rec['site_name'] ?? null),
                'superficie_ref_ha' => $this->decimal($rec['area'] ?? null),
                'date_designation' => $this->dateIso($rec['date_con'] ?? null),
                'date_modif_geo' => null,
                'precision_saisie' => null,
            ];
        }

        foreach ($this->csv->iterate($base.'/info_sig.csv.csv') as $rec) {
            $code = strtoupper(trim((string) ($rec['id'] ?? '')));
            if ($code === '') {
                continue;
            }
            if (! isset($infos[$code])) {
                $infos[$code] = [
                    'type' => null, 'nom' => null, 'superficie_ref_ha' => null,
                    'date_designation' => null, 'date_modif_geo' => null, 'precision_saisie' => null,
                ];
            }
            if ($infos[$code]['type'] === null) {
                $infos[$code]['type'] = $this->nonVide($rec['lb_couche'] ?? null);
            }
            $infos[$code]['date_modif_geo'] = $this->dateMoisAnnee($rec['date_dern_modif'] ?? null);
            $infos[$code]['precision_saisie'] = $this->echelleEnPrec($rec['echelle'] ?? null);
        }

        return $infos;
    }

    /**
     * Shapefile Natura : cd_sig ('I098FR5312003' = 4 caractères de préfixe
     * + code du site).
     */
    private function stagingNatura(string $base, array $infos, ?callable $tick): int
    {
        $shapes = glob($base.'/SIG_NATURA/*.shp') ?: [];
        if ($shapes === []) {
            throw new RuntimeException('Shapefile Natura introuvable (SIG_NATURA/*.shp).');
        }

        $this->spatial->creerStaging('portail_tmp_natura');
        $nb = 0;
        $lot = [];
        foreach ($shapes as $file) {
            $reader = new ShapefileReader($file);
            $srs = $this->srsDetecte($reader);
            foreach ($reader->records() as $enregistrement) {
                $cdSig = trim($enregistrement['attrs']['cd_sig'] ?? '');
                if (strlen($cdSig) < 5 || $enregistrement['wkt'] === null) {
                    continue;
                }
                $code = strtoupper(substr($cdSig, 4));
                $lot[] = [
                    'id' => $code,
                    'famille' => 'nat',
                    'nom' => $infos[$code]['nom'] ?? null,
                    'superficie_ref' => $infos[$code]['superficie_ref_ha'] ?? null,
                    'srs' => $srs,
                    'wkt' => $enregistrement['wkt'],
                ];
                $nb++;
                if (count($lot) >= 50) {
                    $this->spatial->insererStaging('portail_tmp_natura', $lot);
                    $lot = [];
                    if ($tick !== null) {
                        $tick(50);
                    }
                }
            }
        }
        if ($lot !== []) {
            $this->spatial->insererStaging('portail_tmp_natura', $lot);
        }

        return $nb;
    }

    // ==================================================================
    // Aires protégées (catalogue APA-INPN, GeoPackage)
    // ==================================================================

    /**
     * @return array<string, int|bool>
     */
    public function importAires(?string $dept = null, ?callable $tick = null, bool $avecGeojson = true): array
    {
        $dir = $this->dir();
        $gpkgFiles = glob($dir.'/Espaces Protégés/*.gpkg') ?: [];
        if ($gpkgFiles === []) {
            throw new RuntimeException('Aucun GeoPackage d\'aires protégées (Espaces Protégés/*.gpkg).');
        }

        // 1. Lecture attributs + géométries de tous les GPKG (PDO sqlite),
        //    déversées au fil de l'eau dans la table de staging par lots de
        //    50 (sinon sig_metrop.gpkg — 148 Mo de géométries, doublées en
        //    hexadécimal — explose la limite mémoire avant toute insertion).
        $seen = [];
        $ignores = 0;
        /** @var array<int, array{id: string, famille: string, nom: ?string, superficie_ref: ?float, srs: int, wkb_hex: string}> $chunk */
        $chunk = [];
        $nbGeom = 0;

        if (! $this->spatial->disponible()) {
            Log::warning('Import des aires protégées sans PostGIS : attributs seuls (ni surfaces ni GeoJSON).');
            // Attributs seuls : les géométries lues sont simplement écartées.
            $jeter = function () use (&$chunk): void {
                $chunk = [];
            };
            foreach ($gpkgFiles as $gpkg) {
                $this->lireGpkgApa($gpkg, $seen, $ignores, $chunk, $jeter);
            }
            $ids = array_map('strval', array_keys($seen));
            $this->ecrireAires($seen, $ids);
            $this->registrer('aires', count($ids));

            return ['aires' => count($ids), 'geometries' => 0, 'intersections' => 0, 'spatial' => false];
        }

        $this->exigerReferentielCommunal();
        $this->spatial->construireCommunesTemp($dept, $tick);

        $this->spatial->creerStaging('portail_tmp_apa');
        $deverser = function () use (&$chunk, &$nbGeom): void {
            if ($chunk !== []) {
                $this->spatial->insererStagingWkb('portail_tmp_apa', $chunk);
                $nbGeom += count($chunk);
                $chunk = [];
            }
        };
        foreach ($gpkgFiles as $gpkg) {
            $this->lireGpkgApa($gpkg, $seen, $ignores, $chunk, $deverser);
        }
        $deverser();
        $this->spatial->finaliserStaging('portail_tmp_apa', $tick);

        $ids = $this->perimetre('portail_tmp_apa', $dept, array_map('strval', array_keys($seen)));
        $this->ecrireAires($seen, $ids);
        $croisement = $this->spatial->croiser('ap_communes', 'ap_id', 'portail_tmp_apa');
        $this->spatial->reporterMesures('aires_protegees', 'id', 'portail_tmp_apa');
        $this->registrer('aires', count($ids));

        $fichiersGeojson = 0;
        if ($avecGeojson) {
            foreach ($this->departsTouchees($dept) as $d) {
                $fichiersGeojson += $this->geojson->departementZones('ap', $d);
            }
            foreach ($ids as $id) {
                $this->geojson->objet('ap', $id);
                $fichiersGeojson++;
            }
            $this->geojson->manifest();
        }

        return [
            'aires' => count($ids),
            'geometries' => $nbGeom,
            'intersections' => $croisement['nb'],
            'communes_manquantes' => $croisement['communes_manquantes'],
            'geojson' => $fichiersGeojson,
            'ignores' => $ignores,
            'spatial' => true,
        ];
    }

    /**
     * Lit un GPKG APA : attributs (les GPK INPN sont encodés cp1252 —
     * leçon de l'ABC) et géométries (blob GP → WKB hexadécimal). Les
     * géométries sont déversées par le callback $deverser au fil de
     * l'eau (pas d'accumulation nationale en mémoire).
     *
     * @param  array<string, array<string, mixed>>  $seen
     * @param  array<int, array{id: string, famille: string, nom: ?string, superficie_ref: ?float, srs: int, wkb_hex: string}>  $chunk
     */
    private function lireGpkgApa(string $gpkg, array &$seen, int &$ignores, array &$chunk, callable $deverser): void
    {
        $territoire = (string) preg_replace('/^sig_|\.gpkg$/i', '', strtolower(basename($gpkg)));
        $pdo = new PDO('sqlite:'.$gpkg);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $layers = $pdo->query(
            'SELECT c.table_name, g.column_name, g.srs_id'
            .' FROM gpkg_contents c JOIN gpkg_geometry_columns g ON g.table_name = c.table_name'
            ." WHERE c.data_type = 'features'"
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($layers as $layer) {
            $select = $pdo->prepare(
                'SELECT id_mnhn, nom, type_espace, objectif_protection, statut,'
                .' superficie_ha, cd_type_milieu, lien_fiche, doc_gestion,'
                .' date_crea_sign, date_modif_geo, "'.$layer['column_name'].'" AS geom'
                .' FROM "'.$layer['table_name'].'"'
            );
            $select->execute();
            while ($row = $select->fetch(PDO::FETCH_ASSOC)) {
                $id = $this->utf8((string) ($row['id_mnhn'] ?? ''));
                if ($id === '' || isset($seen[$id])) {
                    $ignores++; // id_mnhn dupliqué entre territoires

                    continue;
                }

                $libelleType = $this->utf8((string) ($row['type_espace'] ?? ''));
                $type = $this->typeApa($libelleType);
                $nom = $this->utf8((string) ($row['nom'] ?? ''));

                $seen[$id] = [
                    'type_code' => $type['code'],
                    'type_nom' => $libelleType !== '' ? $libelleType : $type['libelle'],
                    'nom' => $nom,
                    'superficie_ref_ha' => $this->decimal($this->utf8((string) ($row['superficie_ha'] ?? ''))),
                    'acte' => null,
                    'gestionnaire' => null,
                    'objectif_protection' => $this->nonVide($this->utf8((string) ($row['objectif_protection'] ?? ''))),
                    'doc_gestion' => strtolower($this->utf8((string) ($row['doc_gestion'] ?? ''))) === 'true',
                    'date_crea_sign' => $this->dateIso($this->utf8((string) ($row['date_crea_sign'] ?? ''))),
                    'date_modif_geo' => $this->dateIso($this->utf8((string) ($row['date_modif_geo'] ?? ''))),
                    'territoire' => $territoire,
                    'url_inpn' => $this->nonVide($this->utf8((string) ($row['lien_fiche'] ?? '')))
                        ?? rtrim((string) config('portail.urls.ap'), '/').'/'.$id,
                ];

                $wkb = $this->gpkgWkb((string) ($row['geom'] ?? ''));
                if ($wkb !== '') {
                    $chunk[] = [
                        'id' => $id,
                        'famille' => 'ap',
                        'nom' => $nom,
                        'superficie_ref' => $seen[$id]['superficie_ref_ha'],
                        'srs' => (int) $layer['srs_id'],
                        'wkb_hex' => bin2hex($wkb),
                    ];
                    if (count($chunk) >= 50) {
                        $deverser();
                    }
                }
            }
        }
    }

    /**
     * Code court d'un type d'aire protégée d'après son libellé INPN
     * (mapping config portail.ap_types, l'ordre des mots-clés compte).
     *
     * @return array{code: string, libelle: string}
     */
    private function typeApa(string $libelle): array
    {
        $normalise = mb_strtolower($libelle);
        foreach ((array) config('portail.ap_types') as $regle) {
            if (str_contains($normalise, $regle['motcle'])) {
                return ['code' => $regle['code'], 'libelle' => $regle['libelle']];
            }
        }
        if ($libelle !== '') {
            Log::info('Type d\'aire protégée non reconnu (code « AP » conservé).', ['type_espace' => $libelle]);
        }

        return ['code' => 'AP', 'libelle' => $libelle];
    }

    // ==================================================================
    // Écritures définitives
    // ==================================================================

    /**
     * @param  array<string, array{famille: string, nom: string, num_regional: ?string, superficie_ref_ha: ?float, date_crea: ?string, date_modif_geo: ?string, parent: ?string, volet: ?string}>  $attrs
     * @param  array<int, string>  $ids
     * @param  array<string, array{territoire: string, volet: string}>  $origines
     */
    private function ecrireZonages(array $attrs, array $ids, array $origines): void
    {
        DB::table('zonages')->delete(); // cascade pivots : rechargement intégral
        $inscrits = array_flip($ids);
        // Rattachement en 2ᵉ passe : l'insert massif ne garantit pas que la
        // ZNIEFF II parente précède ses enfants (contrainte FK row par row).
        // En mode --dept, les parents hors périmètre sont neutralisés (null).
        $rattachements = [];
        $chunk = [];
        foreach ($ids as $id) {
            $a = $attrs[$id];
            $parent = $a['parent'];
            if ($parent !== null && isset($inscrits[$parent])) {
                $rattachements[$id] = $parent;
            }
            $chunk[] = [
                'id' => $id,
                'num_regional' => $a['num_regional'],
                'famille' => $a['famille'],
                'id_parent' => null,
                'nom' => $a['nom'],
                'superficie_ha' => null, // mesurée depuis la géométrie juste après
                'superficie_ref_ha' => $a['superficie_ref_ha'],
                'precision_saisie' => '1/25 000',
                'date_crea' => $a['date_crea'],
                'date_modif_geo' => $a['date_modif_geo'],
                'territoire' => $origines[$id]['territoire'] ?? 'metrop',
                'volet' => $origines[$id]['volet'] ?? $a['volet'],
                'url_inpn' => rtrim((string) config('portail.urls.znieff'), '/').'/'.$id,
            ];
            if (count($chunk) >= 500) {
                DB::table('zonages')->insert($chunk);
                $chunk = [];
            }
        }
        if ($chunk !== []) {
            DB::table('zonages')->insert($chunk);
        }
        foreach ($rattachements as $id => $parent) {
            DB::table('zonages')->where('id', $id)->update(['id_parent' => $parent]);
        }
    }

    /**
     * @param  array<string, array{type: ?string, nom: ?string, superficie_ref_ha: ?float, date_designation: ?string, date_modif_geo: ?string, precision_saisie: ?string}>  $infos
     * @param  array<int, string>  $ids
     */
    private function ecrireNaturaSites(array $infos, array $ids): void
    {
        DB::table('natura_sites')->delete();
        $chunk = [];
        foreach ($ids as $id) {
            $a = $infos[$id];
            $chunk[] = [
                'code' => $id,
                'type' => $a['type'] ?? 'SIC',
                'nom' => $a['nom'],
                'superficie_ha' => null,
                'superficie_ref_ha' => $a['superficie_ref_ha'],
                'precision_saisie' => $a['precision_saisie'] ?? '1/25 000',
                'date_designation' => $a['date_designation'],
                'date_modif_geo' => $a['date_modif_geo'],
                'territoire' => 'metrop',
                'url_inpn' => rtrim((string) config('portail.urls.natura'), '/').'/'.$id,
            ];
            if (count($chunk) >= 500) {
                DB::table('natura_sites')->insert($chunk);
                $chunk = [];
            }
        }
        if ($chunk !== []) {
            DB::table('natura_sites')->insert($chunk);
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $seen
     * @param  array<int, string>  $ids
     */
    private function ecrireAires(array $seen, array $ids): void
    {
        DB::table('aires_protegees')->delete();
        $chunk = [];
        foreach ($ids as $id) {
            $chunk[] = array_merge(['id' => $id], $seen[$id]);
            if (count($chunk) >= 500) {
                DB::table('aires_protegees')->insert($chunk);
                $chunk = [];
            }
        }
        if ($chunk !== []) {
            DB::table('aires_protegees')->insert($chunk);
        }
    }

    // ==================================================================
    // Helpers communs
    // ==================================================================

    /**
     * Périmètre à écrire : en mode départemental, les zones intersectant
     * les communes du département (la table temporaire des communes est
     * déjà filtrée) ; sinon tout le staging — restreint aux identifiants
     * connus du référentiel.
     *
     * @param  array<int, string>  $idsConnus
     * @return array<int, string>
     */
    private function perimetre(string $staging, ?string $dept, array $idsConnus): array
    {
        $connus = array_flip($idsConnus);
        if ($dept === null) {
            $ids = array_map('strval', DB::table($staging)->pluck('id')->unique()->values()->all());
        } else {
            $ids = collect(DB::select(
                "SELECT DISTINCT z.id AS id FROM {$staging} z"
                .' JOIN portail_tmp_communes c ON ST_Intersects(z.geom, c.geom2154)'
            ))->map(fn ($row): string => (string) $row->id)->all();
        }

        return array_values(array_filter($ids, fn (string $id): bool => isset($connus[$id])));
    }

    /**
     * Départements touchés par l'import courant : le paramètre --dept, ou
     * tous les départements du référentiel (mode France entière).
     *
     * @return array<int, string>
     */
    private function departsTouchees(?string $dept): array
    {
        if ($dept !== null) {
            return [$dept];
        }

        $depts = DB::table('communes')->distinct()->pluck('dept')->all();
        sort($depts);

        return $depts;
    }

    /** SRS du shapefile : bbox en degrés (≤ 360) → WGS84, sinon Lambert-93. */
    private function srsDetecte(ShapefileReader $reader): int
    {
        $bbox = $reader->bbox();

        return (abs($bbox['minx']) <= 360 && abs($bbox['maxx']) <= 360) ? 4326 : 2154;
    }

    /**
     * Territoire d'après le nom de fichier shapefile ZNIEFF :
     * ZNIEFF1_G2 → metrop ; GLP_ZNIEFF1_G2 → glp ; REU_ZNIEFF1_MER → reu.
     */
    private function territoireDeFichier(string $base): string
    {
        $prefixe = strtoupper(explode('_', $base)[0]);

        return in_array($prefixe, self::TERRITOIRES, true) ? strtolower($prefixe) : 'metrop';
    }

    private function dossierNatura(): string
    {
        foreach (glob($this->dir().'/NATURA_BDD_*') ?: [] as $dossier) {
            if (is_dir($dossier)) {
                return $dossier;
            }
        }
        throw new RuntimeException('Dossier Natura 2000 (NATURA_BDD_*) introuvable dans le dossier INPN.');
    }

    private function exigerReferentielCommunal(): void
    {
        if ((int) DB::table('communes')->count() === 0) {
            throw new RuntimeException('Référentiel communal vide : lancez d\'abord `php artisan portail:refresh-communes`.');
        }
    }

    private function registrer(string $source, int $nbEntites): void
    {
        ReferentielImport::updateOrCreate(
            ['source' => $source],
            [
                'version_source' => (string) config("portail.inpn.versions.{$source}"),
                'nb_entites' => $nbEntites,
                'importe_le' => now(),
            ],
        );
    }

    private function dir(): string
    {
        $dir = (string) config('portail.inpn.dir');
        if ($dir === '' || ! is_dir($dir)) {
            throw new RuntimeException('Dossier des données INPN introuvable (PORTAIL_INPN_DIR / config portail.inpn.dir).');
        }

        return $dir;
    }

    /**
     * Extrait le WKB d'un blob binaire GeoPackage (en-tête GP + srs +
     * éventuelle enveloppe), à donner à ST_GeomFromWKB (reprise ABC).
     */
    private function gpkgWkb(string $blob): string
    {
        if (strlen($blob) < 8 || ! str_starts_with($blob, 'GP')) {
            return '';
        }
        $flags = ord($blob[3]);
        $envelope = ($flags >> 1) & 0x07;
        $envelopeBytes = match ($envelope) {
            1 => 32,
            2, 3 => 48,
            4 => 64,
            default => 0,
        };

        return substr($blob, 8 + $envelopeBytes);
    }

    private function utf8(string $value): string
    {
        if ($value !== '' && ! mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
        }

        return $value;
    }

    private function nonVide(?string $value): ?string
    {
        $v = trim((string) $value);

        return $v === '' ? null : $v;
    }

    private function intOuNull(mixed $value): ?int
    {
        $v = trim((string) $value);

        return $v !== '' && is_numeric($v) ? (int) $v : null;
    }

    /** Notation FSD A-D (representativite, conservation, globale) → 1 caractère. */
    private function char1(?string $value): ?string
    {
        $v = strtoupper(substr(trim((string) $value), 0, 1));

        return in_array($v, ['A', 'B', 'C', 'D'], true) ? $v : null;
    }

    private function decimal(?string $value): ?float
    {
        $v = str_replace(',', '.', trim((string) $value));
        if ($v === '' || ! is_numeric($v)) {
            return null;
        }

        return round((float) $v, 4);
    }

    /** Dates INPN : 19820101000000 (ZNIEFF) ou 2016-11-15 (GPKG) → Y-m-d. */
    private function dateInpn(?string $value): ?string
    {
        $v = trim((string) $value);
        if ($v === '') {
            return null;
        }
        if (preg_match('/^(\d{4})(\d{2})(\d{2})/', $v, $m)) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $m[1].'-'.$m[2].'-'.$m[3] : null;
        }

        return $this->dateIso($v);
    }

    private function dateIso(?string $value): ?string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', trim((string) $value), $m)) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $m[1].'-'.$m[2].'-'.$m[3] : null;
        }

        return null;
    }

    /** « 07/2024 » (info_sig Natura) → « 2024-07-01 ». */
    private function dateMoisAnnee(?string $value): ?string
    {
        if (preg_match('#^(\d{2})/(\d{4})$#', trim((string) $value), $m)) {
            $mois = min(max((int) $m[1], 1), 12);

            return $m[2].'-'.sprintf('%02d', $mois).'-01';
        }

        return $this->dateIso($value);
    }

    /** Échelle de saisie « 25000 » → « 1/25 000 ». */
    private function echelleEnPrec(?string $valeur): ?string
    {
        $v = trim((string) $valeur);
        if ($v === '' || ! is_numeric($v)) {
            return null;
        }
        $n = (int) $v;
        if (in_array($n, [25000, 50000, 100000], true)) {
            return '1/'.number_format((float) $n, 0, ',', ' ');
        }

        return '1/'.number_format((float) $n, 0, ',', ' ');
    }
}
