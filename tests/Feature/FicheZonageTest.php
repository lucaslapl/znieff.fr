<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\SemeFiches;
use Tests\TestCase;

/**
 * Fiche ZNIEFF (itération b) : chapeau factuel, table de croisement
 * triée, parent/enfants, redirection 301 de l'ancien numéro régional.
 */
class FicheZonageTest extends TestCase
{
    use RefreshDatabase;
    use SemeFiches;

    protected function setUp(): void
    {
        parent::setUp();
        $this->semeFiches();
    }

    public function test_fiche_znieff_ii_avec_table_de_croisement(): void
    {
        $reponse = $this->get('/znieff/230009093');

        $reponse->assertOk();
        $reponse->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Zonages/Show')
            ->where('zonage.id', '230009093')
            ->where('zonage.famille', 'z2')
            ->where('zonage.badge', 'znieff2')
            ->where('zonage.num_regional', '8526')
            ->where('zonage.superficie_ha', '6 557,68 ha')
            ->where('zonage.precision_saisie', '1/25 000')
            ->where('zonage.date_crea', '1ᵉʳ janvier 1982')
            ->where('zonage.date_modif_geo', '15 juillet 2024')
            ->where('zonage.url_inpn', 'https://inpn.mnhn.fr/zone/znieff/230009093')
            ->where('nbCommunes', 2)
            // tri par surface d'intersection décroissante : Acquigny (1 661 ha) avant Louviers (1 340 ha)
            ->where('communes.0.slug', 'acquigny')
            ->where('communes.0.surface_ha', '1 661,22 ha')
            ->where('communes.0.part_zonage', '25,3 %')
            ->where('communes.0.part_commune', '88,2 %')
            ->where('communes.1.slug', 'louviers')
            ->where('communes.1.part_commune', '49,5 %')
            // pas de ZNIEFF II de rattachement sur une Z2
            ->where('parent', null)
            ->has('enfants', 1)
            ->where('enfants.0.id', '230030467')
            ->where('meta.canonical', route('znieff.show', '230009093'))
        );
    }

    public function test_fiche_znieff_i_avec_parent(): void
    {
        $reponse = $this->get('/znieff/230030467');

        $reponse->assertOk();
        $reponse->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Zonages/Show')
            ->where('zonage.famille', 'z1')
            ->where('parent.id', '230009093')
            ->where('parent.nom', 'Forêt de Bord et de Louviers')
            ->where('communes.0.slug', 'louviers')
        );
    }

    public function test_chapeau_factuel_de_la_znieff(): void
    {
        $reponse = $this->get('/znieff/230009093');

        $reponse->assertOk();
        $reponse->assertInertia(fn (AssertableInertia $page) => $page
            ->where('chapeau', fn (string $c) => str_contains($c, 'ZNIEFF de type II « Forêt de Bord et de Louviers » (230009093)')
                && str_contains($c, 'depuis le 1ᵉʳ janvier 1982')
                && str_contains($c, '6 557,68 ha')
                && str_contains($c, '2 communes')
                && str_contains($c, 'dont Acquigny couverte à 88,2 %')
                && str_contains($c, 'hors espèces confidentielles'))
            // anti thin-content : meta dérivée du chapeau, sans HTML
            ->where('meta.description', fn (string $m) => $m !== '' && ! str_contains($m, '<'))
        );
    }

    public function test_numero_regional_redirige_en_301(): void
    {
        $this->get('/znieff/num/8526')
            ->assertRedirect(route('znieff.show', '230009093'))
            ->assertStatus(301);

        // Repli : numéro régional saisi comme identifiant → 301 aussi.
        $this->get('/znieff/85260005')
            ->assertRedirect(route('znieff.show', '230030467'))
            ->assertStatus(301);
    }

    public function test_znieff_inconnu_renvoie_404(): void
    {
        $this->get('/znieff/999999999')->assertStatus(404);
        $this->get('/znieff/num/00000000')->assertStatus(404);
    }

    public function test_la_liste_des_communes_est_paginee_cote_serveur(): void
    {
        $this->semeZnieffTrenteCommunes();

        $page1 = $this->get('/znieff/230099999');
        $page1->assertOk();
        $page1->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Zonages/Show')
            ->has('communes', 25)
            // première page : les premières communes triées par surface décroissante
            ->where('communes.0.slug', 'commune-test-1')
            ->where('nbCommunes', 30)
            ->where('pagination.page', 1)
            ->where('pagination.derniere', 2)
            ->where('pagination.url_suivante', fn (string $u) => str_contains($u, 'page=2'))
            ->where('pagination.url_precedente', null)
            // le chapeau couvre le périmètre entier, pas la page seule
            ->where('chapeau', fn (string $c) => str_contains($c, '88,5 %'))
        );

        $page2 = $this->get('/znieff/230099999?page=2');
        $page2->assertOk();
        $page2->assertInertia(fn (AssertableInertia $page) => $page
            ->has('communes', 5)
            ->where('communes.0.slug', 'commune-test-26')
            ->where('pagination.page', 2)
            ->where('pagination.url_precedente', fn (string $u) => str_contains($u, 'page=1'))
            ->where('pagination.url_suivante', null)
        );
    }

    public function test_la_pagination_ne_deborde_pas_du_premier_et_dernier_page(): void
    {
        $this->semeZnieffTrenteCommunes();

        $reponse = $this->get('/znieff/230099999?page=99999');

        $reponse->assertOk();
        $reponse->assertInertia(fn (AssertableInertia $page) => $page
            // page bornée à la dernière : pas de liste vide en cascade
            ->has('communes', 5)
            ->where('pagination.page', 2)
            ->where('pagination.derniere', 2)
        );
    }

    /**
     * Section « Espèces déterminantes » de la fiche ZNIEFF : seules les
     * espèces déterminantes NON confidentielles sont listées — l'espèce
     * confidentielle (Rhinolophus) et l'espèce non déterminante (Picus canus)
     * restent en base mais ne sortent jamais (filtre absolu App\Support\Especes).
     */
    public function test_especes_determinantes_hors_confidentielles(): void
    {
        $reponse = $this->get('/znieff/230009093');

        $reponse->assertOk();
        $reponse->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Zonages/Show')
            ->where('nbEspeces', 2) // 2 déterminantes visibles sur 4 lignes en base
            ->has('especes', 2)
            // tri groupe_taxo puis nom_cite : Mammifères avant Oiseaux
            ->where('especes.0.groupe', 'Mammifères')
            ->where('especes.0.nom', 'Miniopterus schreibersii (Kuhl, 1817)') // italique HTML retirée
            // pas de nom vernaculaire semé : le latin seul (TAXREF jamais improvisé)
            ->where('especes.0.vern', null)
            ->where('especes.1.groupe', 'Oiseaux')
            ->where('especes.1.nom', 'Pluvialis apricaria (Linnaeus, 1758)')
            // l'espèce confidentielle n'apparaît nulle part dans les props
            ->where('especes', fn ($e) => ! str_contains((string) json_encode($e), 'Rhinolophus'))
            ->where('paginationEspeces.total', 2)
            ->where('paginationEspeces.derniere', 1)
        );

        // la ZNIEFF I rattachée a sa propre espèce déterminante
        $this->get('/znieff/230030467')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('nbEspeces', 1)
            ->where('especes.0.nom', 'Triturus cristatus (Laurenti, 1768)')
        );
    }

    /**
     * Pagination serveur de la section « Espèces » (?page_especes=) :
     * 32 espèces déterminantes visibles (2 semées + 30) → 25 + 7.
     */
    public function test_la_liste_des_especes_est_paginee_cote_serveur(): void
    {
        $this->semeZnieffTrenteEspeces();

        $page1 = $this->get('/znieff/230009093');
        $page1->assertOk();
        $page1->assertInertia(fn (AssertableInertia $page) => $page
            ->has('especes', 25)
            ->where('nbEspeces', 32)
            ->where('paginationEspeces.page', 1)
            ->where('paginationEspeces.derniere', 2)
            ->where('paginationEspeces.url_suivante', fn (string $u) => str_contains($u, 'page_especes=2'))
        );

        $page2 = $this->get('/znieff/230009093?page_especes=2');
        $page2->assertOk();
        $page2->assertInertia(fn (AssertableInertia $page) => $page
            ->has('especes', 7)
            ->where('paginationEspeces.page', 2)
            ->where('paginationEspeces.url_precedente', fn (string $u) => str_contains($u, 'page_especes=1'))
            ->where('paginationEspeces.url_suivante', null)
        );
    }

    /**
     * Sous-onglets par groupe taxonomique (?groupe=Oiseaux) : le filtre
     * est posé côté serveur, la pagination le conserve dans ses URLs, et
     * un groupe inconnu retombe sur « tous ».
     */
    public function test_sous_onglets_par_groupe_taxonomique(): void
    {
        $this->get('/znieff/230009093')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('groupeActif', null)
            ->where('nbEspeces', 2)
            ->where('nbEspecesTotal', 2)
            ->where('groupes.0.groupe', 'Mammifères')
            ->where('groupes.0.n', 1)
            ->where('groupes.1.groupe', 'Oiseaux')
            ->where('groupes.1.n', 1)
        );

        // Filtre serveur par groupe : seules les espèces du groupe, page 1.
        $this->get('/znieff/230009093?groupe=Oiseaux')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('groupeActif', 'Oiseaux')
            ->where('nbEspeces', 1)
            ->has('especes', 1)
            ->where('especes.0.nom', 'Pluvialis apricaria (Linnaeus, 1758)')
            ->where('especes.0.groupe', 'Oiseaux')
        );

        // Groupe inconnu : ignoré, on retombe sur la liste complète.
        $this->get('/znieff/230009093?groupe=Dinosaures')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('groupeActif', null)
            ->where('nbEspeces', 2)
            ->has('especes', 2)
        );
    }

    /**
     * La pagination des espèces conserve le groupe actif dans ses URLs.
     */
    public function test_la_pagination_des_especes_conserve_le_groupe(): void
    {
        $this->semeZnieffTrenteEspeces(); // 30 Oiseaux de plus → 31 visibles

        $this->get('/znieff/230009093?groupe=Oiseaux')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('groupeActif', 'Oiseaux')
            ->where('nbEspeces', 31)
            ->has('especes', 25)
            ->where('paginationEspeces.derniere', 2)
            ->where('paginationEspeces.url_suivante', fn (string $u) => str_contains($u, 'groupe=Oiseaux') && str_contains($u, 'page_especes=2'))
        );

        $this->get('/znieff/230009093?groupe=Oiseaux&page_especes=2')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('paginationEspeces.page', 2)
            ->has('especes', 6)
            ->where('paginationEspeces.url_precedente', fn (string $u) => str_contains($u, 'groupe=Oiseaux') && str_contains($u, 'page_especes=1'))
        );
    }

    /**
     * Une ZNIEFF de 30 communes (une page de 25 ne suffit pas), dont la
     * commune la plus couverte (part_commune max) n'est pas la première
     * de la liste — pour vérifier le chapeau.
     */
    private function semeZnieffTrenteCommunes(): void
    {
        $lignes = [];
        $pivot = [];
        foreach (range(1, 30) as $i) {
            $lignes[] = [
                'insee' => str_pad((string) (90000 + $i), 5, '0', STR_PAD_LEFT),
                'slug' => 'commune-test-'.$i,
                'nom' => 'Commune test '.$i,
                'dept' => '99', 'dept_nom' => 'Hors métropole', 'reg_code' => null, 'reg_nom' => null,
                'population' => null, 'superficie_ha' => 100, 'superficie_calc_ha' => 100,
                'lat' => 48.5, 'lng' => 2.5, 'codes_postaux' => null, 'has_abc' => false,
            ];
            $pivot[] = [
                'zonage_id' => '230099999', 'insee' => str_pad((string) (90000 + $i), 5, '0', STR_PAD_LEFT),
                'surface_ha' => 200 - $i, 'part_zonage' => 1.11, 'part_commune' => $i === 30 ? 88.5 : 1.0,
            ];
        }
        DB::table('communes')->insert($lignes);
        DB::table('zonages')->insert([
            'id' => '230099999', 'famille' => 'z2', 'nom' => 'Plateau de test',
            'num_regional' => null, 'id_parent' => null,
            'superficie_ha' => 1000.0, 'superficie_ref_ha' => 1000.0,
            'precision_saisie' => '1/25 000', 'date_crea' => '2000-01-01',
            'date_modif_geo' => '2024-01-01', 'territoire' => 'metrop', 'volet' => 'CONT',
            'url_inpn' => 'https://inpn.mnhn.fr/zone/znieff/230099999',
        ]);
        DB::table('zonage_communes')->insert($pivot);
    }
}
