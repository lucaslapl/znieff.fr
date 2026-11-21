<?php

namespace Tests\Feature;

use App\Models\ReferentielImport;
use App\Services\InpnImportService;
use App\Support\Especes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Import Natura 2000 en mode « attributs seuls » (SQLite, sans PostGIS) :
 * sites (biotop + info_sig) + espèces du FSD (species.csv.csv, enrichies
 * dhff_especes / code_do_oiseaux, statuts fusionnés) + habitats (habit1,
 * enrichi dhff_habitats). Le chemin spatial est validé en local Docker.
 */
class ImportNaturaAttributsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'portail.inpn.dir' => base_path('tests/fixtures/inpn'),
        ]);
    }

    public function test_importe_sites_especes_et_habitats(): void
    {
        $retour = app(InpnImportService::class)->importNatura(null, null, avecGeojson: false);

        $this->assertFalse($retour['spatial']);
        $this->assertSame(2, $retour['sites']);
        $this->assertSame(3, $retour['especes'], 'A136 dédoublonné (statuts r+w fusionnés), ligne date_supp ignorée');
        $this->assertSame(3, $retour['habitats'], 'doublon habit1 ignoré');

        $this->assertDatabaseCount('natura_sites', 2);
        $this->assertDatabaseCount('natura_especes', 3);
        $this->assertDatabaseCount('natura_habitats', 3);

        // Espèce oiseau A136 : enrichie du référentiel code_do_oiseaux,
        // statuts FSD fusionnés dans `presences`.
        $oiseau = \DB::table('natura_especes')->where('site_code', 'FR2300128')->where('code_n2000', 'A136')->first();
        $this->assertNotNull($oiseau);
        $this->assertSame('Pluvialis apricaria', $oiseau->nom_scientifique);
        $this->assertSame('Pluvier doré', $oiseau->nom_vernaculaire);
        $this->assertEquals(1, (int) $oiseau->annexe_1, 'annexe I directive Oiseaux');
        $this->assertEquals(0, (int) $oiseau->annexe_ii);
        $this->assertSame('r,w', $oiseau->presences);

        // Espèce directive Habitats 1308 : annexe II depuis le FSD + dhff.
        $mammifere = \DB::table('natura_especes')->where('code_n2000', '1308')->first();
        $this->assertEquals(1, (int) $mammifere->annexe_ii);
        $this->assertSame('Miniopterus schreibersii', $mammifere->nom_scientifique);

        // Habitat 2110 : nom + prioritaire depuis dhff_habitats ; notations
        // FSD (represent/conserve/global) dans les bonnes colonnes.
        $hab = \DB::table('natura_habitats')->where('site_code', 'FR2300128')->where('cd_ue', '2110')->first();
        $this->assertSame('Dunes mobiles du cordon littoral à Ammophila arenaria', $hab->nom);
        $this->assertEquals(1, (int) $hab->prioritaire);
        $this->assertSame('B', $hab->representativite);
        $this->assertSame('C', $hab->conservation, 'structure & fonctions (champ conserve)');
        $this->assertSame('C', $hab->evaluation_globale, 'évaluation globale (champ global)');

        // Lecture publique paginée.
        $especes = Especes::especesNaturaPagine('FR2300128', 1, 25);
        $this->assertSame(2, $especes['total']);
        $habitats = Especes::habitatsNaturaPagine('FR2300128', 1, 25);
        $this->assertSame(2, $habitats['total']);
    }

    public function test_journalise_le_referentiel_natura(): void
    {
        app(InpnImportService::class)->importNatura(null, null, avecGeojson: false);

        $ligne = ReferentielImport::query()->where('source', 'natura')->first();
        $this->assertNotNull($ligne);
        $this->assertSame((string) config('portail.inpn.versions.natura'), $ligne->version_source);
        $this->assertEquals(2, $ligne->nb_entites);
    }
}
