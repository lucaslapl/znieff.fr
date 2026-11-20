<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\SemeFiches;
use Tests\TestCase;

/**
 * Recherche transverse (searchbar de l'accueil, itération d) : un
 * terme exact mène à la fiche (commune prioritaire, puis ZNIEFF — y
 * compris ancien numéro régional —, Natura 2000, aire protégée) ;
 * sinon page de résultats par section, département détecté. Page
 * fonctionnelle noindex.
 */
class RechercheTest extends TestCase
{
    use RefreshDatabase;
    use SemeFiches;

    protected function setUp(): void
    {
        parent::setUp();
        $this->semeFiches();
    }

    public function test_sans_terme_la_page_presente_le_formulaire(): void
    {
        $this->get('/recherche')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Recherche/Index')
            ->where('q', '')
            ->where('total', 0)
            ->where('dept', null)
            ->where('meta.noindex', true)
            ->has('communes', 0));
    }

    public function test_nom_exact_de_commune_redirige_vers_sa_fiche(): void
    {
        $this->get('/recherche?q=louviers')->assertRedirect(route('commune.show', 'louviers'));

        // le slug normalisé fait fois : majuscules et accents indifférents
        $this->get('/recherche?q=Louviers')->assertRedirect(route('commune.show', 'louviers'));
        $this->get('/recherche?q=ACQUIGNY')->assertRedirect(route('commune.show', 'acquigny'));
    }

    public function test_numero_regional_znieff_redirige_vers_lidentifiant_national(): void
    {
        $this->get('/recherche?q=8526')->assertRedirect(route('znieff.show', '230009093'));
        $this->get('/recherche?q=230009093')->assertRedirect(route('znieff.show', '230009093'));
    }

    public function test_code_natura_et_identifiant_ap_redirigent(): void
    {
        $this->get('/recherche?q=FR2300128')->assertRedirect(route('natura.show', 'FR2300128'));
        $this->get('/recherche?q=fr2300128')->assertRedirect(route('natura.show', 'FR2300128'));
        $this->get('/recherche?q=FR3800583')->assertRedirect(route('ap.show', 'FR3800583'));
    }

    public function test_nom_de_departement_presente_les_sections_du_dept(): void
    {
        $this->get('/recherche?q=eure')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('dept.code', '27')
            ->where('dept.nom', 'Eure')
            ->where('natura.0.code', 'FR2300128') // « La Vallée de l'Eure »
            ->where('total', 2)); // département + site Natura

        $this->get('/recherche?q=27')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('dept.code', '27'));

        $this->get('/recherche?q=eure-et-loir')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('dept.code', '28'));
    }

    public function test_correspondance_partielle_sans_redirection_liste_les_sections(): void
    {
        $this->get('/recherche?q=acqui')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('communes.0.slug', 'acquigny')
            ->where('total', 1));

        // « sainte colombe » (sans accents) via le slug du référentiel
        $this->get('/recherche?q=sainte colombe')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('communes.0.slug', 'sainte-colombe-pres-vernnon'));
    }

    public function test_aucun_resultat_page_explicite_sans_page_vide(): void
    {
        $this->get('/recherche?q=zzzztrouneau')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('total', 0)
            ->where('dept', null)
            ->has('communes', 0)
            ->has('zonages', 0)
            ->has('natura', 0)
            ->has('aires', 0));
    }
}
