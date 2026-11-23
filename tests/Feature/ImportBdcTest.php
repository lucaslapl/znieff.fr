<?php

namespace Tests\Feature;

use App\Models\ReferentielImport;
use App\Services\InpnImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SemeFiches;
use Tests\TestCase;

/**
 * Import BDC v18 (statuts) + taxons_ref (TAXREF v18) en mode « attributs
 * seuls » (SQLite) : fixtures tests/fixtures/inpn-bdc et inpn-taxref,
 * périmètre = graine SemeFiches (Louviers/Acquigny, dept 27).
 */
class ImportBdcTest extends TestCase
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
    }

    public function test_importe_les_statuts_du_perimetre_sans_zdet_sensibilite_ni_communes(): void
    {
        $retour = app(InpnImportService::class)->importBdc();

        // 10 lignes fixture, moins ZDET + SENSNAT (999001) et INSEEC (60400) et hors périmètre (123456) = 6.
        $this->assertSame(6, $retour['statuts']);
        $this->assertDatabaseCount('espece_statuts', 6);

        // 3161 : PN + DH + LRN + BERN, tous TERFXFR.
        $types = DB::table('espece_statuts')->where('cd_ref', 3161)->pluck('cd_type_statut')->all();
        sort($types);
        $this->assertSame(['BERN', 'DH', 'LRN', 'PN'], $types);

        // Jamais de SENSIBILITE ni de ZDET importés (filtre, pas affichage).
        $this->assertSame(0, DB::table('espece_statuts')->whereIn('cd_type_statut', ['ZDET', 'SENSNAT', 'SENSREG', 'SENSDEP'])->count());
        // Territoires communaux exclus.
        $this->assertSame(0, DB::table('espece_statuts')->where('cd_sig', 'like', 'INSEEC%')->count());
        // Hors périmètre : rien.
        $this->assertSame(0, DB::table('espece_statuts')->where('cd_ref', 123456)->count());

        // 60400 : seul le statut départemental INSEED27 reste.
        $this->assertSame(['PD'], DB::table('espece_statuts')->where('cd_ref', 60400)->pluck('cd_type_statut')->all());

        // Encodage cp1252 converti en UTF-8.
        $label = DB::table('espece_statuts')->where('cd_ref', 3161)->where('cd_type_statut', 'PN')->value('label_statut');
        $this->assertStringContainsString('espèces protégées', (string) $label);
    }

    public function test_importe_les_taxons_ref_du_perimetre_depuis_taxref_v18(): void
    {
        $retour = app(InpnImportService::class)->importBdc();

        // Périmètre SemeFiches : 3161, 1308, 999001, 60612, 60400.
        $this->assertSame(5, $retour['taxons']);
        $this->assertDatabaseCount('taxons_ref', 5);

        $t = DB::table('taxons_ref')->where('cd_ref', 3161)->first();
        $this->assertNotNull($t);
        $this->assertSame('Pluvialis apricaria', $t->nom_valide);
        $this->assertSame('Oiseaux', $t->groupe2_inpn);
        $this->assertSame('Terrestre', $t->habitat_libelle); // HABITAT=3 via habitats_note.csv
        $this->assertSame('P', $t->presence_fr);
        $this->assertFalse((bool) $t->present_om); // GF=I, pas P
        $this->assertSame('https://inpn.mnhn.fr/espece/cd_nom/3161', $t->url_inpn);

        // 60400 : présent en outre-mer (GF=P).
        $this->assertTrue((bool) DB::table('taxons_ref')->where('cd_ref', 60400)->value('present_om'));
    }

    public function test_journalise_le_bdc_importe(): void
    {
        app(InpnImportService::class)->importBdc();

        $journal = ReferentielImport::where('source', 'bdc')->first();
        $this->assertNotNull($journal);
        $this->assertSame(6, (int) $journal->nb_entites);
        $this->assertStringContainsString('v18', (string) $journal->version_source);

        // La ligne taxref reflète l'extraction v18 des fiches taxons.
        $taxref = ReferentielImport::where('source', 'taxref')->first();
        $this->assertNotNull($taxref);
        $this->assertStringContainsString('v18', (string) $taxref->version_source);
    }

    public function test_import_idempotent(): void
    {
        $service = app(InpnImportService::class);
        $service->importBdc();
        $retour = $service->importBdc();

        $this->assertSame(6, $retour['statuts']);
        $this->assertDatabaseCount('espece_statuts', 6);
        $this->assertDatabaseCount('taxons_ref', 5);
    }
}
