<?php

namespace Tests\Feature;

use App\Services\InpnImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SemeFiches;
use Tests\TestCase;

/**
 * Autocomplétion de la searchbar (/recherche/suggestions, JSON) :
 * suggestions par section dès 3 lettres (5 max par section), rien en
 * dessous ; les espèces passent par le filtre absolu de visibilité —
 * jamais une espèce confidentielle, et une espèce sensible SINP v18
 * uniquement par son nom, en lien externe INPN (sans donnée de présence).
 */
class SuggestionsTest extends TestCase
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

    public function test_moins_de_3_lettres_renvoie_aucune_suggestion(): void
    {
        $this->getJson('/recherche/suggestions?q=pl')->assertOk()->assertJson(['q' => 'pl', 'sections' => []]);
        $this->getJson('/recherche/suggestions?q=')->assertOk()->assertJson(['q' => '', 'sections' => []]);
        $this->getJson('/recherche/suggestions')->assertOk()->assertJson(['sections' => []]);
    }

    public function test_trois_lettres_donnent_des_suggestions_dont_espece(): void
    {
        $sections = $this->getJson('/recherche/suggestions?q=plu')->assertOk()->json('sections');

        $especes = array_values(array_filter($sections, fn ($s) => $s['type'] === 'espece'));
        $this->assertNotEmpty($especes);
        $this->assertSame(route('espece.show', 3161), $especes[0]['url']);
        $this->assertSame('Pluvialis apricaria', $especes[0]['label']);
        $this->assertStringContainsString('Pluvier doré', $especes[0]['sous']); // vernaculaire FSD Natura
    }

    public function test_suggestions_commune_et_znieff(): void
    {
        $sections = $this->getJson('/recherche/suggestions?q=louv')->assertOk()->json('sections');
        $communes = array_values(array_filter($sections, fn ($s) => $s['type'] === 'commune'));
        $this->assertSame(route('commune.show', 'louviers'), $communes[0]['url']);

        $sections = $this->getJson('/recherche/suggestions?q=8526')->assertOk()->json('sections');
        $zonages = array_values(array_filter($sections, fn ($s) => $s['type'] === 'znieff'));
        $this->assertSame(route('znieff.show', '230009093'), $zonages[0]['url']);
    }

    public function test_espece_confidentielle_jamais_suggeree(): void
    {
        // 999001 Rhinolophus (flag `confidentielle`) : aucune suggestion,
        // pas même son nom dans l'encadré « espèce sensible ».
        foreach (['rhin', '999001'] as $q) {
            $sections = $this->getJson('/recherche/suggestions?q='.urlencode($q))->assertOk()->json('sections') ?? [];
            $this->assertSame([], array_values(array_filter($sections, fn ($s) => $s['type'] === 'espece')));
            $this->assertSame([], array_values(array_filter($sections, fn ($s) => $s['type'] === 'espece_sensible')));
            $this->assertNotContains('/espece/999001', array_column($sections, 'url'));
        }
    }

    public function test_espece_sensible_suggeree_par_nom_lien_inpn_externe_sans_presence(): void
    {
        // 60400 Triturus cristatus (pré-liste nationale SINP) : suggéré sous
        // forme d'item « espece_sensible » — lien EXTERNE vers l'INPN,
        // jamais /espece/60400, jamais de compteur de présence.
        foreach (['trit', '60400'] as $q) {
            $sections = $this->getJson('/recherche/suggestions?q='.urlencode($q))->assertOk()->json('sections') ?? [];

            // jamais dans les espèces visibles à fiche interne
            $this->assertSame([], array_values(array_filter($sections, fn ($s) => $s['type'] === 'espece')));
            $this->assertNotContains('/espece/60400', array_column($sections, 'url'));

            $sensibles = array_values(array_filter($sections, fn ($s) => $s['type'] === 'espece_sensible'));
            $this->assertCount(1, $sensibles);
            $this->assertSame('Triturus cristatus', $sensibles[0]['label']);
            $this->assertTrue($sensibles[0]['externe']);
            $this->assertStringStartsWith('https://inpn.mnhn.fr/', $sensibles[0]['url']);
            $this->assertStringNotContainsString('ZNIEFF', $sensibles[0]['sous'] ?? '');
        }
    }
}
