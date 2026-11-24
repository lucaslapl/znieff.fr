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
     * Noms vernaculaires TAXREF joints quand le fichier est présent
     * (colonne nom_vernaculaire) ; le latin seul sinon.
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

        // Noms vernaculaires TAXREF : première lecture en flux des cd_ref
        // DISTINCTS du périmètre (petit ensemble), puis filtrage de TAXREF
        // sur cet ensemble — jamais la distribution complète (TAXREFv18 :
        // 317 Mo) en mémoire. Fichier absent : avertissement, latin seul.
        $vern = $this->nomsVernaculairesTaxref(
            $this->cdRefsDuPerimetre($fichier, $perimetre, $versNational),
        );

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
                'nom_vernaculaire' => $vern[trim((string) ($rec['cd_ref'] ?? ''))] ?? null,
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
     * cd_ref DISTINCTS (texte) cités par le périmètre dans REF_ESPECE.csv :
     * première lecture en flux, servant seulement au filtrage de TAXREF
     * (la carte des noms vernaculaires reste de quelques milliers d'entrées,
     * jamais la distribution complète en mémoire).
     *
     * @param  array<string, int>  $perimetre  NM_SFFZN → true (array_flip)
     * @param  array<string, string>  $versNational  repli ID_ZNIEFF → NM_SFFZN
     * @return array<string, true>
     */
    private function cdRefsDuPerimetre(string $fichier, array $perimetre, array $versNational): array
    {
        $cdRefs = [];
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
            $cd = trim((string) ($rec['cd_ref'] ?? ''));
            if ($cd !== '') {
                $cdRefs[$cd] = true;
            }
        }

        return $cdRefs;
    }

    /**
     * Noms vernaculaires TAXREF (MNHN) : lecture en flux (distribution
     * séparée, conditions INPN à accepter — cf. config portail.inpn).
     * Préféré : le fichier dédicacé TAXVERNv*.txt (léger, fr + eng
     * mélangés — filtrés sur ISO639_3 = fra / LANGUE = français ;
     * première langue gardée par cd_nom, l'anglais ne prime jamais et
     * la variante régionale qui suit non plus) ; repli : NOM_VERN de
     * TAXREFv*.txt / TAXREF.txt. Les en-têtes changent d'orthographe
     * selon la version (CD_NOM v11+ / cdNom v17+) — iterate() bascule
     * déjà tout en minuscules et colonne() tente les orthographes.
     * Aucun fichier trouvé (jamais téléchargé) : avertissement, carte
     * vide — les fiches ZNIEFF retombent sur le nom latin.
     *
     * @param  array<string, true>  $cdRefs
     * @return array<string, string>
     */
    private function nomsVernaculairesTaxref(array $cdRefs): array
    {
        $fichier = $this->fichierTaxref();
        if ($fichier === null) {
            Log::info('TAXREF absent : nom_vernaculaire laissé null (nom latin seul).', [
                'fichier_explicite' => (string) config('portail.inpn.taxref_fichier', ''),
                'dossier' => (string) config('portail.inpn.taxref_dossier', ''),
            ]);

            return [];
        }

        $taxvern = $this->estTaxvern($fichier);

        $vern = [];
        foreach ($this->csv->iterate($fichier, "\t") as $rec) {
            // TAXVERN est indexé par CD_NOM (taxon accepté), TAXREF par CD_REF.
            $cd = $this->colonne($rec, $taxvern ? ['cd_nom', 'cdnom'] : ['cd_ref', 'cdref']);
            if ($cd === null || ($cdRefs !== [] && ! isset($cdRefs[$cd]))) {
                continue;
            }
            $nomVern = $taxvern ? $this->vernFrancais($rec) : $this->colonne($rec, ['nom_vern', 'nomvern', 'nom_vernaculaire']);
            if ($nomVern === null) {
                continue;
            }
            if ($taxvern) {
                // Le français prime sur l'anglais, quelle que soit l'ordre des
                // lignes ; le français qui suit (variante régionale) ne prime
                // pas non plus (première langue gardée, jamais de tri).
                if (! isset($vern[$cd]) || $this->français($rec)) {
                    $vern[$cd] = $nomVern;
                }

                continue;
            }
            $vern[$cd] ??= $nomVern; // première occurrence gardée (le synonyme qui suit ne prime pas)
        }

        $this->registrer('taxref', count($vern), $this->versionTaxref($fichier));

        return $vern;
    }

    /**
     * Fichier TAXREF utilisé : PORTAIL_TAXREF_FICHIER (explicite), sinon
     * candidats des dossiers essayés par priorité — le dossier configuré
     * (PORTAIL_TAXREF_DIR / portail.inpn.taxref_dossier), puis
     * <inpn.dir>/TAXREF et <inpn.dir>/taxref (le conteneur Linux est
     * case-sensitive, le poste Windows local non) — dans l'ordre :
     * TAXVERNv*.txt d'abord (léger, langues mélangées), puis TAXREFv*.txt
     * et TAXREF.txt (NOM_VERN). glob() est trié alphanumériquement :
     * v18 avant v17, la distribution la plus récente d'abord.
     */
    private function fichierTaxref(): ?string
    {
        $explicite = (string) config('portail.inpn.taxref_fichier', '');
        if ($explicite !== '') {
            return is_file($explicite) ? $explicite : null;
        }

        foreach ($this->dossiersTaxref() as $dossier) {
            foreach (['TAXVERNv*.txt', 'TAXREFv*.txt', 'TAXREF.txt'] as $motif) {
                $candidats = glob($dossier.'/'.$motif) ?: [];
                if ($candidats !== []) {
                    return $candidats[0];
                }
            }
        }

        return null;
    }

    /**
     * Dossiers candidats pour TAXREF : dossier configuré
     * (PORTAIL_TAXREF_DIR / portail.inpn.taxref_dossier), puis
     * <inpn.dir>/TAXREF et <inpn.dir>/taxref (le conteneur Linux est
     * case-sensitive, le poste Windows local non).
     *
     * @return array<int, string>
     */
    private function dossiersTaxref(): array
    {
        $dossiers = [(string) config('portail.inpn.taxref_dossier', '')];
        try {
            $dossiers[] = $this->dir().'/TAXREF';
            $dossiers[] = $this->dir().'/taxref';
        } catch (RuntimeException) {
            // dossier INPN non monté : on se contente du dossier configuré
        }

        return array_values(array_unique(array_filter($dossiers)));
    }

    /**
     * Fichier TAXREF complet (tabulé, tous taxons) pour l'extraction des
     * fiches taxons du périmètre : TAXREFv*.txt, repli TAXREF.txt.
     */
    private function fichierTaxrefComplet(): ?string
    {
        foreach ($this->dossiersTaxref() as $dossier) {
            foreach (['TAXREFv*.txt', 'TAXREF.txt'] as $motif) {
                $candidats = glob($dossier.'/'.$motif) ?: [];
                if ($candidats !== []) {
                    return $candidats[0];
                }
            }
        }

        return null;
    }

    /** TAXVERNv*.txt : le fichier dédicacé aux noms vernaculaires. */
    private function estTaxvern(string $fichier): bool
    {
        return str_starts_with(basename($fichier), 'TAXVERN');
    }

    /**
     * Nom vernaculaire de la ligne : LB_VERN seulement si la langue est le
     * français (LANGUE = français / ISO639_3 = fra) — l'anglais ne doit
     * jamais sortir sur une fiche ZNIEFF.
     *
     * @param  array<string, string>  $rec
     */
    private function vernFrancais(array $rec): ?string
    {
        $langue = $this->colonne($rec, ['langue']);
        $iso = $this->colonne($rec, ['iso639_3', 'iso6393']);
        if ($langue === null && $iso === null) {
            return null; // ligne sans langue ni ISO639_3 : jamais deviné
        }
        $français = ($langue !== null && mb_strtolower($langue) === 'français') || $iso === 'fra';

        return $français ? $this->colonne($rec, ['lb_vern', 'lbvern']) : null;
    }

    /** La ligne est-elle en français (LANGUE / ISO639_3) ? */
    private function français(array $rec): bool
    {
        return $this->vernFrancais($rec) !== null;
    }

    /**
     * Version créditée dans le journal des imports : suffixe lu dans le
     * nom du fichier (TAXREFv18.txt → « v18 ») ; orthographe inconnue :
     * version configurée statique (config portail.inpn.versions.taxref).
     */
    private function versionTaxref(string $fichier): ?string
    {
        if (preg_match('/^(?:TAXREF|TAXVERN)v([0-9A-Za-z]+)\.txt$/i', basename($fichier), $m) !== 1) {
            return null;
        }

        return (string) config('portail.inpn.versions.taxref').' — v'.$m[1];
    }

    /**
     * Première colonne d'en-tête reconnue, non vide, en texte pur —
     * l'en-tête de TAXREF change d'orthographe selon la version.
     *
     * @param  array<string, string>  $rec
     * @param  array<int, string>  $candidats
     */
    private function colonne(array $rec, array $candidats): ?string
    {
        foreach ($candidats as $c) {
            $v = isset($rec[$c]) ? trim((string) $rec[$c]) : '';
            if ($v !== '') {
                return $v;
            }
        }

        return null;
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
    // Référentiel de sensibilité SINP v18 (INPN)
    // ==================================================================

    /**
     * Import du référentiel de sensibilité (SENSIBILITE_18/) : une ligne par
     * taxon × département × niveau de sensibilité, compilé des listes
     * régionales + pré-liste nationale (CD_DEPT vide = national).
     *
     * La table ne sert JAMAIS à afficher une sensibilité : elle alimente
     * uniquement le filtre « espèce jamais affichée ni comptée » posé côté
     * requête dans App\Support\Especes (règle absolue AGENTS.md, renforcée
     * par rapport au seul flag FG_CONF de REF_ESPECE.csv).
     *
     * Idempotent : rechargement intégral des deux tables (24 k lignes, lu en
     * flux — jamais de lecture complète en mémoire). Indépendant du
     * périmètre --dept : la sensibilité est un référentiel national.
     *
     * @param  callable(int): void  $tick
     * @return array{lignes: int, taxons: int, mtd: int}
     */
    public function importSensibilite(?callable $tick = null): array
    {
        $dir = $this->dossierSensibilite();
        $fichierMtd = $dir.'/RefSensibiliteMtd.csv';
        $fichier = $dir.'/RefSensibilite_18.csv';
        if (! is_file($fichier)) {
            // Tolérance au numéro de version : RefSensibilite_*.csv le plus récent.
            foreach (glob($dir.'/RefSensibilite_*.csv') ?: [] as $candidat) {
                if (preg_match('/RefSensibilite_\d+\.csv$/i', $candidat) === 1) {
                    $fichier = $candidat;
                    break;
                }
            }
        }
        if (! is_file($fichier)) {
            throw new RuntimeException("Référentiel de sensibilité introuvable : {$dir}/RefSensibilite_*.csv");
        }

        $nbMtd = $this->importSensibiliteMtd($fichierMtd);

        DB::table('sensibilite_especes')->delete(); // rechargement intégral (idempotent)
        $chunk = [];
        $nb = 0;
        $taxons = [];
        foreach ($this->csv->iterate($fichier, ',') as $rec) {
            $cdRef = $this->intOuNull($rec['cd_ref'] ?? null);
            if ($cdRef === null) {
                continue;
            }
            $taxons[$cdRef] = true;
            $chunk[] = [
                'cd_nom' => $this->intOuNull($rec['cd_nom'] ?? null),
                'cd_ref' => $cdRef,
                'cd_sensibilite' => (int) ($this->intOuNull($rec['cd_sensibilite'] ?? null) ?? 0),
                'duree' => $this->intOuNull($rec['duree'] ?? null),
                'cd_dept' => $this->nonVide($this->utf8((string) ($rec['cd_dept'] ?? ''))),
                'statut_biologique' => $this->intOuNull($rec['statut_biologique'] ?? null),
                'comportement' => $this->intOuNull($rec['comportement'] ?? null),
                'autre' => $this->nonVide($this->utf8((string) ($rec['autre'] ?? ''))),
                'cd_sens_mtd' => $this->intOuNull($rec['cd_sens_mtd'] ?? null),
                'created_at' => now(),
                'updated_at' => now(),
            ];
            if (count($chunk) >= self::BATCH) {
                DB::table('sensibilite_especes')->insert($chunk);
                $nb += count($chunk);
                $chunk = [];
                if ($tick !== null) {
                    $tick($nb);
                }
            }
        }
        if ($chunk !== []) {
            DB::table('sensibilite_especes')->insert($chunk);
            $nb += count($chunk);
        }

        $this->registrer('sensibilite', $nb, $this->versionSensibilite($fichier));

        return ['lignes' => $nb, 'taxons' => count($taxons), 'mtd' => $nbMtd];
    }

    /**
     * Méthodes de sensibilité (RefSensibiliteMtd.csv, séparateur « ; »,
     * encodage Windows-1252 possible) : crédit des listes régionales.
     */
    private function importSensibiliteMtd(string $fichier): int
    {
        if (! is_file($fichier)) {
            Log::warning('RefSensibiliteMtd.csv absent : sensibilite_mtd laissée vide.', ['fichier' => $fichier]);

            return 0;
        }

        $texte = file_get_contents($fichier);
        if ($texte === false) {
            throw new RuntimeException("Impossible de lire {$fichier}");
        }
        $texte = $this->utf8($texte);
        $lignes = preg_split('/\r\n|\r|\n/', $texte) ?: [];
        $header = array_map(fn ($h) => mb_strtolower(trim(trim((string) $h, "\"'"))), (array) str_getcsv((string) array_shift($lignes), ';'));

        DB::table('sensibilite_mtd')->delete();
        $chunk = [];
        $nb = 0;
        foreach ($lignes as $ligne) {
            if (trim($ligne) === '') {
                continue;
            }
            $champs = str_getcsv($ligne, ';');
            if (count($champs) !== count($header)) {
                continue; // ligne de largeur différente : ignorée
            }
            $rec = array_change_key_case(array_combine($header, $champs), CASE_LOWER);
            $id = $this->intOuNull($rec['cd_sens_mtd'] ?? null);
            if ($id === null) {
                continue;
            }
            $chunk[] = [
                'id' => $id,
                'cd_reg' => (string) ($this->nonVide($this->utf8((string) ($rec['cd_reg'] ?? ''))) ?? ''),
                'libelle_liste' => $this->nonVide($this->utf8((string) ($rec['libelle_liste'] ?? ''))),
                'ref_biblio' => $this->nonVide($this->utf8((string) ($rec['ref_biblio'] ?? ''))),
                'url' => $this->nonVide($this->utf8((string) ($rec['url'] ?? ''))),
                'ressource' => $this->nonVide($this->utf8((string) ($rec['ressource'] ?? ''))),
            ];
            if (count($chunk) >= self::BATCH) {
                DB::table('sensibilite_mtd')->insert($chunk);
                $nb += count($chunk);
                $chunk = [];
            }
        }
        if ($chunk !== []) {
            DB::table('sensibilite_mtd')->insert($chunk);
            $nb += count($chunk);
        }

        return $nb;
    }

    /** Dossier du référentiel de sensibilité (config, repli <inpn.dir>/SENSIBILITE_18). */
    private function dossierSensibilite(): string
    {
        $dossier = (string) config('portail.inpn.sensibilite_dossier', '');
        if ($dossier !== '' && is_dir($dossier)) {
            return $dossier;
        }
        $repli = $this->dir().'/SENSIBILITE_18';
        if (is_dir($repli)) {
            return $repli;
        }

        throw new RuntimeException('Dossier du référentiel de sensibilité introuvable (PORTAIL_SENSIBILITE_DIR / portail.inpn.sensibilite_dossier).');
    }

    /** Version créditée : numéro lu dans le nom du fichier (RefSensibilite_18.csv → « v18 »). */
    private function versionSensibilite(string $fichier): ?string
    {
        if (preg_match('/RefSensibilite_(\d+)\.csv$/i', basename($fichier), $m) === 1) {
            return (string) config('portail.inpn.versions.sensibilite').' — v'.$m[1];
        }

        return null;
    }

    // ==================================================================
    // Base de Connaissance des statuts (BDC v18) + fiches taxons TAXREF v18
    // ==================================================================

    /**
     * Statuts de protection/réglementation (BDC v18, INPN) des espèces du
     * périmètre + extraction TAXREF v18 (taxons_ref) pour les fiches espèces.
     *
     * Périmètre = cd_ref distincts de znieff_especes ∪ natura_especes (en
     * mode --dept, seules les espèces du département sont concernées). Les
     * deux tables sont rechargées intégralement (idempotent).
     *
     * Exclus à l'import (jamais dans une table affichable) : les statuts
     * ZDET (redondants avec znieff_especes.determinante), SENSNAT/SENSREG/
     * SENSDEP (la sensibilité ne s'affiche JAMAIS — elle ne filtre que côté
     * requête, App\Support\Especes) et les territoires communaux INSEEC.
     *
     * Les cd_ref CITÉS par REF_ESPECE / FSD peuvent être d'anciens codes
     * TAXREF devenus synonymes (ex. Utricularia neglecta = cd_nom 128307,
     * taxon accepté 128316 Utricularia australis) : après extraction de
     * taxons_ref (taxons acceptés seuls), les tables d'espèces sont
     * réorientées vers l'accepté (resoudreSynonymes) et le périmètre des
     * statuts BDC est étendu aux acceptés — sinon les listes lieraient des
     * fiches /espece/{synonyme} inexistantes.
     *
     * @param  callable(int): void  $tick
     * @return array{statuts: int, taxons: int, synonymes: int, lignes_resolues: int}
     */
    public function importBdc(?callable $tick = null): array
    {
        $cdRefs = $this->cdRefsPerimetre();
        if ($cdRefs === []) {
            Log::warning('Aucun cd_ref dans le périmètre (znieff_especes / natura_especes vide) : BDC non importé.');

            return ['statuts' => 0, 'taxons' => 0, 'synonymes' => 0, 'lignes_resolues' => 0];
        }

        $extraction = $this->importTaxonsRef($cdRefs, $tick);
        // Les statuts BDC ne référencent que des cd_ref acceptés : on étend
        // le périmètre aux acceptés des synonymes cités avant l'extraction.
        foreach ($extraction['synonymes'] as $accepte) {
            $cdRefs[(string) $accepte] = true;
        }
        $nbStatuts = $this->importEspeceStatuts($cdRefs, $tick);
        $resolues = $this->resoudreSynonymes($extraction['synonymes']);

        return [
            'statuts' => $nbStatuts,
            'taxons' => $extraction['taxons'],
            'synonymes' => count($extraction['synonymes']),
            'lignes_resolues' => $resolues,
        ];
    }

    /**
     * cd_ref distincts du périmètre courant : znieff_especes ∪ natura_especes.
     *
     * @return array<string, true>
     */
    private function cdRefsPerimetre(): array
    {
        $cdRefs = [];
        foreach (DB::table('znieff_especes')->distinct()->pluck('cd_ref') as $cd) {
            $cdRefs[(string) $cd] = true;
        }
        foreach (DB::table('natura_especes')->whereNotNull('cd_ref')->distinct()->pluck('cd_ref') as $cd) {
            $cdRefs[(string) $cd] = true;
        }

        return $cdRefs;
    }

    /**
     * Statuts BDC v18 filtrés au périmètre (bdc_18_01.csv, ~448 k lignes,
     * lu en flux, encodage Windows-1252 converti champ par champ).
     *
     * @param  array<string, true>  $cdRefs
     * @param  callable(int): void  $tick
     */
    private function importEspeceStatuts(array $cdRefs, ?callable $tick): int
    {
        $fichier = $this->dossierBdc().'/bdc_18_01.csv';
        if (! is_file($fichier)) {
            foreach (glob($this->dossierBdc().'/bdc_*.csv') ?: [] as $candidat) {
                $fichier = $candidat;
                break;
            }
        }
        if (! is_file($fichier)) {
            throw new RuntimeException('Base de connaissance BDC introuvable : '.$this->dossierBdc().'/bdc_*.csv');
        }

        $typesExclus = ['ZDET', 'SENSNAT', 'SENSREG', 'SENSDEP'];

        DB::table('espece_statuts')->delete(); // rechargement intégral (idempotent)
        $chunk = [];
        $nb = 0;
        foreach ($this->csv->iterate($fichier, ',') as $rec) {
            $cd = trim((string) ($rec['cd_ref'] ?? ''));
            if ($cd === '' || ! isset($cdRefs[$cd])) {
                continue;
            }
            $type = strtoupper(trim((string) ($rec['cd_type_statut'] ?? '')));
            if (in_array($type, $typesExclus, true)) {
                continue; // ZDET redondant, SENS* jamais importé (filtre uniquement)
            }
            $sig = trim((string) ($rec['cd_sig'] ?? ''));
            if (str_starts_with($sig, 'INSEEC')) {
                continue; // territoire communal exclu
            }
            $chunk[] = [
                'cd_ref' => (int) $cd,
                // Tous les champs texte passent par utf8() : le fichier BDC est
                // en Windows-1252, un octet haut non converti ferait échouer
                // le json_encode des props Inertia (data-page vide).
                'cd_type_statut' => $this->utf8($type),
                'lb_type_statut' => $this->utf8(trim((string) ($rec['lb_type_statut'] ?? ''))),
                'regroupement_type' => $this->utf8(trim((string) ($rec['regroupement_type'] ?? ''))),
                'code_statut' => $this->nonVide($this->utf8((string) ($rec['code_statut'] ?? ''))),
                'label_statut' => $this->nonVide($this->utf8((string) ($rec['label_statut'] ?? ''))),
                'rq_statut' => $this->nonVide($this->utf8((string) ($rec['rq_statut'] ?? ''))),
                'cd_sig' => $this->utf8($sig),
                'lb_adm_tr' => $this->nonVide($this->utf8((string) ($rec['lb_adm_tr'] ?? ''))),
                'niveau_admin' => $this->nonVide($this->utf8((string) ($rec['niveau_admin'] ?? ''))),
                'doc_url' => $this->nonVide($this->utf8((string) ($rec['doc_url'] ?? ''))),
                'full_citation' => $this->nonVide($this->utf8((string) ($rec['full_citation'] ?? ''))),
                'thematique' => $this->nonVide($this->utf8((string) ($rec['thematique'] ?? ''))) ?? 'STATUTS',
                'created_at' => now(),
                'updated_at' => now(),
            ];
            if (count($chunk) >= self::BATCH) {
                DB::table('espece_statuts')->insert($chunk);
                $nb += count($chunk);
                $chunk = [];
                if ($tick !== null) {
                    $tick($nb);
                }
            }
        }
        if ($chunk !== []) {
            DB::table('espece_statuts')->insert($chunk);
            $nb += count($chunk);
        }

        $this->registrer('bdc', $nb, (string) config('portail.inpn.versions.bdc'));

        return $nb;
    }

    /**
     * Extraction TAXREF v18 (fichier complet tabulé, lu en flux) pour les
     * cd_ref du périmètre : nom valide, groupes INPN, habitat (libellé via
     * habitats_note.csv), présence France / outre-mer, URL fiche INPN.
     *
     * Collecte au passage les SYNONYMES du périmètre : un cd_ref cité par
     * znieff/natura qui n'est pas son propre taxon accepté (TAXREF redirige
     * cd_nom → cd_ref) — importBdc s'en sert pour réorienter les tables
     * d'espèces vers l'accepté.
     *
     * @param  array<string, true>  $cdRefs
     * @param  callable(int): void  $tick
     * @return array{taxons: int, synonymes: array<int, int>} synonymes : cd_ref cité => cd_ref accepté
     */
    private function importTaxonsRef(array $cdRefs, ?callable $tick): array
    {
        $fichier = $this->fichierTaxrefComplet();
        if ($fichier === null) {
            Log::warning('TAXREFv*.txt introuvable : taxons_ref non importé (fiches espèces en repli sur znieff_especes).');

            return ['taxons' => 0, 'synonymes' => []];
        }

        $habitats = $this->carteHabitats();
        $om = ['GF', 'MAR', 'GUA', 'SM', 'SB', 'SPM', 'MAY', 'EPA', 'REU', 'SA', 'TA', 'TAAF', 'PF', 'NC', 'WF', 'CLI'];

        DB::table('taxons_ref')->delete(); // rechargement intégral (idempotent)
        $chunk = [];
        $nb = 0;
        $vus = []; // TAXREF contient plusieurs lignes par cd_ref (synonymes) : PK = cd_ref, on garde la première
        $synonymes = []; // cd_ref cité dans le périmètre => cd_ref accepté
        foreach ($this->csv->iterate($fichier, "\t") as $rec) {
            $cd = trim((string) ($rec['cd_ref'] ?? ''));
            // Synonyme ? le cd_nom cité par les tables d'espèces est distinct
            // de son taxon accepté (ligne du fichier : cd_nom ≠ cd_ref).
            $cdNom = trim((string) ($rec['cd_nom'] ?? ''));
            if ($cd !== '' && $cdNom !== '' && $cdNom !== $cd
                && isset($cdRefs[$cdNom]) && ! isset($synonymes[(int) $cdNom])) {
                $synonymes[(int) $cdNom] = (int) $cd;
            }
            if ($cd === '' || ! isset($cdRefs[$cd]) || isset($vus[$cd])) {
                continue;
            }
            $vus[$cd] = true;
            $hab = trim((string) ($rec['habitat'] ?? ''));
            $presentOm = false;
            foreach ($om as $terr) {
                if (strtoupper(trim((string) ($rec[strtolower($terr)] ?? ''))) === 'P') {
                    $presentOm = true;
                    break;
                }
            }
            $chunk[] = [
                'cd_ref' => (int) $cd,
                'nom_valide' => $this->nonVide($this->utf8((string) ($rec['nom_valide'] ?? ''))),
                'groupe1_inpn' => $this->nonVide($this->utf8((string) ($rec['group1_inpn'] ?? ''))),
                'groupe2_inpn' => $this->nonVide($this->utf8((string) ($rec['group2_inpn'] ?? ''))),
                'habitat_libelle' => $hab !== '' ? ($habitats[$hab] ?? null) : null,
                'presence_fr' => $this->nonVide($this->utf8((string) ($rec['fr'] ?? ''))),
                'present_om' => $presentOm,
                'url_inpn' => $this->nonVide($this->utf8((string) ($rec['url_inpn'] ?? ''))),
            ];
            if (count($chunk) >= self::BATCH) {
                DB::table('taxons_ref')->insert($chunk);
                $nb += count($chunk);
                $chunk = [];
                if ($tick !== null) {
                    $tick($nb);
                }
            }
        }
        if ($chunk !== []) {
            DB::table('taxons_ref')->insert($chunk);
            $nb += count($chunk);
        }

        $this->registrer('taxref', $nb, $this->versionTaxref($fichier));

        return ['taxons' => $nb, 'synonymes' => $synonymes];
    }

    /**
     * Réoriente les cd_ref cités des tables d'espèces vers leur taxon
     * accepté TAXREF. Sans cela, les listes lieraient des fiches
     * /espece/{cd_ref} inexistantes (un synonyme n'a pas de fiche : le
     * contenu des fiches part de taxons_ref, taxons acceptés seuls).
     *
     * Les autres attributs (nom_cite, déterminante, confidentielle…) sont
     * conservés : le filtre absolu App\Support\Especes garde son périmètre.
     *
     * @param  array<int, int>  $mapSynonymes  cd_ref cité => cd_ref accepté
     * @return int nombre de lignes mises à jour
     */
    private function resoudreSynonymes(array $mapSynonymes): int
    {
        $nb = 0;
        foreach ($mapSynonymes as $cite => $accepte) {
            $nb += DB::table('znieff_especes')->where('cd_ref', $cite)->update(['cd_ref' => $accepte]);
            $nb += DB::table('natura_especes')->where('cd_ref', $cite)->update(['cd_ref' => $accepte]);
        }

        return $nb;
    }

    /**
     * HABITAT (code 1-8 TAXREF) → libellé français, lu dans habitats_note.csv
     * (séparateur « ; », encodage Windows-1252 possible) du dossier TAXREF.
     *
     * @return array<string, string>
     */
    private function carteHabitats(): array
    {
        $carte = [];
        foreach ($this->dossiersTaxref() as $dossier) {
            $fichier = $dossier.'/habitats_note.csv';
            if (! is_file($fichier)) {
                continue;
            }
            $texte = file_get_contents($fichier);
            if ($texte === false) {
                continue;
            }
            foreach (preg_split('/\r\n|\r|\n/', $this->utf8($texte)) ?: [] as $i => $ligne) {
                if ($i === 0 || trim($ligne) === '') {
                    continue;
                }
                $champs = str_getcsv($ligne, ';');
                if (count($champs) >= 2 && is_numeric(trim($champs[0]))) {
                    $carte[trim($champs[0])] = trim($this->utf8($champs[1]));
                }
            }
            break;
        }

        return $carte;
    }

    /** Dossier de la Base de Connaissance (config, repli <inpn.dir>/BDC_18). */
    private function dossierBdc(): string
    {
        $dossier = (string) config('portail.inpn.bdc_dossier', '');
        if ($dossier !== '' && is_dir($dossier)) {
            return $dossier;
        }
        $repli = $this->dir().'/BDC_18';
        if (is_dir($repli)) {
            return $repli;
        }

        throw new RuntimeException('Dossier BDC introuvable (PORTAIL_BDC_DIR / portail.inpn.bdc_dossier).');
    }

    // ==================================================================
    // INPG — Inventaire National du Patrimoine Géologique (INPN/MNHN)
    // ==================================================================

    /** SRS des shapefiles INPG par territoire (métrop en Lambert-93, OM en UTM/RGF). */
    private const INPG_SRS = [
        'metrop' => 2154,
        'glp' => 32620,  // WGS84 UTM 20N
        'guf' => 2972,  // RGFG95 UTM 22N
        'mtq' => 32620,  // WGS84 UTM 20N
        'myt' => 32738,  // WGS84 UTM 38S
        'reu' => 2975,  // RGR92 UTM 40S
        'spm' => 32621,  // WGS84 UTM 21N
    ];

    /**
     * Import INPG : sites du patrimoine géologique (attributs), staging
     * PostGIS des shapefiles SIG_INPG (sites publics uniquement — la
     * géométrie d'un site confidentiel ne sort jamais), croisements
     * communes, GeoJSON précalculés (famille « inpg »).
     *
     * Idempotent : rechargement intégral. --dept restreint le périmètre
     * (sites intersectant le département, plus les sites qui lui sont
     * rattachés dans inpg_data_site_commune.csv même sans géométrie).
     *
     * @param  callable(int): void  $tick
     * @return array<string, int|bool>
     */
    public function importInpg(?string $dept = null, ?callable $tick = null, bool $avecGeojson = true): array
    {
        $dir = $this->dossierInpg();
        $attrs = $this->attrsInpg($dir);
        if ($attrs === []) {
            throw new RuntimeException('Aucun site INPG trouvé dans inpg_data_site.csv.csv.');
        }
        $idsConnus = array_keys($attrs);

        if (! $this->spatial->disponible()) {
            Log::warning('Import INPG sans PostGIS : attributs du référentiel seuls (ni surfaces ni GeoJSON).');
            $ids = $this->perimetreInpgAttributs($dir, $dept, $idsConnus);
            $this->ecrireInpgSites($attrs, $ids, []);
            $this->ecrireInpgPivots($dir, $ids, $tick);
            $this->ecrireInpgCommunesCsv($dir, $ids); // pivot depuis le CSV (sans surfaces calculées)
            $this->registrer('inpg', count($ids));

            return ['sites' => count($ids), 'interets' => 0, 'geometries' => 0, 'intersections' => 0, 'spatial' => false];
        }

        $this->exigerReferentielCommunal();
        $this->spatial->construireCommunesTemp($dept, $tick);

        [$nbGeom, $territoires] = $this->stagingInpg($dir, $tick, $idsConnus);
        $this->spatial->finaliserStaging('portail_tmp_inpg', $tick);

        // Périmètre spatial (géométrie ∩ département) ∪ rattachement CSV
        // (sites sans géométrie mais liés à une commune du département).
        $idsSpatial = $this->perimetre('portail_tmp_inpg', $dept, $idsConnus);
        $idsCsv = $this->perimetreInpgAttributs($dir, $dept, $idsConnus);
        $ids = array_values(array_unique(array_merge($idsSpatial, $idsCsv)));

        $this->ecrireInpgSites($attrs, $ids, $territoires);
        $this->ecrireInpgPivots($dir, $ids, $tick);
        $croisement = $this->spatial->croiser('inpg_communes', 'site_id', 'portail_tmp_inpg');
        $this->spatial->reporterMesures('inpg_sites', 'id', 'portail_tmp_inpg');
        $this->registrer('inpg', count($ids));

        $fichiersGeojson = 0;
        if ($avecGeojson) {
            foreach ($this->departsTouchees($dept) as $d) {
                $fichiersGeojson += $this->geojson->departementZones('inpg', $d);
            }
            foreach ($ids as $id) {
                if ($this->geojson->objet('inpg', $id)) {
                    $fichiersGeojson++;
                }
            }
            $this->geojson->manifest();
        }

        return [
            'sites' => count($ids),
            'interets' => (int) DB::table('inpg_interets')->count(),
            'geometries' => $nbGeom,
            'intersections' => $croisement['nb'],
            'communes_manquantes' => $croisement['communes_manquantes'],
            'geojson' => $fichiersGeojson,
            'spatial' => true,
        ];
    }

    /** Dossier INPG (config, repli : <inpn.dir>/INPG_BDD_* le plus récent). */
    private function dossierInpg(): string
    {
        $dossier = (string) config('portail.inpn.inpg_dossier', '');
        if ($dossier !== '' && is_dir($dossier)) {
            return $dossier;
        }
        $candidats = glob($this->dir().'/INPG_BDD_*') ?: [];
        rsort($candidats); // INPG_BDD_072025 avant INPG_BDD_062024
        foreach ($candidats as $c) {
            if (is_dir($c)) {
                return $c;
            }
        }

        throw new RuntimeException('Dossier INPG introuvable (PORTAIL_INPG_DIR / portail.inpn.inpg_dossier).');
    }

    /**
     * Attributs des sites INPG (inpg_data_site.csv.csv) dénormalisés via les
     * tables de codes (inpg_code_*.csv) : id_site_inpg → attributs.
     *
     * @return array<string, array<string, mixed>>
     */
    private function attrsInpg(string $dir): array
    {
        $codes = $this->codesInpg($dir);

        // Territoire par site via inpg_data_site_departement.csv (INSEEDxx → dept → territoire).
        $territoires = [];
        $fichierDep = $dir.'/inpg_data_site_departement.csv.csv';
        if (is_file($fichierDep)) {
            foreach ($this->csv->iterate($fichierDep) as $rec) {
                $site = trim((string) ($rec['id_site_inpg'] ?? ''));
                $dep = $this->spatial->codeCommune($rec['cd_sig_dep'] ?? null);
                if ($site !== '' && $dep !== null) {
                    $territoires[$site] ??= $this->territoireDeDept(substr($dep, 0, 2) === '97' ? substr($dep, 0, 3) : substr($dep, 0, 2));
                }
            }
        }

        $attrs = [];
        foreach ($this->csv->iterate($dir.'/inpg_data_site.csv.csv') as $rec) {
            $id = trim((string) ($rec['id_site_inpg'] ?? ''));
            if ($id === '') {
                continue;
            }
            $metier = trim((string) ($rec['identifiant_metier'] ?? ''));
            $etat = $this->intOuNull($rec['id_etat'] ?? null);
            $rarete = $this->intOuNull($rec['id_rarete'] ?? null);
            $phenom = $this->intOuNull($rec['id_phenom_geol'] ?? null);
            $gilges = $this->intOuNull($rec['id_gilges'] ?? null);
            $unite = $this->intOuNull($rec['id_unite_superficie'] ?? null);
            $attrs[$id] = [
                'id' => $id,
                'identifiant_permanent' => $this->nonVide($rec['identifiant_permanent'] ?? null),
                'identifiant_metier' => $metier,
                'nom' => $this->utf8(trim((string) ($rec['lb_site'] ?? ''))),
                'niv_diffusion' => (int) ($this->intOuNull($rec['id_niv_diffusion'] ?? null) ?? 2),
                'superficie_saisie' => $this->decimal($rec['superficie_saisie'] ?? null),
                'justif_superficie' => $this->nonVide($this->utf8((string) ($rec['justif_superficie'] ?? ''))),
                'unite_superficie' => $unite !== null ? ($codes['unite'][$unite] ?? null) : null,
                'etat' => $etat !== null ? ($codes['etat'][$etat] ?? null) : null,
                'present_succincte' => $this->nonVide($this->utf8((string) ($rec['present_succincte'] ?? ''))),
                'descr_phys' => $this->nonVide($this->utf8((string) ($rec['descr_phys'] ?? ''))),
                'descr_geol' => $this->nonVide($this->utf8((string) ($rec['descr_geol'] ?? ''))),
                'itineraire' => $this->nonVide($this->utf8((string) ($rec['itineraire'] ?? ''))),
                'gilges' => $gilges !== null ? ($codes['gilges'][$gilges] ?? null) : null,
                'phenom_geol' => $phenom !== null ? ($codes['phenom'][$phenom] ?? null) : null,
                'interet_hist_geol' => $this->nonVide($this->utf8((string) ($rec['interet_hist_geol'] ?? ''))),
                'rarete' => $rarete !== null ? ($codes['rarete'][$rarete] ?? null) : null,
                'comment_eval' => $this->nonVide($this->utf8((string) ($rec['comment_eval'] ?? ''))),
                'vuln_nat' => $this->nonVide($this->utf8((string) ($rec['vuln_nat'] ?? ''))),
                'menace_anthrop' => $this->nonVide($this->utf8((string) ($rec['menace_anthrop'] ?? ''))),
                'suivi_protec' => $this->nonVide($this->utf8((string) ($rec['suivi_protec'] ?? ''))),
                'comment_gnrl_menace' => $this->nonVide($this->utf8((string) ($rec['comment_gnrl_menace'] ?? ''))),
                'date_crea_site' => $this->dateIso($rec['date_creat_site'] ?? null),
                'date_prem_visite' => $this->dateIso($rec['date_prem_visite'] ?? null),
                'date_der_visite' => $this->dateIso($rec['date_der_visite'] ?? null),
                'date_modif' => $this->dateIso($rec['date_modif'] ?? null),
                'ref_carto' => $this->nonVide($rec['ref_carto'] ?? null),
                'territoire' => $territoires[$id] ?? 'metrop',
                'url_inpn' => $metier !== '' ? (string) config('portail.urls.inpg').$metier : null,
            ];
        }

        return $attrs;
    }

    /**
     * Tables de codes INPG (inpg_code_*.csv) : id → libellé (ou tableau pour
     * la stratigraphie et la bibliographie).
     *
     * @return array<string, array<int|string, string|array>>
     */
    private function codesInpg(string $dir): array
    {
        $lire = function (string $fichier, string $cle, callable $valeur) use ($dir): array {
            $map = [];
            if (! is_file($dir.'/'.$fichier)) {
                return $map;
            }
            foreach ($this->csv->iterate($dir.'/'.$fichier) as $rec) {
                $id = $this->intOuNull($rec[$cle] ?? null);
                if ($id !== null) {
                    $map[$id] = $valeur($rec);
                }
            }

            return $map;
        };

        $interets = $lire('inpg_code_interet.csv.csv', 'id_interet', fn ($r) => $this->utf8(trim((string) ($r['lb_interet'] ?? ''))));
        // Relation intérêt → intérêt supérieur : id_interet_interet_sup → [interet, sup].
        $interetSup = [];
        if (is_file($dir.'/inpg_code_interet_interet_sup.csv.csv')) {
            foreach ($this->csv->iterate($dir.'/inpg_code_interet_interet_sup.csv.csv') as $r) {
                $idRel = $this->intOuNull($r['id_interet_interet_sup'] ?? null);
                $idInt = $this->intOuNull($r['id_interet'] ?? null);
                $idSup = $this->intOuNull($r['id_interet_sup'] ?? null);
                if ($idRel !== null) {
                    $interetSup[$idRel] = [
                        'interet' => $idInt !== null ? ($interets[$idInt] ?? null) : null,
                        'sup' => $idSup !== null ? ($interets[$idSup] ?? null) : null,
                    ];
                }
            }
        }

        return [
            'etat' => $lire('inpg_code_etat.csv.csv', 'id_etat', fn ($r) => $this->utf8(trim((string) ($r['lb_etat'] ?? '')))),
            'rarete' => $lire('inpg_code_rarete.csv.csv', 'id_rarete', fn ($r) => $this->utf8(trim((string) ($r['lb_rarete'] ?? '')))),
            'phenom' => $lire('inpg_code_phenom_geol.csv.csv', 'id_phenom_geol', fn ($r) => $this->utf8(trim((string) ($r['lb_phenom_geol'] ?? '')))),
            'gilges' => $lire('inpg_code_gilges.csv.csv', 'id_gilges', fn ($r) => $this->utf8(trim((string) ($r['def_gilges'] ?? '')))),
            'unite' => $lire('inpg_code_unite_superficie.csv.csv', 'id_unite_superficie', fn ($r) => $this->utf8(trim((string) ($r['lb_unite_superficie'] ?? '')))),
            'interet' => $interets,
            'interet_sup' => $interetSup,
            'zonage_ref' => $lire('inpg_code_zonage_ref.csv.csv', 'id_zonage_ref', fn ($r) => $this->utf8(trim((string) ($r['lb_zonage_ref'] ?? '')))),
            'strati' => $lire('inpg_code_strati.csv.csv', 'id_strati', fn ($r) => [
                'lb' => $this->utf8(trim((string) ($r['lb_fr_strati'] ?? ''))),
                'age_inf' => $this->decimal($r['age_inf'] ?? null),
                'age_sup' => $this->decimal($r['age_sup'] ?? null),
            ]),
            'type_doc' => $lire('inpg_code_type_doc.csv.csv', 'id_type_doc', fn ($r) => $this->utf8(trim((string) ($r['lb_type_doc'] ?? '')))),
            'accessib' => $lire('inpg_code_accessib.csv.csv', 'id_accessib', fn ($r) => $this->utf8(trim((string) ($r['lb_accessib'] ?? '')))),
            'typologie' => $lire('inpg_code_typologie.csv.csv', 'id_typologie', fn ($r) => $this->utf8(trim((string) ($r['lb_typologie'] ?? '')))),
            'type_note' => $lire('inpg_code_type_note.csv.csv', 'id_type_note', fn ($r) => $this->utf8(trim((string) ($r['lb_type_note'] ?? '')))),
            'biblio' => $lire('inpg_code_biblio.csv.csv', 'id_biblio', function ($r) {
                $auteurs = $this->utf8(trim((string) ($r['auteurs'] ?? '')));
                $titre = $this->utf8(trim((string) ($r['titre'] ?? '')));
                $annee = trim((string) ($r['annee_publi'] ?? ''));
                $ref = $this->utf8(trim((string) ($r['reference'] ?? '')));
                $txt = trim($auteurs.($annee !== '' ? ' ('.$annee.')' : '').'. '.$titre.($ref !== '' ? ' '.$ref : ''), '. ');

                return $txt === '' ? null : $txt;
            }),
            'carte_geol' => $lire('inpg_code_carte_geol.csv.csv', 'id_carte_geol', fn ($r) => $this->nonVide($this->utf8(trim((string) ($r['lb_carte_geol'] ?? ''))).' '.trim((string) ($r['num_carte_geol'] ?? '')))),
            'carte_ign' => $lire('inpg_code_carte_ign.csv.csv', 'id_carte_ign', fn ($r) => $this->nonVide($this->utf8(trim((string) ($r['lb_carte_ign'] ?? ''))).' '.trim((string) ($r['num_carte_ign'] ?? '')))),
            'carte_marine' => $lire('inpg_code_carte_marine.csv.csv', 'id_carte_marine', fn ($r) => $this->nonVide($this->utf8(trim((string) ($r['lb_carte_marine'] ?? ''))).' '.trim((string) ($r['num_carte_marine'] ?? '')))),
        ];
    }

    /** Territoire INPG depuis un code département (971→glp, 973→guf, 2A/2B/971-976…). */
    private function territoireDeDept(string $dept): string
    {
        return match ($dept) {
            '971' => 'glp',
            '972' => 'mtq',
            '973' => 'guf',
            '974' => 'reu',
            '975' => 'spm',
            '976' => 'myt',
            default => 'metrop',
        };
    }

    /**
     * Staging PostGIS des shapefiles SIG_INPG : un fichier par territoire,
     * SRS explicite (les OM sont en UTM, la détection par bbox ne suffit
     * pas). Seuls les sites connus du référentiel d'attributs sont chargés.
     * Les sites confidentiels (niv_diffusion = 1) n'ont en principe pas de
     * géométrie diffusée — on ne charge de toute façon que les ids du CSV.
     *
     * @param  array<int, string>  $idsConnus
     * @param  callable(int): void  $tick
     * @return array{0: int, 1: array<string, string>} [nb géométries, id → territoire]
     */
    private function stagingInpg(string $dir, ?callable $tick, array $idsConnus): array
    {
        $connus = array_flip($idsConnus);
        $this->spatial->creerStaging('portail_tmp_inpg');
        $nb = 0;
        $territoires = [];
        $lot = [];
        foreach (self::INPG_SRS as $territoire => $srs) {
            $shp = $dir.'/SIG_INPG/inpg_sig_'.$territoire.'.shp';
            if (! is_file($shp)) {
                continue;
            }
            $reader = new ShapefileReader($shp);
            foreach ($reader->records() as $rec) {
                $id = trim((string) ($rec['attrs']['id_site_in'] ?? ''));
                if ($id === '' || ! isset($connus[$id]) || ($rec['wkt'] ?? null) === null) {
                    continue;
                }
                $territoires[$id] = $territoire;
                $lot[] = [
                    'id' => $id,
                    'famille' => 'inpg',
                    'nom' => null,
                    'superficie_ref' => null,
                    'srs' => $srs,
                    'wkt' => $rec['wkt'],
                ];
                if (count($lot) >= 50) { // lots de 50, comme le GPKG AP (jamais tout en mémoire)
                    $this->spatial->insererStaging('portail_tmp_inpg', $lot);
                    $nb += count($lot);
                    $lot = [];
                    if ($tick !== null) {
                        $tick($nb);
                    }
                }
            }
            if ($lot !== []) {
                $this->spatial->insererStaging('portail_tmp_inpg', $lot);
                $nb += count($lot);
                $lot = [];
            }
        }

        return [$nb, $territoires];
    }

    /**
     * Écrit les sites du périmètre dans inpg_sites (rechargement intégral,
     * les FK des pivots cascadent). superficie_ha / bbox remplies ensuite
     * par reporterMesures pour les sites géométriques.
     *
     * @param  array<string, array<string, mixed>>  $attrs
     * @param  array<int, string>  $ids
     * @param  array<string, string>  $territoires  id → territoire (staging)
     */
    private function ecrireInpgSites(array $attrs, array $ids, array $territoires): void
    {
        DB::table('inpg_sites')->delete();
        $chunk = [];
        foreach ($ids as $id) {
            if (! isset($attrs[$id])) {
                continue;
            }
            $a = $attrs[$id];
            $chunk[] = $a + [
                'territoire' => $territoires[$id] ?? $a['territoire'],
                'superficie_ha' => null,
                'bbox_min_lat' => null,
                'bbox_max_lat' => null,
                'bbox_min_lng' => null,
                'bbox_max_lng' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            if (count($chunk) >= self::BATCH) {
                DB::table('inpg_sites')->insert($chunk);
                $chunk = [];
            }
        }
        if ($chunk !== []) {
            DB::table('inpg_sites')->insert($chunk);
        }
    }

    /**
     * Périmètre attributaire (mode sans PostGIS, ou complément des sites sans
     * géométrrie) : sites rattachés à une commune du département courant via
     * inpg_data_site_commune.csv (cd_sig_com INSEECxxxxx → code INSEE).
     *
     * @param  array<int, string>  $idsConnus
     * @return array<int, string>
     */
    private function perimetreInpgAttributs(string $dir, ?string $dept, array $idsConnus): array
    {
        $connus = array_flip($idsConnus);
        $fichier = $dir.'/inpg_data_site_commune.csv.csv';
        if (! is_file($fichier)) {
            return $dept === null ? $idsConnus : [];
        }
        $inseeDept = null;
        if ($dept !== null) {
            $inseeDept = array_flip(DB::table('communes')->where('dept', $dept)->pluck('insee')->all());
        }
        $ids = [];
        foreach ($this->csv->iterate($fichier) as $rec) {
            $site = trim((string) ($rec['id_site_inpg'] ?? ''));
            if ($site === '' || ! isset($connus[$site])) {
                continue;
            }
            if ($inseeDept !== null) {
                $insee = $this->spatial->codeCommune($rec['cd_sig_com'] ?? null);
                if ($insee === null || ! isset($inseeDept[$insee])) {
                    continue;
                }
            }
            $ids[$site] = $site;
        }

        return $dept === null ? $idsConnus : array_values($ids);
    }

    /**
     * Pivot communes depuis inpg_data_site_commune.csv (mode attributs seuls,
     * sans PostGIS : pas de surfaces calculées, null partout). En mode spatial,
     * c'est croiser() qui remplit le pivot avec les vraies mesures.
     *
     * @param  array<int, string>  $idsPerimetre
     */
    private function ecrireInpgCommunesCsv(string $dir, array $idsPerimetre): void
    {
        DB::table('inpg_communes')->delete();
        $perimetre = array_flip($idsPerimetre);
        $fichier = $dir.'/inpg_data_site_commune.csv.csv';
        if (! is_file($fichier)) {
            return;
        }
        $chunk = [];
        foreach ($this->csv->iterate($fichier) as $rec) {
            $site = trim((string) ($rec['id_site_inpg'] ?? ''));
            if ($site === '' || ! isset($perimetre[$site])) {
                continue;
            }
            $insee = $this->spatial->codeCommune($rec['cd_sig_com'] ?? null);
            // FK vers communes : ne garder que les communes du référentiel.
            if ($insee === null || ! DB::table('communes')->where('insee', $insee)->exists()) {
                continue;
            }
            $chunk[] = [
                'site_id' => $site,
                'insee' => $insee,
                'surface_ha' => null,
                'part_zonage' => null,
                'part_commune' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            if (count($chunk) >= self::BATCH) {
                DB::table('inpg_communes')->insert($chunk);
                $chunk = [];
            }
        }
        if ($chunk !== []) {
            DB::table('inpg_communes')->insert($chunk);
        }
    }

    /**
     * Tables pivot INPG du périmètre : intérêts, zonages réglementaires
     * liés, stratigraphie, documents, lieux-dits, notes, bibliographie,
     * accessibilité, typologies, cartes. Rechargement intégral.
     * Les informateurs (personnes physiques) ne sont jamais importés.
     *
     * @param  array<int, string>  $idsPerimetre
     * @param  callable(int): void  $tick
     * @return array<string, int>
     */
    private function ecrireInpgPivots(string $dir, array $idsPerimetre, ?callable $tick): array
    {
        $perimetre = array_flip($idsPerimetre);
        $codes = $this->codesInpg($dir);
        $maj = function (string $table, string $fichier, callable $ligne) use ($dir, $perimetre, $tick, $codes): int {
            DB::table($table)->delete();
            if (! is_file($dir.'/'.$fichier)) {
                return 0;
            }
            $chunk = [];
            $nb = 0;
            foreach ($this->csv->iterate($dir.'/'.$fichier) as $rec) {
                $site = trim((string) ($rec['id_site_inpg'] ?? ''));
                if ($site === '' || ! isset($perimetre[$site])) {
                    continue;
                }
                $row = $ligne($rec, $site, $codes);
                if ($row === null) {
                    continue;
                }
                $chunk[] = $row;
                if (count($chunk) >= self::BATCH) {
                    DB::table($table)->insert($chunk);
                    $nb += count($chunk);
                    $chunk = [];
                    if ($tick !== null) {
                        $tick($nb);
                    }
                }
            }
            if ($chunk !== []) {
                DB::table($table)->insert($chunk);
                $nb += count($chunk);
            }

            return $nb;
        };

        $retour = [];
        // Le pivot porte id_interet_interet_sup : l'intérêt et son supérieur
        // se lisent dans la relation (code_interet_interet_sup).
        $retour['interets'] = $maj('inpg_interets', 'inpg_data_site_interet.csv.csv', function ($r, $site, $c) {
            $idRel = $this->intOuNull($r['id_interet_interet_sup'] ?? null);
            $rel = $idRel !== null ? ($c['interet_sup'][$idRel] ?? null) : null;

            return [
                'site_id' => $site,
                'interet' => is_array($rel) ? (string) ($rel['interet'] ?? '') : '',
                'interet_sup' => is_array($rel) ? $rel['sup'] : null,
                'justif' => $this->nonVide($this->utf8((string) ($r['justif'] ?? ''))),
            ];
        });
        $retour['zonage_ref'] = $maj('inpg_zonage_ref', 'inpg_data_site_zonage_ref.csv.csv', function ($r, $site, $c) {
            $id = $this->intOuNull($r['id_zonage_ref'] ?? null);

            return [
                'site_id' => $site,
                'zonage_ref' => $id !== null ? ($c['zonage_ref'][$id] ?? '') : '',
                'reference' => $this->nonVide($this->utf8((string) ($r['reference'] ?? ''))),
                'commentaire' => $this->nonVide($this->utf8((string) ($r['commentaire'] ?? ''))),
            ];
        });
        $retour['strati'] = $maj('inpg_strati', 'inpg_data_site_strati.csv.csv', function ($r, $site, $c) {
            $id = $this->intOuNull($r['id_strati'] ?? null);
            $s = $id !== null ? ($c['strati'][$id] ?? null) : null;

            return [
                'site_id' => $site,
                'strati' => is_array($s) ? ($s['lb'] ?? '') : '',
                'age_inf' => is_array($s) ? $s['age_inf'] : null,
                'age_sup' => is_array($s) ? $s['age_sup'] : null,
            ];
        });
        $retour['documents'] = $maj('inpg_documents', 'inpg_data_doc.csv.csv', function ($r, $site, $c) {
            $type = $this->intOuNull($r['id_type_doc'] ?? null);

            return [
                'site_id' => $site,
                'lb_doc' => $this->nonVide($this->utf8((string) ($r['lb_doc'] ?? ''))),
                'legende_doc' => $this->nonVide($this->utf8((string) ($r['legende_doc'] ?? ''))),
                'type_doc' => $type !== null ? ($c['type_doc'][$type] ?? null) : null,
                'diff_doc' => (int) ($this->intOuNull($r['id_diff_doc'] ?? null) ?? 3),
                'path_doc' => $this->nonVide($r['path_doc'] ?? null),
            ];
        });
        $retour['lieudits'] = $maj('inpg_lieudits', 'inpg_data_lieudit.csv.csv', fn ($r, $site) => [
            'site_id' => $site,
            'lb_lieudit' => $this->utf8(trim((string) ($r['lb_lieudit'] ?? ''))),
        ]);
        $retour['notes'] = $maj('inpg_notes', 'inpg_data_site_type_note.csv.csv', function ($r, $site, $c) {
            $id = $this->intOuNull($r['id_type_note'] ?? null);

            return [
                'site_id' => $site,
                'type_note' => $id !== null ? ($c['type_note'][$id] ?? '') : '',
                'note' => $this->nonVide($this->utf8((string) ($r['note'] ?? ''))),
                'note_pond' => $this->decimal($r['note_pond'] ?? null),
            ];
        });
        $retour['biblio'] = $maj('inpg_biblio', 'inpg_data_site_biblio.csv.csv', function ($r, $site, $c) {
            $id = $this->intOuNull($r['id_biblio'] ?? null);

            return [
                'site_id' => $site,
                'biblio' => ($id !== null ? ($c['biblio'][$id] ?? null) : null) ?? '',
            ];
        });
        $retour['accessibilites'] = $maj('inpg_accessibilites', 'inpg_data_site_accessib.csv.csv', function ($r, $site, $c) {
            $id = $this->intOuNull($r['id_accessib'] ?? null);

            return [
                'site_id' => $site,
                'accessib' => $id !== null ? ($c['accessib'][$id] ?? '') : '',
            ];
        });
        $retour['typologies'] = $maj('inpg_typologies', 'inpg_data_site_typologie.csv.csv', function ($r, $site, $c) {
            $id = $this->intOuNull($r['id_typologie'] ?? null);

            return [
                'site_id' => $site,
                'typologie' => $id !== null ? ($c['typologie'][$id] ?? '') : '',
            ];
        });
        // Cartes : trois fichiers alimentent la même table (purge unique).
        DB::table('inpg_cartes')->delete();
        $nbCartes = 0;
        foreach ([
            ['inpg_data_site_carte_geol.csv.csv', 'id_carte_geol', 'geol', 'carte_geol'],
            ['inpg_data_site_carte_ign.csv.csv', 'id_carte_ign', 'ign', 'carte_ign'],
            ['inpg_data_site_carte_marine.csv.csv', 'id_carte_marine', 'marine', 'carte_marine'],
        ] as [$fichier, $champId, $type, $cleCodes]) {
            if (! is_file($dir.'/'.$fichier)) {
                continue;
            }
            $chunk = [];
            foreach ($this->csv->iterate($dir.'/'.$fichier) as $rec) {
                $site = trim((string) ($rec['id_site_inpg'] ?? ''));
                if ($site === '' || ! isset($perimetre[$site])) {
                    continue;
                }
                $id = $this->intOuNull($rec[$champId] ?? null);
                $chunk[] = ['site_id' => $site, 'type_carte' => $type, 'carte' => $id !== null ? ($codes[$cleCodes][$id] ?? '') : ''];
                if (count($chunk) >= self::BATCH) {
                    DB::table('inpg_cartes')->insert($chunk);
                    $nbCartes += count($chunk);
                    $chunk = [];
                }
            }
            if ($chunk !== []) {
                DB::table('inpg_cartes')->insert($chunk);
                $nbCartes += count($chunk);
            }
        }
        $retour['cartes'] = $nbCartes;

        return $retour;
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

    // ==================================================================
    // HABREF v7.0 (MNHN) — référentiel national des habitats
    // ==================================================================

    /** Typologies retenues : 8 = HIC (Natura 2000), 7 = EUNIS, 22 = CORINE Biotopes. */
    private const HABREF_TYPOS = [7, 8, 22];

    /** Relations taxon-habitat retenues (caractéristiques au sens large HABREF). */
    private const HABREF_TYPES_TAXONS = [19, 20, 26, 29, 32, 39];

    /**
     * HABREF v7.0 (MNHN) pour le périmètre : unités HIC (typo 8) citées par
     * les FSD Natura 2000 du périmètre, leurs correspondances directes
     * (EUNIS typo 7, CORINE typo 22) et leurs parents hiérarchiques ;
     * correspondances entre unités ; espèces caractéristiques (types
     * 19/20/26/29/32/39) avec cd_ref résolu via TAXREF v18 ; territoires de
     * présence (statut P). Tables purement attributaires.
     *
     * Le filtre de sensibilité des taxons n'est PAS posé ici : il est posé
     * côté requête à la lecture (App\Support\Habitats, comme App\Support\Especes).
     *
     * @param  callable(int): void  $tick
     * @return array{unites: int, corresp: int, taxons: int, taxons_sans_cdref: int, hic: int}
     */
    public function importHabref(?string $dept = null, ?callable $tick = null): array
    {
        $dir = $this->dossierHabref();
        $fHab = $this->fichierHabref($dir, 'HABREF_[0-9]*.csv');
        $fCorrespHab = $this->fichierHabref($dir, 'HABREF_CORRESP_HAB_[0-9]*.csv');
        $fCorrespTaxon = $this->fichierHabref($dir, 'HABREF_CORRESP_TAXON_[0-9]*.csv');
        $fTerr = $this->fichierHabref($dir, 'HABREF_TERR_[0-9]*.csv');
        $fTypeRel = $this->fichierHabref($dir, 'HABREF_TYPE_REL*.csv');

        // 1. Périmètre : cd_hab (unités HIC) cités par les FSD Natura 2000 du
        //    périmètre (département via natura_communes → communes).
        $requete = DB::table('natura_habitats')->whereNotNull('cd_hab')->distinct();
        if ($dept !== null) {
            $requete->whereIn('site_code', function ($q) use ($dept) {
                $q->select('p.site_code')
                    ->from('natura_communes as p')
                    ->join('communes as c', 'c.insee', '=', 'p.insee')
                    ->where('c.dept', $dept);
            });
        }
        /** @var array<int, int> $cdHabsHic */
        $cdHabsHic = $requete->pluck('cd_hab')->map(fn ($v) => (int) $v)->filter(fn ($v) => $v > 0)->all();
        if ($cdHabsHic === []) {
            throw new RuntimeException('Aucun cd_hab dans natura_habitats pour ce périmètre : lancez d\'abord --source=natura.');
        }

        // 2. Index des unités des typologies retenues (HABREF_70.csv).
        /** @var array<int, array<string, string>> $unites */
        $unites = [];
        foreach ($this->csv->iterate($fHab) as $rec) {
            $typo = (int) ($rec['cd_typo'] ?? 0);
            if (! in_array($typo, self::HABREF_TYPOS, true)) {
                continue;
            }
            $cd = (int) ($rec['cd_hab'] ?? 0);
            if ($cd > 0) {
                $unites[$cd] = $rec;
            }
        }

        // 3. Correspondances entre unités retenues (index direct + inverse).
        $corresp = [];    // entre => [sortie => type]
        $correspInv = []; // sortie => [entre => type]
        foreach ($this->csv->iterate($fCorrespHab) as $rec) {
            $e = (int) ($rec['cd_hab_entre'] ?? 0);
            $s = (int) ($rec['cd_hab_sortie'] ?? 0);
            $t = (int) ($rec['cd_type_relation'] ?? 0);
            if ($e <= 0 || $s <= 0) {
                continue;
            }
            $corresp[$e][$s] = $t;
            $correspInv[$s][$e] = $t;
        }

        // Unités à importer : HIC du périmètre + correspondances directes +
        // parents hiérarchiques (récursif, dans les typologies retenues).
        $aImporter = array_fill_keys($cdHabsHic, true);
        foreach ($cdHabsHic as $cd) {
            foreach (($corresp[$cd] ?? []) as $s => $t) {
                if (isset($unites[$s])) {
                    $aImporter[$s] = true;
                }
            }
            foreach (($correspInv[$cd] ?? []) as $e => $t) {
                if (isset($unites[$e])) {
                    $aImporter[$e] = true;
                }
            }
        }
        $pile = array_keys($aImporter);
        while ($pile !== []) {
            $cd = array_pop($pile);
            $sup = (int) ($unites[$cd]['cd_hab_sup'] ?? 0);
            if ($sup > 0 && isset($unites[$sup]) && ! isset($aImporter[$sup])) {
                $aImporter[$sup] = true;
                $pile[] = $sup;
            }
        }

        // 4. Territoires de présence (statut P) des unités à importer.
        $territoires = []; // cd_hab => [CD_SIG_TERR]
        foreach ($this->csv->iterate($fTerr) as $rec) {
            $cd = (int) ($rec['cd_hab'] ?? 0);
            $statut = strtoupper(trim((string) ($rec['cd_statut_presence'] ?? '')));
            if ($cd > 0 && isset($aImporter[$cd]) && $statut === 'P') {
                $territoires[$cd][] = trim($this->utf8((string) ($rec['cd_sig_terr'] ?? '')));
            }
        }

        // 5. Écriture des unités (rechargement intégral, idempotent).
        DB::table('habref_taxons')->delete();
        DB::table('habref_corresp')->delete();
        DB::table('habref_habitats')->delete();

        $chunk = [];
        $nbUnites = 0;
        foreach (array_keys($aImporter) as $cd) {
            $u = $unites[$cd];
            $chunk[] = [
                'cd_hab' => $cd,
                'cd_typo' => (int) ($u['cd_typo'] ?? 0),
                'lb_code' => $this->nonVide($this->utf8((string) ($u['lb_code'] ?? ''))),
                'nom_fr' => $this->utf8((string) ($u['lb_hab_fr'] ?? '')),
                'nom_fr_complet' => $this->nonVide($this->utf8((string) ($u['lb_hab_fr_complet'] ?? ''))),
                'nom_en' => $this->nonVide($this->utf8((string) ($u['lb_hab_en'] ?? ''))),
                'niveau' => $this->intOuNull($u['niveau'] ?? null),
                'cd_hab_sup' => $this->intOuNull($u['cd_hab_sup'] ?? null),
                'path' => $this->nonVide((string) ($u['path_cd_hab'] ?? '')),
                'description' => $this->nonVide($this->utf8((string) ($u['lb_description'] ?? ''))),
                'territoires' => isset($territoires[$cd]) ? implode(',', array_unique($territoires[$cd])) : null,
                'france' => strtolower(trim((string) ($u['france'] ?? 'true'))) === 'true',
                'created_at' => now(),
                'updated_at' => now(),
            ];
            if (count($chunk) >= self::BATCH) {
                DB::table('habref_habitats')->insert($chunk);
                $nbUnites += count($chunk);
                $chunk = [];
            }
        }
        if ($chunk !== []) {
            DB::table('habref_habitats')->insert($chunk);
            $nbUnites += count($chunk);
        }

        // 6. Correspondances retenues (les deux côtés importés).
        $chunk = [];
        $nbCorresp = 0;
        foreach ($corresp as $e => $sorties) {
            if (! isset($aImporter[$e])) {
                continue;
            }
            foreach ($sorties as $s => $t) {
                if (! isset($aImporter[$s])) {
                    continue;
                }
                $chunk[] = [
                    'cd_hab_entre' => $e,
                    'cd_hab_sortie' => $s,
                    'cd_type_relation' => $t > 0 ? $t : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                if (count($chunk) >= self::BATCH) {
                    DB::table('habref_corresp')->insert($chunk);
                    $nbCorresp += count($chunk);
                    $chunk = [];
                }
            }
        }
        if ($chunk !== []) {
            DB::table('habref_corresp')->insert($chunk);
            $nbCorresp += count($chunk);
        }

        // 7. Libellés des types de relation taxon-habitat.
        $typesRel = []; // cd_type_rel => libellé
        foreach ($this->csv->iterate($fTypeRel) as $rec) {
            $t = (int) ($rec['cd_type_rel'] ?? 0);
            if ($t > 0) {
                $typesRel[$t] = $this->nonVide($this->utf8((string) ($rec['lb_rel'] ?? ''))) ?? (string) $t;
            }
        }

        // 8. Espèces caractéristiques des unités importées, cd_ref résolu via
        //    TAXREF v18 (une passe filtrée sur les cd_nom rencontrés).
        $lignesTaxons = [];
        $cdNoms = [];
        foreach ($this->csv->iterate($fCorrespTaxon) as $rec) {
            $cd = (int) ($rec['cd_hab_entre'] ?? 0);
            $type = (int) ($rec['cd_type_relation'] ?? 0);
            $cdNom = (int) ($rec['cd_nom'] ?? 0);
            if (! isset($aImporter[$cd]) || ! in_array($type, self::HABREF_TYPES_TAXONS, true) || $cdNom <= 0) {
                continue;
            }
            $cdNoms[$cdNom] = true;
            $lignesTaxons[] = [
                'cd_hab' => $cd,
                'cd_nom' => $cdNom,
                'nom_cite' => $this->nonVide($this->utf8((string) ($rec['nom_cite'] ?? ''))),
                'cd_type_relation' => $type,
                'type_libelle' => $typesRel[$type] ?? (string) $type,
            ];
        }
        $cdRefParCdNom = $this->resoudreCdRefTaxref(array_keys($cdNoms));

        $chunk = [];
        $nbTaxons = 0;
        $sansCdRef = 0;
        foreach ($lignesTaxons as $l) {
            $cdRef = $cdRefParCdNom[$l['cd_nom']] ?? null;
            if ($cdRef === null) {
                $sansCdRef++;
            }
            $chunk[] = $l + [
                'cd_ref' => $cdRef,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            if (count($chunk) >= self::BATCH) {
                DB::table('habref_taxons')->insert($chunk);
                $nbTaxons += count($chunk);
                $chunk = [];
                if ($tick !== null) {
                    $tick($nbTaxons);
                }
            }
        }
        if ($chunk !== []) {
            DB::table('habref_taxons')->insert($chunk);
            $nbTaxons += count($chunk);
        }

        $this->registrer('habref', $nbUnites, (string) config('portail.inpn.versions.habref'));

        return [
            'hic' => count($cdHabsHic),
            'unites' => $nbUnites,
            'corresp' => $nbCorresp,
            'taxons' => $nbTaxons,
            'taxons_sans_cdref' => $sansCdRef,
        ];
    }

    /**
     * Premier fichier correspondant au motif dans le dossier HABREF.
     */
    private function fichierHabref(string $dir, string $motif): string
    {
        foreach (glob($dir.'/'.$motif, GLOB_NOSORT) ?: [] as $f) {
            if (is_file($f)) {
                return $f;
            }
        }

        throw new RuntimeException("Fichier HABREF introuvable : {$dir}/{$motif}");
    }

    /**
     * Résout cd_nom → cd_ref via TAXREF v18 (une passe filtrée sur les cd_nom
     * demandés). Retourne [] si TAXREF est indisponible (les taxons seront
     * alors sans cd_ref, donc exclus de l'affichage filtré — conservateur).
     *
     * @param  array<int, int>  $cdNoms
     * @return array<int, int> cd_nom => cd_ref
     */
    private function resoudreCdRefTaxref(array $cdNoms): array
    {
        $fichier = $this->fichierTaxrefComplet();
        if ($fichier === null || $cdNoms === []) {
            return [];
        }
        $demandes = array_fill_keys($cdNoms, true);
        $map = [];
        foreach ($this->csv->iterate($fichier, "\t") as $rec) {
            $cdNom = trim((string) ($rec['cd_nom'] ?? ''));
            if ($cdNom !== '' && isset($demandes[$cdNom]) && ! isset($map[(int) $cdNom])) {
                $cdRef = trim((string) ($rec['cd_ref'] ?? ''));
                if ($cdRef !== '') {
                    $map[(int) $cdNom] = (int) $cdRef;
                }
            }
        }

        return $map;
    }

    /** Dossier HABREF (config, repli sur le plus récent glob HABREF_* sous <inpn.dir>). */
    private function dossierHabref(): string
    {
        $dossier = (string) config('portail.inpn.habref_dossier', '');
        if ($dossier !== '' && is_dir($dossier)) {
            return $dossier;
        }
        foreach (glob($this->dir().'/HABREF_*', GLOB_NOSORT) ?: [] as $d) {
            if (is_dir($d)) {
                return $d;
            }
        }

        throw new RuntimeException('Dossier HABREF introuvable (PORTAIL_HABREF_DIR / portail.inpn.habref_dossier).');
    }

    // ==================================================================
    // Référentiel des organismes adhérents au SINP (producteurs de données)
    // ==================================================================

    /**
     * Organismes adhérents au SINP (ref_organismes_a_plat_*.csv, INPN) :
     * référentiel national complet (pas de périmètre --dept), rechargé
     * intégralement (idempotent). Coordonnées personnelles (mail, téléphone,
     * adresse) JAMAIS importées — page /organismes « producteurs de données ».
     *
     * @param  callable(int): void  $tick
     * @return array{organismes: int}
     */
    public function importOrganismes(?callable $tick = null): array
    {
        $dir = $this->dossierOrganismes();
        $fichier = null;
        foreach (glob($dir.'/ref_organismes_a_plat_*.csv', GLOB_NOSORT) ?: [] as $f) {
            if (is_file($f)) {
                $fichier = $f;
                break;
            }
        }
        if ($fichier === null) {
            throw new RuntimeException("Référentiel des organismes introuvable : {$dir}/ref_organismes_a_plat_*.csv");
        }

        DB::table('organismes')->delete(); // rechargement intégral (idempotent)
        $chunk = [];
        $nb = 0;
        foreach ($this->csv->iterate($fichier) as $rec) {
            $id = trim((string) ($rec['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $chunk[] = [
                'id' => $id,
                'libelle_court' => $this->utf8((string) ($rec['libelle_court'] ?? '')),
                'libelle_long' => $this->nonVide($this->utf8((string) ($rec['libelle_long'] ?? ''))),
                'domaines' => $this->nonVide($this->utf8((string) ($rec['domaines'] ?? ''))),
                'zones' => $this->nonVide($this->utf8((string) ($rec['zones'] ?? ''))),
                'niveau_adhesion' => $this->nonVide($this->utf8((string) ($rec['niveau_adhesion'] ?? ''))),
                'statut_organisme' => $this->nonVide($this->utf8((string) ($rec['statut_organisme'] ?? ''))),
                'type_organisme' => $this->nonVide($this->utf8((string) ($rec['type_organisme'] ?? ''))),
                'url' => $this->nonVide($this->utf8((string) ($rec['url'] ?? ''))),
                'date_adhesion' => $this->dateFrOuIso($rec['date_adhesion'] ?? null),
                'created_at' => now(),
                'updated_at' => now(),
            ];
            if (count($chunk) >= self::BATCH) {
                DB::table('organismes')->insert($chunk);
                $nb += count($chunk);
                $chunk = [];
                if ($tick !== null) {
                    $tick($nb);
                }
            }
        }
        if ($chunk !== []) {
            DB::table('organismes')->insert($chunk);
            $nb += count($chunk);
        }

        $this->registrer('organismes', $nb, (string) config('portail.inpn.versions.organismes'));

        return ['organismes' => $nb];
    }

    /** Dossier du référentiel des organismes (config, repli <inpn.dir>/ORGANISMES). */
    private function dossierOrganismes(): string
    {
        $dossier = (string) config('portail.inpn.organismes_dossier', '');
        if ($dossier !== '' && is_dir($dossier)) {
            return $dossier;
        }
        $repli = $this->dir().'/ORGANISMES';
        if (is_dir($repli)) {
            return $repli;
        }

        throw new RuntimeException('Dossier du référentiel des organismes introuvable (PORTAIL_ORGANISMES_DIR / portail.inpn.organismes_dossier).');
    }

    // ==================================================================
    // Fiches PDF des ZNIEFF (INPN/MNHN)
    // ==================================================================

    /**
     * Fiches PDF ZNIEFF (dump « PDF ZNIEFF » : 19 dossiers régionaux, un
     * PDF par zone nommé {NM_SFFZN}.pdf). Attributs seuls, AUCUN calcul
     * spatial : métadonnées dans `znieff_pdfs` + copie des fichiers dans
     * portail.stockage_pdf (HORS public/ — servis uniquement via la route
     * filtrée /znieff/{id}/fiche.pdf).
     *
     * En mode --dept, le périmètre de table est destructif (comme les
     * autres sources) : seules les ZNIEFF du département restent, et les
     * PDF copiés hors périmètre sont supprimés du stockage local.
     *
     * @param  callable(int): void  $tick
     * @return array{zones: int, pdf: int, octets: int, manquants: int}
     */
    public function importPdf(?string $dept = null, ?callable $tick = null): array
    {
        $dir = $this->dossierPdf();

        // Index du dump : NM_SFFZN => [chemin, dossier_region] (scan
        // récursif des 19 dossiers régionaux, ~20 210 PDF France entière).
        $index = [];
        foreach (glob($dir.'/*/*.pdf', GLOB_NOSORT) ?: [] as $chemin) {
            $id = basename($chemin, '.pdf');
            if (preg_match('/^[0-9A-Za-z]{4,12}$/', $id) === 1 && ! isset($index[$id])) {
                $index[$id] = [$chemin, basename(dirname($chemin))];
            }
        }
        if ($index === []) {
            throw new RuntimeException("Aucun PDF trouvé sous {$dir}/*/*.pdf");
        }

        // Périmètre : zonages ZNIEFF du département (via communes croisées),
        // ou tous les zonages sans --dept.
        $requete = DB::table('zonages')->whereIn('famille', ['z1', 'z2']);
        if ($dept !== null) {
            $requete->whereIn('id', function ($q) use ($dept) {
                $q->select('p.zonage_id')
                    ->from('zonage_communes as p')
                    ->join('communes as c', 'c.insee', '=', 'p.insee')
                    ->where('c.dept', $dept);
            });
        }
        /** @var array<int, string> $idsZonages */
        $idsZonages = $requete->pluck('id')->map(fn ($v) => (string) $v)->all();

        // Mode --dept destructif : on ne garde que le périmètre.
        DB::table('znieff_pdfs')->delete();

        $stockage = (string) config('portail.stockage_pdf');
        if (! is_dir($stockage)) {
            mkdir($stockage, 0775, true);
        }

        $chunk = [];
        $nb = 0;
        $octets = 0;
        $manquants = 0;
        $copies = []; // id => true (pour purger le stockage hors périmètre)
        foreach ($idsZonages as $id) {
            if (! isset($index[$id])) {
                $manquants++;

                continue;
            }
            [$chemin, $dossierRegion] = $index[$id];
            $taille = (int) (filesize($chemin) ?: 0);
            $chunk[] = [
                'zonage_id' => $id,
                'taille_octets' => $taille,
                'dossier_region' => $dossierRegion,
                'nom_fichier' => basename($chemin),
                'created_at' => now(),
                'updated_at' => now(),
            ];
            // Copie locale (écrase) — le filtre de diffusion se fait à la
            // lecture (route znieff.pdf), pas au stockage.
            if (! copy($chemin, $stockage.'/'.$id.'.pdf')) {
                throw new RuntimeException("Copie impossible : {$chemin} → {$stockage}/{$id}.pdf");
            }
            $copies[$id] = true;
            $octets += $taille;
            $nb++;
            if (count($chunk) >= self::BATCH) {
                DB::table('znieff_pdfs')->insert($chunk);
                $chunk = [];
            }
            if ($tick !== null && $nb % 100 === 0) {
                $tick($nb);
            }
        }
        if ($chunk !== []) {
            DB::table('znieff_pdfs')->insert($chunk);
        }

        // Purge du stockage : fichiers hors périmètre (mode --dept).
        foreach (glob($stockage.'/*.pdf', GLOB_NOSORT) ?: [] as $fichier) {
            $id = basename($fichier, '.pdf');
            if (! isset($copies[$id])) {
                @unlink($fichier);
            }
        }

        $this->registrer('pdf', $nb, (string) config('portail.inpn.versions.pdf'));

        return ['zones' => count($idsZonages), 'pdf' => $nb, 'octets' => $octets, 'manquants' => $manquants];
    }

    /** Dossier des fiches PDF ZNIEFF (config, repli <inpn.dir>/PDF ZNIEFF). */
    private function dossierPdf(): string
    {
        $dossier = (string) config('portail.inpn.pdf_dossier', '');
        if ($dossier !== '' && is_dir($dossier)) {
            return $dossier;
        }
        $repli = $this->dir().'/PDF ZNIEFF';
        if (is_dir($repli)) {
            return $repli;
        }

        throw new RuntimeException('Dossier des fiches PDF ZNIEFF introuvable (PORTAIL_PDF_ZNIEFF_DIR / portail.inpn.pdf_dossier).');
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

    private function registrer(string $source, int $nbEntites, ?string $version = null): void
    {
        ReferentielImport::updateOrCreate(
            ['source' => $source],
            [
                'version_source' => $version ?? (string) config("portail.inpn.versions.{$source}"),
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

    /** « 31/12/1969 » (JJ/MM/AAAA, référentiel organismes) → « 1969-12-31 ». */
    private function dateFrOuIso(?string $value): ?string
    {
        $v = trim((string) $value);
        if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $v, $m) === 1) {
            return checkdate((int) $m[2], (int) $m[1], (int) $m[3]) ? $m[3].'-'.$m[2].'-'.$m[1] : null;
        }

        return $this->dateIso($v);
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
