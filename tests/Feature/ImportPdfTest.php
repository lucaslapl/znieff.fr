<?php

namespace Tests\Feature;

use App\Models\ReferentielImport;
use App\Services\InpnImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SemeFiches;
use Tests\TestCase;

/**
 * Import des fiches PDF ZNIEFF (source `pdf`) : fixtures
 * tests/fixtures/inpn-pdf (2 faux PDF dans 28_Normandie), graine SemeFiches
 * (Louviers 27375, zonages 230009093 et 230030467 en base).
 *
 * Le stockage local des PDF est redirigé vers un dossier de test ; les PDF
 * ne sont jamais servis en statique (route filtrée znieff.pdf, testée dans
 * ZnieffPdfTest).
 */
class ImportPdfTest extends TestCase
{
    use RefreshDatabase;
    use SemeFiches;

    private string $stockage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->semeFiches();

        $this->stockage = storage_path('framework/testing/pdf-znieff');
        if (is_dir($this->stockage)) {
            foreach (glob($this->stockage.'/*.pdf') ?: [] as $f) {
                @unlink($f);
            }
        }

        config([
            'portail.inpn.pdf_dossier' => base_path('tests/fixtures/inpn-pdf'),
            'portail.stockage_pdf' => $this->stockage,
        ]);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->stockage)) {
            foreach (glob($this->stockage.'/*.pdf') ?: [] as $f) {
                @unlink($f);
            }
        }
        parent::tearDown();
    }

    public function test_importe_metadonnees_et_copie_les_pdf(): void
    {
        $retour = app(InpnImportService::class)->importPdf(null);

        $this->assertSame(2, $retour['zones']);   // les 2 zonages de la graine
        $this->assertSame(2, $retour['pdf']);     // 2 PDF trouvés dans le dump
        $this->assertSame(0, $retour['manquants']);
        $this->assertGreaterThan(0, $retour['octets']);

        $this->assertDatabaseCount('znieff_pdfs', 2);
        $ligne = DB::table('znieff_pdfs')->where('zonage_id', '230009093')->first();
        $this->assertNotNull($ligne);
        $this->assertSame('28_Normandie', $ligne->dossier_region);
        $this->assertSame('230009093.pdf', $ligne->nom_fichier);
        $this->assertSame(73, (int) $ligne->taille_octets);

        // Fichiers copiés dans le stockage local (hors public/).
        $this->assertFileExists($this->stockage.'/230009093.pdf');
        $this->assertFileExists($this->stockage.'/230030467.pdf');
        $this->assertStringStartsWith('%PDF-1.4', (string) file_get_contents($this->stockage.'/230009093.pdf'));

        // Journal des référentiels.
        $this->assertSame(2, (int) ReferentielImport::where('source', 'pdf')->value('nb_entites'));
    }

    public function test_mode_dept_destructif_purge_table_et_stockage(): void
    {
        $service = app(InpnImportService::class);
        $service->importPdf(null);
        $this->assertDatabaseCount('znieff_pdfs', 2);
        $this->assertFileExists($this->stockage.'/230009093.pdf');

        // Département sans aucune commune en base : périmètre vide, purge.
        $retour = $service->importPdf('28');
        $this->assertSame(0, $retour['zones']);
        $this->assertSame(0, $retour['pdf']);
        $this->assertDatabaseCount('znieff_pdfs', 0);
        $this->assertFileDoesNotExist($this->stockage.'/230009093.pdf');
        $this->assertFileDoesNotExist($this->stockage.'/230030467.pdf');
    }

    public function test_mode_dept_27_garde_les_zonages_du_perimetre(): void
    {
        $retour = app(InpnImportService::class)->importPdf('27');

        $this->assertSame(2, $retour['zones']); // les 2 zonages touchent le 27
        $this->assertSame(2, $retour['pdf']);
        $this->assertDatabaseCount('znieff_pdfs', 2);
    }

    public function test_zone_sans_pdf_dans_le_dump_est_comptee_manquante(): void
    {
        DB::table('zonages')->insert([
            'id' => '230099999', 'famille' => 'z1', 'nom' => 'Zone sans PDF',
            'num_regional' => '99999999', 'id_parent' => null,
            'superficie_ha' => 10.0, 'superficie_ref_ha' => 10.0,
            'precision_saisie' => '1/25 000', 'date_crea' => '2010-01-01',
            'date_modif_geo' => '2024-07-15', 'territoire' => 'metrop', 'volet' => 'CONT',
            'url_inpn' => 'https://inpn.mnhn.fr/zone/znieff/230099999',
        ]);
        DB::table('zonage_communes')->insert([
            'zonage_id' => '230099999', 'insee' => '27375', 'surface_ha' => 10.0, 'part_zonage' => 100.0, 'part_commune' => 0.37,
        ]);

        $retour = app(InpnImportService::class)->importPdf('27');

        $this->assertSame(3, $retour['zones']);
        $this->assertSame(2, $retour['pdf']);
        $this->assertSame(1, $retour['manquants']);
        $this->assertDatabaseMissing('znieff_pdfs', ['zonage_id' => '230099999']);
    }
}
