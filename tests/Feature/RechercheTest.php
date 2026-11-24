<?php

namespace Tests\Feature;

use App\Services\InpnImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\SemeFiches;
use Tests\TestCase;

/**
 * Recherche transverse (searchbar de l'accueil, itération d) : un
 * terme exact mène à la fiche (commune prioritaire, puis ZNIEFF — y
 * compris ancien numéro régional —, Natura 2000, aire protégée,
 * espèce) ; sinon page de résultats par section, département détecté.
 * Page fonctionnelle noindex.
 *
 * La graine inclut le référentiel TAXREF/BDC des fixtures (fiches
 * espèces) et une pré-liste nationale de sensibilité pour 60400
 * (Triturus cristatus) : une espèce sensible ou confidentielle ne doit
 * jamais apparaître dans la recherche.
 */
class RechercheTest extends TestCase
{
    use RefreshDatabase;
    use SemeFiches;

    protected function setUp(): void
    {
        parent::setUp();
        $this->semeFiches();

        config([
            'portail.inpn.bdc_dossier' => base_path('tests/fixtures/inpn-bdc'),
            'portail.inpn.taxref_dossier' => base_path('tests/fixtures/inpn-taxref'),
        ]);
        app(InpnImportService::class)->importBdc();

        DB::table('sensibilite_mtd')->insert([
            ['id' => 5, 'cd_reg' => '28', 'libelle_liste' => 'Liste régionale test', 'ref_biblio' => null, 'url' => null, 'ressource' => null],
        ]);
        DB::table('sensibilite_especes')->insert([
            ['cd_nom' => 60400, 'cd_ref' => 60400, 'cd_sensibilite' => 3, 'duree' => null, 'cd_dept' => null, 'cd_sens_mtd' => 5],
        ]);
    }

    public function test_sans_terme_la_page_presente_le_formulaire(): void
    {
        $this->get('/recherche')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Recherche/Index')
            ->where('q', '')
            ->where('total', 0)
            ->where('dept', null)
            ->where('meta.noindex', true)
            ->has('communes', 0)
            ->has('especes', 0));
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
            ->has('aires', 0)
            ->has('especes', 0));
    }

    // ------------------------------------------------------------------
    // Section « espèces » (searchbar de l'accueil)
    // ------------------------------------------------------------------

    public function test_cd_ref_exact_redirige_vers_la_fiche_espece(): void
    {
        $this->get('/recherche?q=3161')->assertRedirect(route('espece.show', 3161));
    }

    public function test_nom_exact_espece_redirige_vers_la_fiche(): void
    {
        // nom valide TAXREF
        $this->get('/recherche?q=Pluvialis apricaria')->assertRedirect(route('espece.show', 3161));
        // nom vernaculaire exact (FSD Natura 2000)
        $this->get('/recherche?q=Pluvier doré')->assertRedirect(route('espece.show', 3161));
    }

    public function test_recherche_partielle_liste_la_section_especes(): void
    {
        $this->get('/recherche?q=pluvialis')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Recherche/Index')
            ->where('especes.0.cd_ref', 3161)
            ->where('especes.0.nom', 'Pluvialis apricaria')
            ->where('especes.0.vern', 'Pluvier doré') // vernaculaire du FSD Natura
            ->where('especes.0.groupe', 'Oiseaux')
            ->where('especes.0.nbZonages', 1)  // ZNIEFF 230009093 déterminante
            ->where('especes.0.nbSites', 1)    // site Natura FR2300128
            ->has('especes', 1)
            ->where('total', 1));
    }

    public function test_especes_confidentielle_et_sensible_jamais_trouvees(): void
    {
        // 999001 Rhinolophus : flaguée confidentielle → ni redirection ni résultat.
        $this->get('/recherche?q=999001')->assertOk();
        $this->get('/recherche?q=999001')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('total', 0)
            ->has('especes', 0));
        $this->get('/recherche?q=rhinolophus')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('total', 0)
            ->has('especes', 0));

        // 60400 Triturus cristatus : pré-liste nationale de sensibilité SINP v18.
        $this->get('/recherche?q=60400')->assertOk(); // pas de redirection
        $this->get('/recherche?q=triturus')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('total', 0)
            ->has('especes', 0));
    }
}
