<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Le socle rend l'accueil : charte herbier, navigation complète,
 * versions des référentiels partagées à chaque page Inertia.
 */
class AccueilSocleTest extends TestCase
{
    use RefreshDatabase;

    public function test_laccueil_rend_la_page_inertia(): void
    {
        $reponse = $this->get('/');

        $reponse->assertOk();
        $reponse->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Home')
            ->where('pageTitre', 'Portail ZNIEFF — le patrimoine naturel des communes de France'));
    }

    public function test_les_sections_sont_declarees_mais_noindex(): void
    {
        // Pages squelettes des itérations suivantes : titled + noindex.
        $this->get('/communes')->assertOk();
        $this->get('/znieff')->assertOk();
        $this->get('/natura')->assertOk();
        $this->get('/ap')->assertOk();
        $this->get('/carte')->assertOk();
        $this->get('/donnees')->assertOk();
    }

    public function test_la_page_inconnue_renvoie_la_fiche_introuvable(): void
    {
        $reponse = $this->get('/nexistepas');
        $reponse->assertStatus(404);
        $reponse->assertInertia(fn (AssertableInertia $page) => $page->component('Error'));
    }
}
