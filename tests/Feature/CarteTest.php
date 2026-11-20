<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\SemeFiches;
use Tests\TestCase;

/**
 * Carte interactive (itération c) : /carte avec sélecteur de département,
 * cadrage /carte/znieff/{id} (dept résolu par la commune la plus touchée
 * du croisement) et /carte/commune/{slug}.
 */
class CarteTest extends TestCase
{
    use RefreshDatabase;
    use SemeFiches;

    protected function setUp(): void
    {
        parent::setUp();
        $this->semeFiches();
    }

    public function test_carte_sans_departement_propose_le_selecteur(): void
    {
        $reponse = $this->get('/carte');

        $reponse->assertOk();
        $reponse->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Cartes/Index')
            ->where('dept', null)
            ->where('focus', null)
            ->where('meta.canonical', route('carte.index'))
            ->where('departements.27', 'Eure')
            ->where('departements.2A', 'Corse-du-Sud')
        );
    }

    public function test_carte_avec_departement(): void
    {
        $this->get('/carte?dept=27')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('dept', '27')
                ->where('deptNom', 'Eure')
                ->where('meta.canonical', route('carte.index', ['dept' => '27']))
            );

        // Codes INSEE paddés : la casse ne compte que pour 2A/2B.
        $this->get('/carte?dept=2a')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('dept', '2A'));
    }

    public function test_departement_inconnu_renvoie_404(): void
    {
        $this->get('/carte?dept=99')->assertStatus(404);
    }

    public function test_cadrage_sur_une_znieff(): void
    {
        $reponse = $this->get('/carte/znieff/230009093');

        $reponse->assertOk();
        $reponse->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Cartes/Index')
            // commune la plus touchée = Acquigny (dept 27)
            ->where('dept', '27')
            ->where('deptNom', 'Eure')
            ->where('focus.famille', 'z2')
            ->where('focus.cle', '230009093')
            ->where('meta.canonical', route('carte.znieff', '230009093'))
        );
    }

    public function test_cadrage_sur_une_znieff_de_type_un(): void
    {
        $this->get('/carte/znieff/230030467')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('focus.famille', 'z1')
                ->where('focus.cle', '230030467')
            );
    }

    public function test_cadrage_sur_une_commune(): void
    {
        $reponse = $this->get('/carte/commune/louviers');

        $reponse->assertOk();
        $reponse->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Cartes/Index')
            ->where('dept', '27')
            ->where('focus.famille', 'commune')
            ->where('focus.cle', 'louviers')
            ->where('meta.canonical', route('carte.commune', 'louviers'))
        );
    }

    public function test_cadrages_inconnus_renvoient_404(): void
    {
        $this->get('/carte/znieff/999999999')->assertStatus(404);
        $this->get('/carte/commune/nexistepas')->assertStatus(404);
    }

    public function test_numero_regional_de_la_carte_redirige_en_301(): void
    {
        $this->get('/carte/znieff/8526')
            ->assertRedirect(route('carte.znieff', '230009093'))
            ->assertStatus(301);
    }
}
