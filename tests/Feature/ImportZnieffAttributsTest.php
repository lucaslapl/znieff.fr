<?php

namespace Tests\Feature;

use App\Models\ReferentielImport;
use App\Services\InpnImportService;
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
}
