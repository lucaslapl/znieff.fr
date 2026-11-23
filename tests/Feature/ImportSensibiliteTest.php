<?php

namespace Tests\Feature;

use App\Models\ReferentielImport;
use App\Services\InpnImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Import du référentiel de sensibilité SINP v18 (SENSIBILITE_18/) en mode
 * « attributs seuls » (SQLite) : fixtures tests/fixtures/inpn-sensibilite.
 * Le référentiel national réel (~24 k lignes) est importé en local Docker.
 */
class ImportSensibiliteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'portail.inpn.sensibilite_dossier' => base_path('tests/fixtures/inpn-sensibilite'),
        ]);
    }

    public function test_importe_les_niveaux_par_taxon_et_departement(): void
    {
        $retour = app(InpnImportService::class)->importSensibilite();

        $this->assertSame(4, $retour['lignes']);
        $this->assertSame(4, $retour['taxons']);
        $this->assertSame(2, $retour['mtd']);

        $this->assertDatabaseCount('sensibilite_especes', 4);
        $this->assertDatabaseCount('sensibilite_mtd', 2);

        // Sensibilité départementale (84) avec durée de 20 ans.
        $ligne = DB::table('sensibilite_especes')->where('cd_ref', 53631)->first();
        $this->assertNotNull($ligne);
        $this->assertSame(2, (int) $ligne->cd_sensibilite);
        $this->assertSame(20, (int) $ligne->duree);
        $this->assertSame('84', $ligne->cd_dept);
        $this->assertSame(9, (int) $ligne->cd_sens_mtd);

        // Pré-liste nationale : CD_DEPT vide → null, durée permanente.
        $nationale = DB::table('sensibilite_especes')->where('cd_ref', 3161)->first();
        $this->assertNotNull($nationale);
        $this->assertNull($nationale->cd_dept);
        $this->assertNull($nationale->duree);
    }

    public function test_convertit_le_cp1252_des_methodes_en_utf8(): void
    {
        app(InpnImportService::class)->importSensibilite();

        $mtd = DB::table('sensibilite_mtd')->where('id', 9)->first();
        $this->assertNotNull($mtd);
        $this->assertSame('84', $mtd->cd_reg);
        $this->assertSame('Référentiel régional de données sensibles (exemple)', $mtd->libelle_liste);
        $this->assertSame('https://inpn.mnhn.fr/docs-web/docs/download/189968', $mtd->ressource);
    }

    public function test_journalise_le_referentiel_importe(): void
    {
        app(InpnImportService::class)->importSensibilite();

        $journal = ReferentielImport::where('source', 'sensibilite')->first();
        $this->assertNotNull($journal);
        $this->assertSame(4, (int) $journal->nb_entites);
        $this->assertStringContainsString('v18', (string) $journal->version_source);
    }

    public function test_import_idempotent(): void
    {
        $service = app(InpnImportService::class);
        $service->importSensibilite();
        $retour = $service->importSensibilite();

        $this->assertSame(4, $retour['lignes']);
        $this->assertDatabaseCount('sensibilite_especes', 4);
        $this->assertDatabaseCount('sensibilite_mtd', 2);
    }
}
