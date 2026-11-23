<?php

namespace Tests\Feature;

use App\Services\InpnImportService;
use App\Support\Especes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\Concerns\SemeFiches;
use Tests\TestCase;

/**
 * Route /znieff/{id}/fiche.pdf : sert la fiche PDF INPN depuis le stockage
 * local (jamais public/), avec le RÈGLE ABSOLUE posée côté requête : une
 * ZNIEFF contenant un taxon confidentiel ou sous sensibilité SINP v18
 * renvoie 404 (les fiches INPN peuvent contenir des localisations précises
 * d'espèces sensibles). La fiche HTML affiche alors la mention SINP.
 */
class ZnieffPdfTest extends TestCase
{
    use RefreshDatabase;
    use SemeFiches;

    private string $stockage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->semeFiches();

        $this->stockage = storage_path('framework/testing/pdf-znieff');
        if (! is_dir($this->stockage)) {
            mkdir($this->stockage, 0775, true);
        }

        config([
            'portail.inpn.pdf_dossier' => base_path('tests/fixtures/inpn-pdf'),
            'portail.stockage_pdf' => $this->stockage,
        ]);

        app(InpnImportService::class)->importPdf('27');
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

    /** Rend la ZNIEFF 230030467 « sensible » (taxon en pré-liste nationale). */
    private function rendZoneSensible(string $zonageId, int $cdRef = 999999): void
    {
        DB::table('znieff_especes')->insert([
            'zonage_id' => $zonageId, 'cd_ref' => $cdRef, 'nom_cite' => 'Taxon sensible',
            'groupe_taxo' => 'Oiseaux', 'determinante' => true, 'confidentielle' => false,
        ]);
        DB::table('sensibilite_especes')->insert([
            'cd_nom' => $cdRef, 'cd_ref' => $cdRef, 'cd_sensibilite' => 2,
            'duree' => null, 'cd_dept' => null, // pré-liste nationale
        ]);
    }

    public function test_pdf_servi_pour_zone_non_sensible(): void
    {
        // 230030467 : espèce déterminante non confidentielle, hors référentiel SINP.
        $this->assertFalse(Especes::zoneSensible('230030467'));
        $reponse = $this->get('/znieff/230030467/fiche.pdf');

        $reponse->assertOk();
        $this->assertSame('application/pdf', $reponse->headers->get('Content-Type'));
        $this->assertStringContainsString('inline', (string) $reponse->headers->get('Content-Disposition'));
        // BinaryFileResponse : on vérifie le fichier réellement servi.
        $this->assertInstanceOf(BinaryFileResponse::class, $reponse->baseResponse);
        $this->assertSame(
            realpath($this->stockage.'/230030467.pdf'),
            $reponse->baseResponse->getFile()->getPathname()
        );
    }

    public function test_pdf_refuse_404_pour_zone_sensible_sinp(): void
    {
        $this->rendZoneSensible('230030467');
        $this->assertTrue(Especes::zoneSensible('230030467'));

        $this->get('/znieff/230030467/fiche.pdf')->assertNotFound();
    }

    public function test_pdf_refuse_404_pour_taxon_confidentiel(): void
    {
        // La graine SemeFiches cite déjà cd_ref 999001 (Rhinolophus ferrumequinum)
        // avec confidentielle = true sur 230009093 : la zone est sensible.
        $this->assertTrue(Especes::zoneSensible('230009093'));

        $this->get('/znieff/230009093/fiche.pdf')->assertNotFound();
    }

    public function test_pdf_refuse_404_quand_zone_sans_pdf(): void
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

        $this->get('/znieff/230099999/fiche.pdf')->assertNotFound();
    }

    public function test_pdf_refuse_404_pour_id_inconnu(): void
    {
        $this->get('/znieff/999999999/fiche.pdf')->assertNotFound();
    }

    public function test_fiche_html_affiche_le_bouton_pdf(): void
    {
        $this->get('/znieff/230030467')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Zonages/Show')
                ->where('pdf.present', true)
                ->where('pdf.sensible', false)
                ->where('pdf.url', route('znieff.pdf', '230030467'))
            );
    }

    public function test_fiche_html_masque_le_bouton_pour_zone_sensible(): void
    {
        // 230009093 déjà sensible via la graine (taxon confidentiel 999001).
        $this->get('/znieff/230009093')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Zonages/Show')
                ->where('pdf.present', true)
                ->where('pdf.sensible', true) // mention « demande au SINP » affichée, jamais le lien PDF
                ->where('pdf.url_sinp', 'https://sinp.naturefrance.fr/')
            );
    }

    public function test_fiche_html_sans_pdf_n_affiche_rien(): void
    {
        DB::table('znieff_pdfs')->where('zonage_id', '230009093')->delete();

        $this->get('/znieff/230009093')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('pdf.present', false)
                ->where('pdf.sensible', false)
            );
    }
}
