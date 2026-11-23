<?php

namespace Tests\Feature;

use App\Models\ReferentielImport;
use App\Services\InpnImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Référentiel des organismes adhérents au SINP (source `organismes`) :
 * fixtures tests/fixtures/inpn-organismes (3 organismes), page /organismes
 * (liste paginée serveur, recherche, filtre domaine). Les coordonnées
 * personnelles (mail, téléphone, adresse, SIRET) ne sont JAMAIS importées.
 */
class OrganismesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['portail.inpn.organismes_dossier' => base_path('tests/fixtures/inpn-organismes')]);
        app(InpnImportService::class)->importOrganismes();
    }

    public function test_import_complet_sans_coordonnees_personnelles(): void
    {
        $this->assertDatabaseCount('organismes', 3);
        $o = DB::table('organismes')->where('id', 'AAA-111')->first();
        $this->assertSame('CBN NORMANDIE', $o->libelle_court);
        $this->assertSame('Flore | Habitats', $o->domaines);
        $this->assertSame('Normandie', $o->zones);
        $this->assertSame('Adhérent', $o->niveau_adhesion);
        $this->assertSame('https://cbn.fr', $o->url);
        $this->assertSame('2005-03-15', substr((string) $o->date_adhesion, 0, 10)); // JJ/MM/AAAA → ISO

        // Aucune colonne de coordonnées personnelles dans la table.
        $colonnes = DB::getSchemaBuilder()->getColumnListing('organismes');
        foreach (['mail', 'telephone', 'adresse', 'cp', 'ville', 'siret'] as $interdit) {
            $this->assertNotContains($interdit, $colonnes);
        }

        $this->assertSame(3, (int) ReferentielImport::where('source', 'organismes')->value('nb_entites'));
    }

    public function test_page_liste_paginee(): void
    {
        $this->get('/organismes')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Organismes/Index')
                ->where('pagination.total', 3)
                ->has('organismes', 3)
                ->where('organismes.0.libelle_court', 'ASSO FAUNE 27') // tri libellé
            );
    }

    public function test_recherche_et_filtre_domaine(): void
    {
        $this->get('/organismes?q=cbn')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('pagination.total', 1)
                ->where('organismes.0.id', 'AAA-111')
            );

        $this->get('/organismes?domaine=Flore')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('pagination.total', 2) // CBN + DREAL
            );
    }

    public function test_page_dans_le_sitemap_pages(): void
    {
        $this->get('/sitemaps/pages-1.xml')
            ->assertOk()
            ->assertSee('/organismes', false);
    }
}
