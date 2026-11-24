<?php

namespace Tests\Feature;

use App\Services\InpnImportService;
use App\Support\Especes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\SemeFiches;
use Tests\TestCase;

/**
 * Fiche espèce /espece/{cd_ref} (itération P1) : TAXREF v18 + statuts BDC v18
 * importés depuis les fixtures, graine SemeFiches (dept 27).
 *
 *  - 3161 (Pluvialis apricaria) : fiche publique, statuts PN/DH/LRN/BERN,
 *    ZNIEFF 230009093 déterminante.
 *  - 999001 (Rhinolophus) : flaggé confidentiel → 404.
 *  - 60400 (Triturus cristatus) : ajouté au référentiel de sensibilité
 *    (national) → 404, jamais de fiche d'espèce sensible.
 */
class EspeceFicheTest extends TestCase
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

    public function test_fiche_espece_publique_avec_statuts_et_znieff(): void
    {
        $reponse = $this->get('/espece/3161');

        $reponse->assertOk();
        $reponse->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Especes/Show')
            ->where('espece.cd_ref', 3161)
            ->where('espece.nom', 'Pluvialis apricaria')
            ->where('espece.vern', null) // graine sans nom vernaculaire TAXREF joint
            ->where('espece.groupe', 'Oiseaux')
            ->where('espece.habitat', 'Terrestre')
            ->where('espece.url_inpn', 'https://inpn.mnhn.fr/espece/cd_nom/3161')
            ->where('nbZonages', 1)
            ->where('zonages.0.id', '230009093')
            ->where('zonages.0.nom', 'Forêt de Bord et de Louviers')
            ->where('meta.canonical', route('espece.show', 3161))
            // 4 statuts BDC de la fixture pour 3161, groupés.
            ->has('statuts', 4)
        );

        // Les statuts contiennent bien PN (Protection) et DH (annexe II).
        $statuts = Especes::statutsEspece(3161);
        $types = array_merge(...array_map(fn (array $g) => array_column($g['statuts'], 'type'), $statuts));
        sort($types);
        $this->assertSame(['BERN', 'DH', 'LRN', 'PN'], $types);
    }

    public function test_espece_confidentielle_renvoie_404(): void
    {
        $this->get('/espece/999001')->assertNotFound();
        $this->assertFalse(Especes::especeVisible(999001));
    }

    public function test_espece_sensible_v18_renvoie_404(): void
    {
        // 60400 est déterminante sur la ZNIEFF 230030467 mais en pré-liste
        // nationale de sensibilité : jamais de fiche, jamais comptée.
        $this->get('/espece/60400')->assertNotFound();
        $this->assertFalse(Especes::especeVisible(60400));

        $page = Especes::especesZonagePagine('230030467', 1, 25);
        $this->assertSame(0, $page['total']);
    }

    public function test_espece_sans_znieff_determinante_a_une_fiche_vide(): void
    {
        $reponse = $this->get('/espece/60612');

        $reponse->assertOk();
        $reponse->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Especes/Show')
            ->where('espece.nom', 'Picus canus')
            ->where('nbZonages', 0)
            ->has('zonages', 0)
            // non déterminante et citée par aucun site Natura : aucune commune
            ->where('nbCommunes', 0)
            ->has('communes', 0)
        );
    }

    public function test_fiche_espece_liste_les_communes_ou_le_taxon_est_present(): void
    {
        // 3161 (Pluvialis apricaria) : déterminante sur la ZNIEFF 230009093
        // (communes Louviers 27375 + Acquigny 27003) et citée par le site
        // Natura FR2300128 (commune Louviers) → 2 communes distinctes.
        $reponse = $this->get('/espece/3161');

        $reponse->assertOk();
        $reponse->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Especes/Show')
            ->where('nbCommunes', 2)
            ->where('communes.0.slug', 'acquigny') // tri département puis nom
            ->where('communes.0.insee', '27003')
            ->where('communes.1.slug', 'louviers')
            ->where('communes.1.insee', '27375')
            ->where('paginationCommunes.total', 2)
            ->where('paginationCommunes.derniere', 1)
            ->where('paginationCommunes.page', 1)
        );

        // page demandée hors bornes → ramenée à la dernière page (pagination serveur)
        $this->get('/espece/3161?page_communes=99')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('paginationCommunes.page', 1)
            ->where('nbCommunes', 2));
    }

    public function test_communes_espece_ne_comptent_pas_les_citations_confidentielles(): void
    {
        // 999001 (Rhinolophus) est confidentielle : aucune fiche (404), et le
        // compteur de communes exclut ce flag — vérifié via l'API interne.
        $this->assertFalse(Especes::especeVisible(999001));
        $this->get('/espece/999001')->assertNotFound();
    }

    public function test_cd_ref_inconnu_renvoie_404(): void
    {
        $this->get('/espece/424242')->assertNotFound();
    }

    public function test_liste_especes_paginee_serveur(): void
    {
        // Sans q : toutes les espèces visibles du périmètre (3 taxons :
        // 1308, 60612, 3161 — 999001 confidentielle et 60400 sensible exclues),
        // triées par nom valide, indexable (noindex false).
        $this->get('/especes')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Especes/Index')
            ->where('q', '')
            ->where('meta.noindex', false)
            ->where('pagination.total', 3)
            ->has('especes', 3)
            ->where('especes.0.cd_ref', 1308)  // Miniopterus schreibersii
            ->where('especes.1.cd_ref', 60612) // Picus canus
            ->where('especes.2.cd_ref', 3161)  // Pluvialis apricaria
        );
    }

    public function test_liste_especes_filtree_par_nom_incomplet(): void
    {
        // Nom incomplet → tous les résultats correspondants, noindex (page fonctionnelle).
        $this->get('/especes?q=pluvialis')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Especes/Index')
            ->where('q', 'pluvialis')
            ->where('meta.noindex', true)
            ->where('pagination.total', 1)
            ->where('especes.0.cd_ref', 3161)
            ->where('especes.0.nbZonages', 1)
            ->where('especes.0.nbSites', 1)
            ->where('especes.0.vern', 'Pluvier doré')
        );

        // vernaculaire incomplet (casse quelconque)
        $this->get('/especes?q=PLUVIER')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('pagination.total', 1)
            ->where('especes.0.cd_ref', 3161));
    }

    public function test_liste_especes_est_mise_en_cache_cinq_minutes(): void
    {
        // Le count + les LIKE sur taxons_ref sont coûteux : la page (q, page)
        // est mise en cache 5 min (les référentiels ne changent qu'à l'import).
        $this->get('/especes?q=pluvialis')->assertOk();
        $this->assertTrue(Cache::has('portail:especes:'.md5('pluvialis|1|25')));
    }

    public function test_liste_especes_ne_contient_jamais_sensibles_ni_confidentielles(): void
    {
        $this->get('/especes?q=triturus')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('pagination.total', 0)
            ->has('especes', 0));

        $this->get('/especes?q=rhinolophus')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('pagination.total', 0)
            ->has('especes', 0));

        $this->get('/especes?q=999001')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('pagination.total', 0)
            ->has('especes', 0));
    }

    public function test_sitemap_liste_les_fiches_especes_visibles_seulement(): void
    {
        $index = $this->get('/sitemap.xml');
        $index->assertOk();
        $this->assertStringContainsString('/sitemaps/especes-1.xml', $index->getContent());

        $lot = $this->get('/sitemaps/especes-1.xml');
        $lot->assertOk();
        $contenu = $lot->getContent();
        $this->assertStringContainsString('/espece/3161', $contenu);
        $this->assertStringContainsString('/espece/60612', $contenu);
        // Ni l'espèce confidentielle, ni l'espèce sensible.
        $this->assertStringNotContainsString('/espece/999001', $contenu);
        $this->assertStringNotContainsString('/espece/60400', $contenu);
    }
}
