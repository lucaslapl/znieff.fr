<?php

namespace Tests\Feature;

use App\Models\ReferentielImport;
use App\Services\InpnImportService;
use App\Support\Inpg;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SemeFiches;
use Tests\TestCase;

/**
 * Import INPG en mode « attributs seuls » (SQLite, sans PostGIS) : fixtures
 * tests/fixtures/inpn-inpg, graine SemeFiches (Louviers 27375 en base).
 * Le chemin spatial (staging, croisements, GeoJSON) est validé en local
 * Docker par l'import réel — jamais dans la CI.
 */
class ImportInpgTest extends TestCase
{
    use RefreshDatabase;
    use SemeFiches;

    protected function setUp(): void
    {
        parent::setUp();
        $this->semeFiches();

        config([
            'portail.inpn.inpg_dossier' => base_path('tests/fixtures/inpn-inpg'),
        ]);
    }

    public function test_importe_les_sites_et_leurs_pivots(): void
    {
        $retour = app(InpnImportService::class)->importInpg(null, null, avecGeojson: false);

        $this->assertFalse($retour['spatial']);
        $this->assertSame(2, $retour['sites']); // dont 1 confidentiel (importé, jamais affiché)
        $this->assertDatabaseCount('inpg_sites', 2);

        $site = DB::table('inpg_sites')->where('identifiant_metier', 'NAQ0004')->first();
        $this->assertNotNull($site);
        $this->assertSame("Mines d'or gauloises du Tindeix", $site->nom);
        $this->assertSame(2, (int) $site->niv_diffusion);
        $this->assertSame('Bon état général', $site->etat);
        $this->assertSame('Régionale', $site->rarete);
        $this->assertSame('Ressources naturelles', $site->phenom_geol);
        $this->assertSame('hectares', $site->unite_superficie);
        $this->assertSame('metrop', $site->territoire); // via INSEED27
        $this->assertSame('https://inpn.mnhn.fr/site/inpg/NAQ0004', $site->url_inpn);
        $this->assertSame('2021-07-21', (string) $site->date_crea_site);

        // Pivots dénormalisés via les codes.
        $this->assertDatabaseCount('inpg_interets', 1);
        $interet = DB::table('inpg_interets')->where('site_id', '5352')->first();
        $this->assertSame('Ressources naturelles', $interet->interet);
        $this->assertSame('Minéralogie', $interet->interet_sup);

        $this->assertDatabaseCount('inpg_zonage_ref', 2);
        $this->assertDatabaseCount('inpg_strati', 1);
        $strati = DB::table('inpg_strati')->where('site_id', '5352')->first();
        $this->assertSame('Carbonifère', $strati->strati);
        $this->assertEqualsWithDelta(330.0, (float) $strati->age_inf, 0.01);

        $this->assertDatabaseCount('inpg_documents', 2); // dont 1 non diffusable (diff_doc=3)
        $this->assertDatabaseCount('inpg_lieudits', 1);
        $this->assertDatabaseCount('inpg_notes', 1);
        $this->assertDatabaseCount('inpg_biblio', 1);
        $biblio = DB::table('inpg_biblio')->where('site_id', '5352')->value('biblio');
        $this->assertStringContainsString('Dupont J. (2020)', (string) $biblio);
        $this->assertDatabaseCount('inpg_accessibilites', 1);
        $this->assertDatabaseCount('inpg_typologies', 1);
        $this->assertDatabaseCount('inpg_cartes', 1);

        // Pivot communes depuis le CSV (mode attributs, sans surfaces).
        $this->assertDatabaseCount('inpg_communes', 1);
        $p = DB::table('inpg_communes')->where('site_id', '5352')->first();
        $this->assertSame('27375', $p->insee);
    }

    public function test_filtre_diffusion_cote_requete(): void
    {
        app(InpnImportService::class)->importInpg(null, null, avecGeojson: false);

        // Le site confidentiel est en base (flag conservé) mais JAMAIS visible.
        $this->assertSame(2, DB::table('inpg_sites')->count());
        $this->assertSame(1, Inpg::sitesVisibles()->count());
        $this->assertNotNull(Inpg::sitePublic('NAQ0004'));
        $this->assertNull(Inpg::sitePublic('XXX9999'));
        $this->assertSame(1, Inpg::compteurCommune('27375'));

        // Documents : filtre diff_doc IN (1,2) posé côté requête.
        $docs = Inpg::documentsVisibles('5352');
        $this->assertCount(1, $docs);
        $this->assertSame('Plan du site', $docs[0]['lb_doc']);
    }

    public function test_html_inpn_nettoye(): void
    {
        app(InpnImportService::class)->importInpg(null, null, avecGeojson: false);

        $site = Inpg::sitePublic('NAQ0004');
        $html = Inpg::htmlAutorise((string) $site->descr_geol);
        $this->assertStringContainsString('<b>géologique</b>', (string) $html);
        $this->assertStringNotContainsString('<script>', (string) $html);
        $this->assertStringNotContainsString('<a ', (string) $html);
        $this->assertStringContainsString('lien', (string) $html); // texte du lien conservé, balise retirée
    }

    public function test_journalise_et_idempotent(): void
    {
        $service = app(InpnImportService::class);
        $service->importInpg(null, null, avecGeojson: false);
        $retour = $service->importInpg(null, null, avecGeojson: false);

        $this->assertSame(2, $retour['sites']);
        $this->assertDatabaseCount('inpg_sites', 2);

        $journal = ReferentielImport::where('source', 'inpg')->first();
        $this->assertNotNull($journal);
        $this->assertSame(2, (int) $journal->nb_entites);
    }
}
