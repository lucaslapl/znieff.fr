<?php

namespace Tests\Feature;

use App\Services\InpnImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\SemeFiches;
use Tests\TestCase;

/**
 * Fiches habitats HABREF v7.0 : /habitats (liste paginée, recherche,
 * filtre typologie) et /habitat/{cd_hab} (unité, hiérarchie,
 * correspondances, espèces caractéristiques VISIBLES — filtre absolu côté
 * requête, App\Support\Habitats — et sites Natura 2000 du périmètre).
 * Lien croisé depuis la fiche espèce (/espece/{cd_ref} → habitats).
 */
class HabitatFicheTest extends TestCase
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
            // importBdc : sans cette clé, dossierBdc retombe sur <inpn.dir>/BDC_18
            // (absent en CI — les données INPN brutes ne sont pas dans le dépôt).
            'portail.inpn.bdc_dossier' => base_path('tests/fixtures/inpn-bdc'),
        ]);

        $service = app(InpnImportService::class);
        $service->importBdc();   // taxons_ref (jointure du filtre des taxons visibles)
        $service->importHabref('27');
    }

    public function test_fiche_hic_avec_hierarchie_corresp_taxons_visibles_et_sites(): void
    {
        $this->get('/habitat/2682')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Habitats/Show')
                ->where('habitat.cd_hab', 2682)
                ->where('habitat.cd_typo', 8)
                ->where('habitat.typo', 'HIC — Natura 2000 (EUR)')
                ->where('habitat.lb_code', '2110')
                ->where('habitat.nom_fr', 'Dunes mobiles du cordon littoral à Ammophila arenaria')
                ->where('habitat.nom_en', 'Embryonic shifting dunes')
                ->where('habitat.parent.cd_hab', 831)
                ->where('habitat.territoires', 'TERFXFR')
                ->has('habitat.corresp', 2) // 9999 (EUNIS) et 7777 (CORINE)
                // Filtre absolu : 999001 (confidentiel) et 424242 (sans cd_ref) JAMAIS affichés.
                ->where('nbTaxons', 1)
                ->where('taxons.0.cd_ref', 3161)
                ->where('taxons.0.nom', 'Pluvialis apricaria')
                ->where('taxons.0.type', 'Espèce caractéristique')
                ->where('nbSites', 1)
                ->where('sites.0.code', 'FR2300128')
                ->where('meta.canonical', route('habitat.show', 2682))
            );
    }

    public function test_fiche_eunis_correspondante_avec_ses_propres_taxons_et_sites(): void
    {
        $this->get('/habitat/9999')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Habitats/Show')
                ->where('habitat.cd_typo', 7)
                ->where('habitat.typo', 'EUNIS 2012')
                ->where('habitat.lb_code', 'B1.1')
                ->where('habitat.parent.cd_hab', 8888)
                ->where('nbTaxons', 1) // Pluvialis apricaria (type 20 = diagnostique)
                ->where('taxons.0.cd_ref', 3161)
                ->where('taxons.0.type', 'Espèce diagnostique')
                ->where('nbSites', 1) // FR2300128 via la correspondance directe 2682↔9999
            );
    }

    public function test_fiche_inconnue_404(): void
    {
        $this->get('/habitat/123456')->assertNotFound();
    }

    public function test_liste_habitats_avec_recherche_et_filtre_typologie(): void
    {
        $this->get('/habitats')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Habitats/Index')
                ->where('pagination.total', 6)
            );

        $this->get('/habitats?type=8')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('pagination.total', 3) // 197, 831, 2682
            );

        $this->get('/habitats?q=dunes')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('pagination.total', 5) // 197, 831, 2682, 8888, 9999
            );
    }

    public function test_fiche_espece_liste_ses_habitats_caracteristiques(): void
    {
        $this->get('/espece/3161')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Especes/Show')
                ->where('nbHabitats', 2) // 2682 (caract.) et 9999 (diagn.)
                ->has('habitats', 2)
            );
    }

    public function test_sitemap_lot_habitats(): void
    {
        $this->get('/sitemaps/habitats-1.xml')
            ->assertOk()
            ->assertSee('/habitat/2682', false)
            ->assertSee('/habitat/9999', false);
    }
}
