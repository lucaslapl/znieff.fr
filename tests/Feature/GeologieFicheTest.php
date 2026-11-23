<?php

namespace Tests\Feature;

use App\Services\InpnImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\SemeFiches;
use Tests\TestCase;

/**
 * Fiches INPG : /geologie (liste paginée) et /geologie/{identifiant_metier}.
 * Un site confidentiel (niv_diffusion = 1) renvoie 404 — jamais de fiche,
 * jamais dans le sitemap, jamais dans la liste.
 */
class GeologieFicheTest extends TestCase
{
    use RefreshDatabase;
    use SemeFiches;

    protected function setUp(): void
    {
        parent::setUp();
        $this->semeFiches();

        config([
            'portail.inpn.inpg_dossier' => base_path('tests/fixtures/inpn-inpg'),
        ]);
        app(InpnImportService::class)->importInpg(null, null, avecGeojson: false);
    }

    public function test_fiche_site_inpg_publique(): void
    {
        $reponse = $this->get('/geologie/NAQ0004');

        $reponse->assertOk();
        $reponse->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Geologie/Show')
            ->where('site.identifiant_metier', 'NAQ0004')
            ->where('site.nom', "Mines d'or gauloises du Tindeix")
            ->where('site.etat', 'Bon état général')
            ->where('site.rarete', 'Régionale')
            ->where('site.url_inpn', 'https://inpn.mnhn.fr/site/inpg/NAQ0004')
            ->where('meta.canonical', route('geologie.show', 'NAQ0004'))
            ->has('interets', 1)
            ->where('interets.0.interet', 'Ressources naturelles')
            ->where('interets.0.interet_sup', 'Minéralogie')
            ->has('zonageRefs', 2)
            // tri alphabétique sur zonage_ref : « SIC / ZSC » avant « ZNIEFF… »
            ->where('zonageRefs.0.lien', ['type' => 'natura', 'id' => 'FR2300128'])
            ->where('zonageRefs.1.lien', ['type' => 'znieff', 'id' => '230009093']) // NM_SFFZN reconnu → lien
            ->has('strati', 1)
            ->where('strati.0.strati', 'Carbonifère')
            ->has('documents', 1) // le document non diffusable (diff_doc=3) est exclu côté requête
            ->has('lieudits', 1)
            ->has('notes', 1)
            ->has('biblio', 1)
            ->has('accessibilites', 1)
            ->has('typologies', 1)
            ->has('cartes', 1)
            ->where('nbCommunes', 1)
            ->where('communes.0.slug', 'louviers')
        );
    }

    public function test_site_confidentiel_renvoie_404(): void
    {
        $this->get('/geologie/XXX9999')->assertNotFound();
    }

    public function test_site_inconnu_renvoie_404(): void
    {
        $this->get('/geologie/ZZZ9999')->assertNotFound();
    }

    public function test_liste_geologie_ne_contient_que_les_sites_publics(): void
    {
        $reponse = $this->get('/geologie');

        $reponse->assertOk();
        $reponse->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Geologie/Index')
            ->has('sites', 1)
            ->where('sites.0.id', 'NAQ0004')
            ->where('pagination.total', 1)
        );
    }

    public function test_fiche_commune_affiche_le_bloc_inpg(): void
    {
        $reponse = $this->get('/commune/louviers');

        $reponse->assertOk();
        $reponse->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Communes/Show')
            ->where('nbInpg', 1)
            ->where('inpg.0.id', 'NAQ0004')
            ->where('inpg.0.nom', "Mines d'or gauloises du Tindeix")
        );
    }

    public function test_sitemap_geologie_filtre_les_sites_confidentiels(): void
    {
        $index = $this->get('/sitemap.xml');
        $this->assertStringContainsString('/sitemaps/geologie-1.xml', $index->getContent());

        $lot = $this->get('/sitemaps/geologie-1.xml');
        $lot->assertOk();
        $this->assertStringContainsString('/geologie/NAQ0004', $lot->getContent());
        $this->assertStringNotContainsString('XXX9999', $lot->getContent());
    }
}
