<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\SemeFiches;
use Tests\TestCase;

/**
 * SEO (itération e) : page Données & méthodologie indexable, sitemap
 * exhaustif (toutes les fiches + navigation par département) et
 * robots.txt. Le plan est contractuel pour le référencement des
 * fiches « communes sans zonage » aussi — jamais de page invisible.
 */
class SeoSitemapTest extends TestCase
{
    use RefreshDatabase;
    use SemeFiches;

    protected function setUp(): void
    {
        parent::setUp();
        $this->semeFiches();
    }

    // ------------------------------------------------------------------
    // Données & méthodologie
    // ------------------------------------------------------------------

    public function test_la_page_donnees_est_rendue_et_indexable(): void
    {
        $reponse = $this->get('/donnees');

        $reponse->assertOk();
        $reponse->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Donnees/Index')
            ->where('meta.title', 'Données & méthodologie')
            ->where('meta.canonical', route('donnees.index')));
    }

    // ------------------------------------------------------------------
    // Sitemap
    // ------------------------------------------------------------------

    public function test_le_sitemap_index_reference_chaque_famille(): void
    {
        $reponse = $this->get('/sitemap.xml');

        $reponse->assertOk();
        $reponse->assertHeader('Content-Type', 'application/xml; charset=UTF-8');

        $xml = $reponse->getContent();
        $this->assertStringContainsString('<sitemapindex', $xml);
        foreach (['pages-1', 'communes-1', 'znieff-1', 'natura-1', 'ap-1'] as $lot) {
            $this->assertStringContainsString("/sitemaps/{$lot}.xml", $xml);
        }
    }

    public function test_le_lot_communes_reprend_toutes_les_fiches(): void
    {
        $xml = $this->get('/sitemaps/communes-1.xml')->getContent();

        $this->assertStringContainsString('<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">', $xml);
        $this->assertStringContainsString(route('commune.show', 'louviers'), $xml);
        $this->assertStringContainsString(route('commune.show', 'acquigny'), $xml);
        // la commune sans zonage est dans le plan — jamais de page invisible
        $this->assertStringContainsString(route('commune.show', 'sainte-colombe-pres-vernnon'), $xml);
    }

    public function test_les_lots_des_autres_familles(): void
    {
        $znieff = $this->get('/sitemaps/znieff-1.xml')->getContent();
        $this->assertStringContainsString(route('znieff.show', '230009093'), $znieff);
        $this->assertStringContainsString(route('znieff.show', '230030467'), $znieff);

        $natura = $this->get('/sitemaps/natura-1.xml')->getContent();
        $this->assertStringContainsString(route('natura.show', 'FR2300128'), $natura);

        $ap = $this->get('/sitemaps/ap-1.xml')->getContent();
        $this->assertStringContainsString(route('ap.show', 'FR3800583'), $ap);
    }

    public function test_le_lot_pages_couvre_la_navigation_y_compris_departements(): void
    {
        $xml = $this->get('/sitemaps/pages-1.xml')->getContent();

        foreach ([route('home'), route('donnees.index'), route('carte.index'), route('communes.index')] as $url) {
            $this->assertStringContainsString($url, $xml);
        }
        // les 102 départements du COG sont explorables depuis le plan
        $this->assertStringContainsString(route('communes.index').'?dept=27', $xml);
        $this->assertStringContainsString(route('communes.index').'?dept=2A', $xml);
        $this->assertStringContainsString(route('znieff.index').'?dept=971', $xml);
        $this->assertStringContainsString(route('carte.index').'?dept=27', $xml);
    }

    public function test_famille_ou_lot_inconnus_renvoient_404(): void
    {
        $this->get('/sitemaps/inconnu-1.xml')->assertNotFound();
        $this->get('/sitemaps/communes-99.xml')->assertNotFound();
        $this->get('/sitemaps/communes-0.xml')->assertNotFound();
    }

    public function test_robots_txt_declare_le_sitemap_et_protege_la_recherche(): void
    {
        $reponse = $this->get('/robots.txt');

        $reponse->assertOk();
        $contenu = $reponse->getContent();
        $this->assertStringContainsString('Disallow: /recherche', $contenu);
        $this->assertStringContainsString('Sitemap: '.url('/sitemap.xml'), $contenu);
    }
}
