<?php

namespace Tests\Feature;

use App\Models\ReferentielImport;
use App\Services\InpnImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SemeFiches;
use Tests\TestCase;

/**
 * Import HABREF v7.0 (source `habref`) : fixtures tests/fixtures/inpn-habref,
 * graine SemeFiches (site Natura FR2300128 sur Louviers 27375, habitat
 * cd_ue 2110 avec cd_hab 2682). TAXREF fixture pour la résolution
 * cd_nom → cd_ref (via importBdc, qui alimente taxons_ref).
 */
class ImportHabrefTest extends TestCase
{
    use RefreshDatabase;
    use SemeFiches;

    protected function setUp(): void
    {
        parent::setUp();
        $this->semeFiches();

        config([
            'portail.inpn.habref_dossier' => base_path('tests/fixtures/inpn-habref'),
            'portail.inpn.taxref_dossier' => base_path('tests/fixtures/inpn-taxref'),
        ]);

        // taxons_ref (jointure des taxons caractéristiques visibles).
        app(InpnImportService::class)->importBdc();
    }

    public function test_importe_unites_corresp_taxons_et_territoires(): void
    {
        $retour = app(InpnImportService::class)->importHabref(null);

        $this->assertSame(1, $retour['hic']);        // cd_hab 2682 (FSD FR2300128)
        $this->assertSame(6, $retour['unites']);     // 2682 + 9999 + 7777 + parents 831, 197, 8888
        $this->assertSame(3, $retour['corresp']);    // 2682↔9999, 2682↔7777, 9999↔2682
        $this->assertSame(4, $retour['taxons']);     // types retenus (19, 39, 26, 20), type 99 exclu
        $this->assertSame(1, $retour['taxons_sans_cdref']); // cd_nom 424242 hors TAXREF

        $this->assertDatabaseCount('habref_habitats', 6);
        $h = DB::table('habref_habitats')->where('cd_hab', 2682)->first();
        $this->assertSame(8, (int) $h->cd_typo);
        $this->assertSame('2110', $h->lb_code);
        $this->assertSame('Dunes mobiles du cordon littoral à Ammophila arenaria', $h->nom_fr);
        $this->assertSame('Embryonic shifting dunes', $h->nom_en);
        $this->assertSame(831, (int) $h->cd_hab_sup);
        $this->assertSame('TERFXFR', $h->territoires); // statut A (TERFX971) ignoré

        $this->assertDatabaseCount('habref_corresp', 3);
        $this->assertDatabaseCount('habref_taxons', 4);
        $t = DB::table('habref_taxons')->where('cd_hab', 2682)->where('cd_nom', 3161)->first();
        $this->assertSame(3161, (int) $t->cd_ref); // résolu via TAXREF v18
        $this->assertSame('Espèce caractéristique', $t->type_libelle);
        $this->assertNull(DB::table('habref_taxons')->where('cd_nom', 424242)->value('cd_ref'));

        $this->assertSame(6, (int) ReferentielImport::where('source', 'habref')->value('nb_entites'));
    }

    public function test_mode_dept_27_garde_le_perimetre(): void
    {
        $retour = app(InpnImportService::class)->importHabref('27');
        $this->assertSame(1, $retour['hic']);
        $this->assertSame(6, $retour['unites']);
    }

    public function test_mode_dept_sans_natura_leve_une_erreur(): void
    {
        $this->expectException(\RuntimeException::class);
        app(InpnImportService::class)->importHabref('28'); // aucune commune du 28 en base
    }
}
