<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\SemeFiches;
use Tests\TestCase;

/**
 * Fiche commune (itération b) : toute commune a une fiche — avec zonages
 * (pivot précalculé) comme sans aucun zonage (affichage explicite, jamais
 * de page vide).
 */
class FicheCommuneTest extends TestCase
{
    use RefreshDatabase;
    use SemeFiches;

    protected function setUp(): void
    {
        parent::setUp();
        $this->semeFiches();
    }

    public function test_fiche_commune_avec_zonages(): void
    {
        $reponse = $this->get('/commune/louviers');

        $reponse->assertOk();
        $reponse->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Communes/Show')
            ->where('commune.insee', '27375')
            ->where('commune.nom', 'Louviers')
            ->where('commune.population', '18 347')
            ->where('commune.superficie_ha', '2 710 ha')
            ->where('commune.has_abc', true)
            ->where('nbZonages', 2)
            ->where('nbNatura', 1)
            ->where('nbAires', 1)
            // tri : part du territoire communal décroissante → ZNIEFF II d'abord
            ->where('zonages.0.id', '230009093')
            ->where('zonages.0.part_commune', '49,5 %')
            ->where('zonages.0.badge', 'znieff2')
            // compteur d'espèces déterminantes (hors confidentielles) par zonage
            ->where('zonages.0.nb_especes', 2)
            ->where('zonages.1.id', '230030467')
            ->where('zonages.1.nb_especes', 1)
            ->where('natura.0.code', 'FR2300128')
            ->where('natura.0.nb_especes', 2)
            ->where('natura.0.nb_habitats', 2)
            ->where('aires.0.id', 'FR3800583')
            // voisines : Acquigny à 4 200 m
            ->has('voisines', 1)
            ->where('voisines.0.slug', 'acquigny')
            ->where('voisines.0.distance_m', 4200)
            ->where('meta.canonical', route('commune.show', 'louviers'))
        );
    }

    public function test_fiche_commune_sans_zonage_jamais_vide(): void
    {
        $reponse = $this->get('/commune/sainte-colombe-pres-vernnon');

        $reponse->assertOk();
        $reponse->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Communes/Show')
            ->where('commune.insee', '27558')
            ->where('nbZonages', 0)
            ->where('nbNatura', 0)
            ->where('nbAires', 0)
            ->where('zonages', [])
            ->where('natura', [])
            ->where('aires', [])
            ->where('voisines', [])
        );
    }

    public function test_slug_inconnu_renvoie_404(): void
    {
        $this->get('/commune/nexistepas')->assertStatus(404);
    }

    public function test_canonical_et_meta_description(): void
    {
        $reponse = $this->get('/commune/louviers');

        $reponse->assertOk();
        $reponse->assertInertia(fn (AssertableInertia $page) => $page
            ->where('meta.title', 'Patrimoine naturel à Louviers (27)')
            ->where('meta.description', 'Commune de Louviers (27) : 2 ZNIEFF, 1 site(s) Natura 2000, 1 aire(s) protégée(s) recensé(s) par l\'INPN sur le territoire communal.')
        );
    }
}
