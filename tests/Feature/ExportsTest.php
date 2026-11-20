<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SemeFiches;
use Tests\Concerns\SemeListes;
use Tests\TestCase;

/**
 * Exports des listes filtrées (itération d) : CSV (point-virgule,
 * BOM UTF-8, URL des fiches) et GeoJSON (géométries précalculées de
 * public/geo — jamais recalculées, jamais en base). Sous conditions
 * INPN rappelées dans l'interface.
 *
 * Les assertions portent sur des fragments de champs (l'enclosure RFC
 * de fputcsv et le schéma/hôte des URL ne sont pas contractualisés).
 */
class ExportsTest extends TestCase
{
    use RefreshDatabase;
    use SemeFiches;
    use SemeListes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->semeFiches();
        $this->semeListes();
    }

    protected function tearDown(): void
    {
        $this->nettoieStubsGeo();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // CSV
    // ------------------------------------------------------------------

    public function test_export_csv_des_communes(): void
    {
        $reponse = $this->get('/communes?export=csv');

        $reponse->assertOk();
        $reponse->assertDownload();
        $reponse->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $csv = $reponse->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBFinsee;commune;dept;departement;region", $csv);
        $this->assertStringContainsString('27375;Louviers;27;Eure;Normandie;18347;2710,00;27400;2;1;1;oui', $csv);
        $this->assertStringContainsString('27003;Acquigny;27;Eure;Normandie;1725;1780,00;27400;1;0;0;non', $csv);
        $this->assertStringContainsString('/commune/louviers', $csv);
        // 33 lignes : les 30 fictives aussi (export complet de France)
        $this->assertSame(33, substr_count($csv, "\n") - 1);
    }

    public function test_export_csv_des_znieff(): void
    {
        $csv = $this->get('/znieff?dept=27&export=csv')->streamedContent();

        $this->assertStringContainsString('id_znieff;num_regional;type;nom', $csv);
        $this->assertStringContainsString('230009093;8526;II;', $csv);
        $this->assertStringContainsString('Forêt de Bord et de Louviers', $csv);
        $this->assertStringContainsString('230030467;85260005;I;', $csv);
        // dates ISO, URL INPN et URL de la fiche du portail
        $this->assertStringContainsString(';1982-01-01;2024-07-15;https://inpn.mnhn.fr/zone/znieff/230009093;http', $csv);
        $this->assertStringContainsString('/znieff/230009093', $csv);
    }

    public function test_export_csv_natura_et_aires(): void
    {
        $natura = $this->get('/natura?dept=27&export=csv')->streamedContent();
        $this->assertStringContainsString('code_natura2000;type;nom', $natura);
        $this->assertStringContainsString('FR2300128;SIC;', $natura);
        $this->assertStringContainsString("La Vallée de l'Eure", $natura);
        $this->assertStringContainsString('728,50;1;', $natura);
        $this->assertStringContainsString('/natura/FR2300128', $natura);

        $ap = $this->get('/ap?dept=27&export=csv')->streamedContent();
        $this->assertStringContainsString('id;type_code;type;nom', $ap);
        $this->assertStringContainsString('FR3800583;APPB;', $ap);
        $this->assertStringContainsString('Arrêté de protection de biotope, habitat ou site', $ap);
        $this->assertStringContainsString('Bois de la hete', $ap);
        $this->assertStringContainsString(';12,50;1;', $ap);
        $this->assertStringContainsString('Arrêté préfectoral du 12/02/1990', $ap);
        $this->assertStringContainsString("Préfecture de l'Eure", $ap);
        $this->assertStringContainsString('/ap/FR3800583', $ap);
    }

    // ------------------------------------------------------------------
    // GeoJSON
    // ------------------------------------------------------------------

    public function test_export_geojson_sans_filtre_refuse_avec_message(): void
    {
        $this->get('/communes?export=geojson')->assertStatus(400);
        $this->get('/znieff?export=geojson')->assertStatus(400);
        $this->get('/natura?export=geojson')->assertStatus(400);
        $this->get('/ap?export=geojson')->assertStatus(400);

        // le type est un périmètre acceptable pour les ZNIEFF et les AP
        $this->get('/znieff?type=z1&export=geojson')->assertOk();
        $this->get('/ap?type=APPB&export=geojson')->assertOk();
    }

    public function test_export_geojson_des_communes_dun_departement(): void
    {
        $this->garantitGeojson('communes/27375');
        $this->garantitGeojson('communes/27003');
        $this->garantitGeojson('communes/27558');

        $reponse = $this->get('/communes?dept=27&export=geojson');
        $reponse->assertOk();
        $reponse->assertHeader('Content-Type', 'application/geo+json; charset=UTF-8');

        $geo = json_decode($reponse->streamedContent(), true);

        $this->assertSame('FeatureCollection', $geo['type'] ?? null);
        $this->assertCount(3, $geo['features'] ?? []);
        $this->assertSame('27003', $geo['features'][0]['properties']['insee'] ?? null, 'ordre alphabétique : Acquigny d\'abord');

        foreach ($geo['features'] ?? [] as $feature) {
            $this->assertNotNull($feature['geometry'], 'La géométrie vient du précalcul public/geo, jamais recalculée');
            $this->assertStringContainsString('/commune/', (string) $feature['properties']['fiche']);
        }
    }

    public function test_export_geojson_des_znieff_avec_geometries_precalculees(): void
    {
        $this->garantitGeojson('zonages/z2/230009093');
        $this->garantitGeojson('zonages/z1/230030467');

        $geo = json_decode($this->get('/znieff?dept=27&export=geojson')->streamedContent(), true);

        $this->assertSame('FeatureCollection', $geo['type'] ?? null);
        $this->assertCount(2, $geo['features'] ?? []);
        $this->assertSame('230009093', $geo['features'][0]['properties']['id_znieff'] ?? null);
        $this->assertNotNull($geo['features'][0]['geometry']);
        $this->assertSame('8526', $geo['features'][0]['properties']['num_regional'] ?? null);
    }

    public function test_export_geojson_natura_et_aires(): void
    {
        $this->garantitGeojson('natura/FR2300128');
        $this->garantitGeojson('ap/FR3800583');

        $natura = json_decode($this->get('/natura?dept=27&export=geojson')->streamedContent(), true);
        $this->assertSame('FR2300128', $natura['features'][0]['properties']['code_natura2000'] ?? null);
        $this->assertNotNull($natura['features'][0]['geometry']);

        $ap = json_decode($this->get('/ap?dept=27&export=geojson')->streamedContent(), true);
        $this->assertSame('FR3800583', $ap['features'][0]['properties']['id'] ?? null);
        $this->assertNotNull($ap['features'][0]['geometry']);
    }
}
