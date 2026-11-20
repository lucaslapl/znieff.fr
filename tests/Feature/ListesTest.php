<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\SemeFiches;
use Tests\Concerns\SemeListes;
use Tests\TestCase;

/**
 * Listes et recherche (itération d) : pagination serveur (25/page),
 * filtres simples (département, type, recherche nom / identifiant /
 * code postal) — les quatre familles du portail.
 */
class ListesTest extends TestCase
{
    use RefreshDatabase;
    use SemeFiches;
    use SemeListes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->semeFiches();
        $this->semeListes();
    }

    // ------------------------------------------------------------------
    // Communes
    // ------------------------------------------------------------------

    public function test_la_liste_des_communes_pagine(): void
    {
        $reponse = $this->get('/communes');

        $reponse->assertOk();
        $reponse->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Communes/Index')
            ->where('meta.noindex', false)
            ->where('meta.canonical', route('communes.index'))
            ->where('pagination.total', 33) // 3 fiches de référence + 30 fictives
            ->where('pagination.page', 1)
            ->where('pagination.derniere', 2)
            ->where('pagination.url_precedente', null)
            ->where('pagination.url_suivante', fn ($u) => is_string($u) && str_contains($u, '/communes') && str_contains($u, 'page=2'))
            ->has('communes', 25)
            // ordre alphabétique : Acquigny d'abord, Commune Test 01 dans la page 1
            ->where('communes.0.nom', 'Acquigny')
            ->where('communes.0.population', '1 725')
            ->where('communes.0.nbZonages', 1)
            ->where('communes.0.nbNatura', 0)
            ->where('communes.0.nbAp', 0)
            ->where('communes.1.nom', 'Commune Test 01')
        );

        // la page 2 porte le même filtre
        $page2 = $this->get('/communes?dept=28&page=2');
        $page2->assertInertia(fn (AssertableInertia $page) => $page
            ->where('pagination.page', 2)
            ->where('pagination.total', 30)
            ->has('communes', 5)
            ->where('communes.0.nom', 'Commune Test 26')
        );
    }

    public function test_filtre_departement_communes(): void
    {
        $this->get('/communes?dept=27')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('pagination.total', 3)
            ->where('deptNom', 'Eure')
            ->has('communes', 3)
            ->where('communes.0.nom', 'Acquigny'));

        $this->get('/communes?dept=28')->assertInertia(
            fn (AssertableInertia $page) => $page->where('pagination.total', 30)
        );
    }

    public function test_recherche_commune_par_nom_insee_et_code_postal(): void
    {
        // nom, indépendamment de la casse
        $this->get('/communes?q=LOUVIER')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('pagination.total', 1)
            ->where('communes.0.slug', 'louviers')
            ->where('meta.noindex', true));

        // slug sans accents : « sainte colombe »
        $this->get('/communes?q=sainte colombe')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('pagination.total', 1)
            ->where('communes.0.slug', 'sainte-colombe-pres-vernnon'));

        // code INSEE
        $this->get('/communes?q=27558')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('pagination.total', 1)
            ->where('communes.0.insee', '27558'));

        // code postal : Louviers et Acquigny (27400), pas Sainte-Colombe
        $this->get('/communes?q=27400')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('pagination.total', 2)
            ->where('communes.0.slug', 'acquigny')
            ->where('communes.1.slug', 'louviers'));
    }

    public function test_dept_inconnu_404_mais_casse_2a_toleree(): void
    {
        $this->get('/communes?dept=99')->assertNotFound();
        $this->get('/communes?dept=2a')->assertOk(); // 2A Corse-du-Sud, vide mais valide
        $this->get('/communes?q=a')->assertOk(); // terme trop court : liste complète
    }

    public function test_les_comptes_abc_de_la_liste(): void
    {
        $this->get('/communes?dept=27')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('communes.1.slug', 'louviers')->where('communes.1.has_abc', true)
            ->where('communes.0.has_abc', false));
    }

    // ------------------------------------------------------------------
    // Zonages ZNIEFF
    // ------------------------------------------------------------------

    public function test_la_liste_des_znieff_tri_superficie_desc(): void
    {
        $this->get('/znieff')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Zonages/Index')
            ->where('pagination.total', 2)
            ->where('zonages.0.id', '230009093') // z2 6 557 ha avant z1 338 ha
            ->where('zonages.0.badge', 'znieff2')
            ->where('zonages.0.nbCommunes', 2)
            ->where('zonages.0.maj', '15 juillet 2024')
            ->where('zonages.1.id', '230030467')
            ->where('zonages.1.maj', '15 juillet 2024'));
    }

    public function test_recherche_znieff_par_ancien_numero_regional_et_nom(): void
    {
        $this->get('/znieff?q=8526')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('pagination.total', 1)
            ->where('zonages.0.id', '230009093'));

        $this->get('/znieff?q=louviers')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('pagination.total', 1)
            ->where('zonages.0.nom', 'Forêt de Bord et de Louviers'));
    }

    public function test_filtre_type_et_dept_znieff(): void
    {
        $this->get('/znieff?type=z1')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('pagination.total', 1)
            ->where('zonages.0.id', '230030467'));

        $this->get('/znieff?dept=27')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('pagination.total', 2));

        $this->get('/znieff?dept=28')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('pagination.total', 0));
    }

    // ------------------------------------------------------------------
    // Natura 2000
    // ------------------------------------------------------------------

    public function test_liste_natura(): void
    {
        $this->get('/natura')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Natura/Index')
            ->where('pagination.total', 1)
            ->where('sites.0.code', 'FR2300128')
            ->where('sites.0.type', 'SIC')
            ->where('sites.0.nom', "La Vallée de l'Eure")
            ->where('sites.0.superficie_ha', '728,5 ha')
            ->where('sites.0.nbCommunes', 1)
            ->where('sites.0.maj', '13 juin 2023'));
    }

    public function test_filtres_natura_type_dept_et_code(): void
    {
        $this->get('/natura?type=zps')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('pagination.total', 0));

        $this->get('/natura?type=sic')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('pagination.total', 1)); // casse indifférente

        $this->get('/natura?dept=28')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('pagination.total', 0));

        $this->get('/natura?q=FR2300128')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('pagination.total', 1)
            ->where('sites.0.code', 'FR2300128'));
    }

    // ------------------------------------------------------------------
    // Aires protégées
    // ------------------------------------------------------------------

    public function test_liste_aires_protegees_avec_types_du_referentiel(): void
    {
        $this->get('/ap')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Aires/Index')
            ->where('pagination.total', 1)
            ->where('aires.0.id', 'FR3800583')
            ->where('aires.0.type_code', 'APPB')
            ->where('aires.0.type_nom', 'Arrêté de protection de biotope, habitat ou site')
            ->where('aires.0.superficie_ha', '12,5 ha')
            ->where('aires.0.nbCommunes', 1)
            ->where('aires.0.maj', '8 septembre 2010')
            ->where('apTypes.0.code', 'APPB'));
    }

    public function test_filtres_aires_type_dept_et_identifiant(): void
    {
        $this->get('/ap?type=RNR')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('pagination.total', 0));

        $this->get('/ap?type=APPB&dept=27')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('pagination.total', 1)
            ->where('aires.0.id', 'FR3800583'));

        $this->get('/ap?q=FR3800583')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('pagination.total', 1));

        $this->get('/ap?dept=28')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('pagination.total', 0));
    }
}
