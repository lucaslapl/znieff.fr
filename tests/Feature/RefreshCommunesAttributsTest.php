<?php

namespace Tests\Feature;

use App\Models\ReferentielImport;
use App\Services\CommunesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rafraîchissement du référentiel communal en mode sans PostGIS
 * (attributs AdminExpress seuls) : slugs, départements, régions,
 * population, superficie INSEE, filtrage des codes hors COG.
 */
class RefreshCommunesAttributsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'portail.inpn.dir' => base_path('tests/fixtures/inpn'),
            'portail.inpn.admin_express_dir' => base_path('tests/fixtures/adminexpress'),
        ]);
    }

    public function test_importe_les_communes_et_les_nommes(): void
    {
        $service = app(CommunesService::class);
        $retour = $service->refresh(null, null, avecGeojson: false, avecCodesPostaux: false, avecAbc: false);

        $this->assertFalse($retour['spatial']);
        $this->assertEquals(4, $retour['communes'], 'les 4 communes valides (le code « NR » est ignoré)');
        $this->assertEquals(1, $retour['ignores']);

        $louviers = \DB::table('communes')->where('insee', '27375')->first();
        $this->assertNotNull($louviers);
        $this->assertSame('louviers', $louviers->slug);
        $this->assertSame('27', $louviers->dept);
        $this->assertSame('Eure', $louviers->dept_nom);
        $this->assertSame('28', $louviers->reg_code);
        $this->assertSame('Normandie', $louviers->reg_nom);
        $this->assertEquals(18347, $louviers->population);
        $this->assertEqualsWithDelta(2710.0, (float) $louviers->superficie_ha, 0.01);

        // DROM : département 971 (3 caractères), région Guadeloupe
        $abymes = \DB::table('communes')->where('insee', '97101')->first();
        $this->assertSame('971', $abymes->dept);
        $this->assertSame('Guadeloupe', $abymes->dept_nom);
        $this->assertSame('Guadeloupe', $abymes->reg_nom);

        $this->assertDatabaseCount('communes', 4);
        $this->assertDatabaseMissing('communes', ['insee' => 'NR']);
    }

    public function test_est_idempotent_et_stable(): void
    {
        $service = app(CommunesService::class);
        $service->refresh(null, null, avecGeojson: false, avecCodesPostaux: false, avecAbc: false);
        $slugsAvant = \DB::table('communes')->orderBy('insee')->pluck('slug')->all();
        $service->refresh(null, null, avecGeojson: false, avecCodesPostaux: false, avecAbc: false);
        $slugsApres = \DB::table('communes')->orderBy('insee')->pluck('slug')->all();

        $this->assertSame($slugsAvant, $slugsApres, 'les slugs ne bougent pas au rechargement');
        $this->assertDatabaseCount('communes', 4);
    }

    public function test_filtre_par_departement(): void
    {
        $service = app(CommunesService::class);
        $retour = $service->refresh('27', null, avecGeojson: false, avecCodesPostaux: false, avecAbc: false);

        $this->assertEquals(3, $retour['communes']);
        $this->assertDatabaseCount('communes', 3);
        $this->assertDatabaseHas('communes', ['insee' => '27375']);

        $ligne = ReferentielImport::query()->where('source', 'adminexpress')->first();
        $this->assertNotNull($ligne);
    }
}
