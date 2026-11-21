<?php

namespace Tests\Feature;

use App\Models\ReferentielImport;
use App\Services\InpnImportService;
use App\Support\Especes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Import ZNIEFF en mode « attributs seuls » (SQLite, sans PostGIS) : le
 * chemin spatial (intersections, GeoJSON) est validé en local Docker par
 * l'import réel + portail:check — jamais dans la CI GitHub Actions.
 */
class ImportZnieffAttributsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'portail.inpn.dir' => base_path('tests/fixtures/inpn'),
        ]);
    }

    public function test_importe_les_zones_actives_avec_leurs_attributs(): void
    {
        $service = app(InpnImportService::class);
        $retour = $service->importZnieff(null, null, avecGeojson: false);

        $this->assertFalse($retour['spatial']);
        $this->assertEquals(3, $retour['zones']); // la ligne FG_SUPP est filtrée

        $this->assertDatabaseCount('zonages', 3);
        $this->assertDatabaseCount('zonage_communes', 0);

        $z2 = \DB::table('zonages')->where('id', '230009093')->first();
        $this->assertNotNull($z2);
        $this->assertSame('z2', $z2->famille);
        $this->assertSame('8526', $z2->num_regional);
        $this->assertEqualsWithDelta(6557.68, (float) $z2->superficie_ref_ha, 0.001);
        $this->assertSame('1982-01-01', $z2->date_crea);
        $this->assertSame('2024-07-15', $z2->date_modif_geo);
        $this->assertSame('https://inpn.mnhn.fr/zone/znieff/230009093', $z2->url_inpn);
        $this->assertNull($z2->id_parent);

        $z1 = \DB::table('zonages')->where('id', '230030467')->first();
        $this->assertSame('z1', $z1->famille);
        $this->assertSame('85260005', $z1->num_regional);
        $this->assertEqualsWithDelta(338.31, (float) $z1->superficie_ref_ha, 0.001);
        $this->assertSame('230009093', $z1->id_parent, 'la ZNIEFF I est rattachée à sa ZNIEFF II');
    }

    public function test_journalise_le_referentiel_importe(): void
    {
        app(InpnImportService::class)->importZnieff(null, null, avecGeojson: false);

        $ligne = ReferentielImport::query()->where('source', 'znieff')->first();
        $this->assertNotNull($ligne);
        $this->assertSame((string) config('portail.inpn.versions.znieff'), $ligne->version_source);
        $this->assertEquals(3, $ligne->nb_entites);
    }

    public function test_est_idempotent(): void
    {
        $service = app(InpnImportService::class);
        $service->importZnieff(null, null, avecGeojson: false);
        $service->importZnieff(null, null, avecGeojson: true); // geojson ignoré sans PostGIS

        $this->assertSame(3, \DB::table('zonages')->count(), 'recharger ne duplique rien');
    }

    /**
     * REF_ESPECE.csv (fixture) : les espèces du périmètre sont importées
     * avec leurs flags — y compris l'espèce CONFIDENTIELLE, qui reste en
     * base avec `confidentielle = true` mais ne doit jamais être lue
     * (filtre absolu posé dans App\Support\Especes, testé ci-dessous).
     */
    public function test_importe_les_especes_avec_leur_flag_confidentielle(): void
    {
        $retour = app(InpnImportService::class)->importZnieff(null, null, avecGeojson: false);

        $this->assertSame(5, $retour['especes'], 'la ligne FG_SUPP=true est filtrée');
        $this->assertDatabaseCount('znieff_especes', 5);

        $conf = \DB::table('znieff_especes')->where('cd_ref', 999001)->first();
        $this->assertNotNull($conf);
        $this->assertSame('230009093', $conf->zonage_id);
        $this->assertEquals(1, (int) $conf->confidentielle, 'flag confidentielle conservé en base');
        $this->assertEquals(0, (int) $conf->determinante);

        $det = \DB::table('znieff_especes')->where('cd_ref', 3161)->first();
        $this->assertEquals(1, (int) $det->determinante);
        $this->assertEquals(0, (int) $det->confidentielle);
        $this->assertSame('<i>Pluvialis apricaria</i> (Linnaeus, 1758)', $det->nom_cite);

        // Lecture publique : seules les déterminantes NON confidentielles sortent.
        $visibles = Especes::especesZonagePagine('230009093', 1, 25);
        $this->assertSame(2, $visibles['total']);
        $noms = array_column($visibles['items'], 'nom');
        $this->assertContains('Miniopterus schreibersii (Kuhl, 1817)', $noms);
        $this->assertNotContains('Rhinolophus ferrumequinum', $noms, 'confidentielle jamais listée');
    }

    /**
     * Noms vernaculaires TAXREF : joints à l'import — préférence au fichier
     * dédicacé (TAXVERNv18, fr + eng mélangés), langue française seulement
     * (LANGUE = français / ISO639_3 = fra) : l'anglais ne prime jamais,
     * quelle que soit l'ordre des lignes, et la variante qui suit non plus
     * (première langue gardée par cd_nom — jamais de tri arbitraire entre
     * les 4 216 taxons gardant des variantes régionales). cdNom absent du
     * référentiel cité (999002) : ignoré. Version « — v18 » créditée dans
     * referentiel_imports (piied de page).
     */
    public function test_joint_les_noms_vernaculaires_taxvern(): void
    {
        config(['portail.inpn.taxref_dossier' => base_path('tests/fixtures/inpn/taxref')]);

        $retour = app(InpnImportService::class)->importZnieff(null, null, avecGeojson: false);
        $this->assertSame(5, $retour['especes']);

        // Les noms français suivent le cd_ref (taxon accepté), jamais le nom cité.
        // 60400 appartient à la zone voisine (230030467), lue plus bas.
        $vern = \DB::table('znieff_especes')->where('zonage_id', '230009093')->pluck('nom_vernaculaire', 'cd_ref');
        $this->assertSame('Pluvier doré', $vern[3161], 'l anglais (même cd_nom, ligne d avant) ne prime pas');
        $this->assertSame('Minioptère de Schreibers', $vern[1308]);
        $this->assertSame('Triton crêté', \DB::table('znieff_especes')->where('zonage_id', '230030467')->value('nom_vernaculaire'));
        $this->assertSame('Grand rhinolophe', $vern[999001], 'confidentielle : jointe en base (fidèle au référentiel), jamais lue — le filtre absolu d Especes s en charge');

        // Lecture des fiches : `vern` officiel à côté du nom latin.
        $especes = Especes::especesZonagePagine('230009093', 1, 25);
        $this->assertSame('Minioptère de Schreibers', $especes['items'][0]['vern']);
        $this->assertSame('Triton crêté', Especes::especesZonagePagine('230030467', 1, 25)['items'][0]['vern']);

        // Version « — v18 » créditée dans le journal des imports (piied de page).
        $taxref = ReferentielImport::query()->where('source', 'taxref')->first();
        $this->assertNotNull($taxref);
        $this->assertSame((string) config('portail.inpn.versions.taxref').' — v18', $taxref->version_source);
        $this->assertSame(5, $taxref->nb_entites, 'tous les cd_nom cités du périmètre (langue française incluse)');
    }

    /**
     * Repli : distribution ancienne (NOM_VERN de TAXREF, PORTAIL_TAXREF_
     * FICHIER explicite) — sans TAXVERN dans le dossier, NOM_VERN répond ;
     * le latin seul quand la colonne est vide ou absente.
     */
    public function test_avec_taxref_ic_nom_vern_repli(): void
    {
        config([
            'portail.inpn.taxref_fichier' => base_path('tests/fixtures/inpn/TAXREF.txt'),
        ]);

        app(InpnImportService::class)->importZnieff(null, null, avecGeojson: false);

        $vern = \DB::table('znieff_especes')->where('zonage_id', '230009093')->pluck('nom_vernaculaire', 'cd_ref');
        $this->assertSame('Pluvier doré', $vern[3161]);
        $this->assertSame('Minioptère de Schreibers', $vern[1308]);
        $this->assertSame('Triton crêté', \DB::table('znieff_especes')->where('zonage_id', '230030467')->value('nom_vernaculaire'));
        $this->assertNull($vern[999001], 'confidentielle hors TAXREF : latin seul');

        // Sans suffixe de version lisible : version configurée statique.
        $taxref = ReferentielImport::query()->where('source', 'taxref')->first();
        $this->assertSame(3, $taxref->nb_entites, 'seuls les NOM_VERN non vides sont retenus');
        $this->assertSame((string) config('portail.inpn.versions.taxref'), $taxref->version_source);
    }

    /**
     * TAXREF jamais téléchargé (conditions INPN non acceptées) : l'import
     * reste fonctionnel, nom_vernaculaire null partout, aucun crédit
     * journalisé — l'absence est tracée par un avertissement, jamais une
     * valeur improvisée.
     */
    public function test_importe_sans_taxref_ic_le_latin_seul(): void
    {
        config([
            // éviction explicite ET du vrai dossier (data/inpn-data/taxref,
            // présent quandmême en local) : aucun fichier ne doit sortir
            'portail.inpn.taxref_fichier' => base_path('tests/fixtures/inpn/absent.txt'),
            'portail.inpn.taxref_dossier' => base_path('tests/fixtures/inpn/absent-dir'),
        ]);

        $retour = app(InpnImportService::class)->importZnieff(null, null, avecGeojson: false);
        $this->assertSame(5, $retour['especes'], 'l absence de TAXREF ne bloque pas les espèces importées');

        $this->assertSame(0, \DB::table('znieff_especes')->whereNotNull('nom_vernaculaire')->count());
        $this->assertNull(ReferentielImport::query()->where('source', 'taxref')->first());
    }
}
