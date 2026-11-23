<?php

namespace Tests\Feature;

use App\Support\Especes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\SemeFiches;
use Tests\TestCase;

/**
 * Filtre absolu « espèces sensibles jamais affichées ni comptées » : au-delà
 * du flag `confidentielle` de REF_ESPECE, le référentiel de sensibilité SINP
 * v18 (sensibilite_especes, taxon × département + pré-liste nationale) est
 * appliqué CÔTÉ REQUÊTE dans App\Support\Especes — listes et compteurs.
 *
 * Graine SemeFiches (Louviers/Acquigny, dept 27) : sur la ZNIEFF 230009093,
 * déterminantes non confidentielles 3161 (Pluvialis apricaria, Oiseaux) et
 * 1308 (Miniopterus schreibersii, Mammifères) ; sur la 230030467, 60400
 * (Triturus cristatus, Amphibiens) ; 999001 est déjà `confidentielle`.
 */
class SensibiliteFiltreTest extends TestCase
{
    use RefreshDatabase;
    use SemeFiches;

    protected function setUp(): void
    {
        parent::setUp();
        $this->semeFiches();

        DB::table('sensibilite_mtd')->insert([
            ['id' => 5, 'cd_reg' => '28', 'libelle_liste' => 'Liste régionale test', 'ref_biblio' => null, 'url' => null, 'ressource' => null],
            ['id' => 9, 'cd_reg' => '84', 'libelle_liste' => 'Pré-liste nationale test', 'ref_biblio' => null, 'url' => null, 'ressource' => null],
        ]);

        DB::table('sensibilite_especes')->insert([
            // Sensible dans le 27 → masquée sur les zones du 27.
            ['cd_nom' => 1308, 'cd_ref' => 1308, 'cd_sensibilite' => 2, 'duree' => 20, 'cd_dept' => '27', 'cd_sens_mtd' => 9],
            // Sensible dans le 28 uniquement → VISIBLE en 27.
            ['cd_nom' => 3161, 'cd_ref' => 3161, 'cd_sensibilite' => 1, 'duree' => null, 'cd_dept' => '28', 'cd_sens_mtd' => 9],
            // Pré-liste nationale (cd_dept null) → masquée partout.
            ['cd_nom' => 60400, 'cd_ref' => 60400, 'cd_sensibilite' => 3, 'duree' => null, 'cd_dept' => null, 'cd_sens_mtd' => 5],
        ]);
    }

    public function test_liste_zonage_masque_les_taxons_sensibles_du_departement(): void
    {
        $page = Especes::especesZonagePagine('230009093', 1, 25);

        // Seul Pluvialis apricaria (3161, sensible seulement en 28) reste :
        // Miniopterus (1308, sensible en 27) et Rhinolophus (flag) sont exclus.
        $this->assertSame(1, $page['total']);
        $this->assertSame([3161], array_column($page['items'], 'cd_ref'));
    }

    public function test_groupes_zonage_ne_comptent_pas_les_taxons_sensibles(): void
    {
        $groupes = Especes::groupesZonage('230009093');

        $this->assertSame([['groupe' => 'Oiseaux', 'n' => 1]], $groupes);
    }

    public function test_pre_liste_nationale_masque_partout(): void
    {
        $page = Especes::especesZonagePagine('230030467', 1, 25);

        // Triturus cristatus (60400) est en pré-liste nationale : plus rien.
        $this->assertSame(0, $page['total']);
        $this->assertSame([], $page['items']);
    }

    public function test_compteurs_commune_excluent_les_taxons_sensibles(): void
    {
        $compteurs = Especes::compteursCommune('27375');

        $this->assertSame(['230009093' => 1], $compteurs['znieff']);
    }

    public function test_fiche_znieff_n_affiche_aucune_espece_sensible(): void
    {
        $reponse = $this->get('/znieff/230009093');

        $reponse->assertOk();
        $reponse->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Zonages/Show')
            ->where('nbEspeces', 1)
            ->has('especes', 1)
            ->where('especes.0.nom', 'Pluvialis apricaria (Linnaeus, 1758)')
            ->where('especes', fn ($e) => ! str_contains((string) json_encode($e), 'Miniopterus'))
            ->where('especes', fn ($e) => ! str_contains((string) json_encode($e), 'Rhinolophus'))
            ->where('groupes', fn ($g) => ! str_contains((string) json_encode($g), 'Mammifères'))
        );
    }
}
