<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\SemeFiches;
use Tests\TestCase;

/**
 * Fiches Natura 2000 et aires protégées (itération b), même gabarit que
 * les ZNIEFF : chapeau factuel, table de croisement, renvoi INPN pour
 * les données naturalistes (jamais rediffusées).
 */
class FicheNaturaApTest extends TestCase
{
    use RefreshDatabase;
    use SemeFiches;

    protected function setUp(): void
    {
        parent::setUp();
        $this->semeFiches();
    }

    public function test_fiche_natura_2000(): void
    {
        $reponse = $this->get('/natura/FR2300128');

        $reponse->assertOk();
        $reponse->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Natura/Show')
            ->where('site.code', 'FR2300128')
            ->where('site.type', 'SIC')
            ->where('site.superficie_ha', '728,5 ha')
            ->where('site.date_designation', '24 mars 1998')
            ->where('site.url_inpn', 'https://inpn.mnhn.fr/site/natura2000/FR2300128')
            ->where('nbCommunes', 1)
            ->where('communes.0.slug', 'louviers')
            ->where('communes.0.part_commune', '3,8 %')
            ->where('meta.canonical', route('natura.show', 'FR2300128'))
        );

        // Code en minuscules toléré (canonique uppercase à la fiche).
        $this->get('/natura/fr2300128')->assertOk();
    }

    public function test_chapeau_natura(): void
    {
        $reponse = $this->get('/natura/FR2300128');

        $reponse->assertOk();
        $reponse->assertInertia(fn (AssertableInertia $page) => $page
            ->where('chapeau', fn (string $c) => str_contains($c, 'Site Natura 2000 (SIC) « La Vallée de l\'Eure » (FR2300128)')
                && str_contains($c, 'désigné le 24 mars 1998')
                && str_contains($c, '728,5 ha')
                && str_contains($c, '1 commune'))
        );
    }

    public function test_fiche_aire_protegee(): void
    {
        $reponse = $this->get('/ap/FR3800583');

        $reponse->assertOk();
        $reponse->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Aires/Show')
            ->where('aire.id', 'FR3800583')
            ->where('aire.type_code', 'APPB')
            ->where('aire.acte', 'Arrêté préfectoral du 12/02/1990')
            ->where('aire.gestionnaire', 'Préfecture de l\'Eure')
            ->where('aire.superficie_ha', '12,5 ha')
            ->where('aire.date_crea_sign', '12 février 1990')
            ->where('nbCommunes', 1)
            ->where('communes.0.slug', 'louviers')
            ->where('communes.0.part_zonage', '81,6 %')
            ->where('meta.canonical', route('ap.show', 'FR3800583'))
        );
    }

    public function test_chapeau_aire_protegee(): void
    {
        $reponse = $this->get('/ap/FR3800583');

        $reponse->assertOk();
        $reponse->assertInertia(fn (AssertableInertia $page) => $page
            ->where('chapeau', fn (string $c) => str_contains($c, 'Arrêté de protection de biotope')
                && str_contains($c, 'Acte de classement : Arrêté préfectoral du 12/02/1990')
                && str_contains($c, 'Gestionnaire : Préfecture de l\'Eure')
                && str_contains($c, '~5 m'))
        );
    }

    public function test_inconnus_renvoient_404(): void
    {
        $this->get('/natura/FR9999999')->assertStatus(404);
        $this->get('/ap/FR9999999')->assertStatus(404);
    }
}
