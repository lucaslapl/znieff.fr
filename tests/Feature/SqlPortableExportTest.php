<?php

namespace Tests\Feature;

use App\Services\SqlPortableService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Dump SQL portable : ordre des DELETE (FK inversés), INSERT par lots,
 * pilote MySQL (production Plesk) — booléens 1/0, SET NAMES utf8mb4,
 * FK auto-référente zonages.id_parent posée en second passage.
 */
class SqlPortableExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_genere_un_dump_mysql_coherent(): void
    {
        DB::table('referentiel_imports')->insert([
            'source' => 'znieff', 'version_source' => 'extraction juin 2025',
            'nb_entites' => 3, 'importe_le' => now(),
        ]);
        DB::table('communes')->insert([
            'insee' => '27375', 'slug' => 'louviers', 'nom' => 'Louviers',
            'dept' => '27', 'dept_nom' => 'Eure', 'reg_code' => '28', 'reg_nom' => 'Normandie',
            'population' => 18347, 'superficie_ha' => 2710, 'superficie_calc_ha' => 2707.5,
            'lat' => 49.17, 'lng' => 1.12, 'codes_postaux' => '27400', 'has_abc' => true,
        ]);
        DB::table('zonages')->insert([
            'id' => '230009093', 'famille' => 'z2', 'nom' => 'Forêt de Bord et de Louviers',
            'superficie_ha' => 6515.2, 'superficie_ref_ha' => 6557.68,
            'precision_saisie' => '1/25 000', 'territoire' => 'metrop', 'volet' => 'CONT',
        ]);
        DB::table('zonages')->insert([
            'id' => '230030467', 'famille' => 'z1', 'nom' => 'Les Longues Rais',
            'superficie_ref_ha' => 338.31, 'precision_saisie' => '1/25 000',
            'territoire' => 'metrop', 'id_parent' => '230009093',
        ]);
        DB::table('zonage_communes')->insert([
            'zonage_id' => '230009093', 'insee' => '27375',
            'surface_ha' => 1340.1, 'part_zonage' => 20.57, 'part_commune' => 49.54,
        ]);
        DB::table('znieff_especes')->insert([
            ['zonage_id' => '230009093', 'cd_ref' => 3161, 'nom_cite' => '<i>Pluvialis apricaria</i>', 'groupe_taxo' => 'Oiseaux', 'determinante' => true, 'confidentielle' => false],
            ['zonage_id' => '230009093', 'cd_ref' => 999001, 'nom_cite' => '<i>Rhinolophus ferrumequinum</i>', 'groupe_taxo' => 'Mammifères', 'determinante' => false, 'confidentielle' => true],
        ]);
        DB::table('natura_sites')->insert([
            'code' => 'FR2300128', 'type' => 'SIC', 'nom' => "La Vallée de l'Eure",
            'superficie_ha' => 728.5, 'superficie_ref_ha' => 731.0,
            'precision_saisie' => '1/25 000', 'territoire' => 'metrop',
        ]);
        DB::table('natura_especes')->insert([
            ['site_code' => 'FR2300128', 'code_n2000' => 'A136', 'cd_ref' => 3161, 'nom_scientifique' => 'Pluvialis apricaria', 'nom_vernaculaire' => 'Pluvier doré', 'taxgroup' => 'Oiseaux', 'annexe_ii' => false, 'annexe_1' => true, 'prioritaire' => false, 'presences' => 'r,w'],
        ]);
        DB::table('natura_habitats')->insert([
            ['site_code' => 'FR2300128', 'cd_ue' => '2110', 'nom' => 'Dunes mobiles', 'prioritaire' => true, 'cd_hab' => '2682', 'representativite' => 'B', 'conservation' => 'C', 'evaluation_globale' => 'C'],
        ]);

        $out = storage_path('framework/testing/portail-deploy-test.sql');
        @unlink($out);

        $counts = app(SqlPortableService::class)->dump($out, 'mysql');
        $sql = (string) file_get_contents($out);

        $this->assertGreaterThan(0, $counts['communes']);
        $this->assertSame(2, $counts['zonages']);
        $this->assertSame(1, $counts['zonages.id_parent']);

        // Headers MySQL, transaction, ordre inverse des FK pour les DELETE
        $this->assertStringContainsString('SET NAMES utf8mb4', $sql);
        $this->assertStringContainsString('SET FOREIGN_KEY_CHECKS = 0', $sql);
        $this->assertStringContainsString('BEGIN;', $sql);
        $this->assertStringContainsString('DELETE FROM zonage_communes;', $sql);
        $this->assertStringContainsString('DELETE FROM communes;', $sql);
        $this->assertGreaterThan(
            strpos($sql, 'DELETE FROM zonage_communes;'),
            strpos($sql, 'DELETE FROM communes;'),
            'les enfants sont vidés avant les parents'
        );

        // INSERT sans id_parent, UPDATE en second passage
        $this->assertStringNotContainsString('SET id_parent = NULL', $sql);
        $this->assertStringContainsString("UPDATE zonages SET id_parent = '230009093' WHERE id = '230030467';", $sql);

        // Booléen au format MySQL (1/0), dernier champ « utile » de la ligne
        // de commune (codes_postaux puis has_abc)
        $this->assertStringContainsString("'27400', 1", $sql);
        $this->assertStringContainsString("'Forêt de Bord et de Louviers'", $sql);

        // Tables naturalistes dans le dump (ordre FK : enfants après parents)
        $this->assertSame(2, $counts['znieff_especes']);
        $this->assertSame(1, $counts['natura_especes']);
        $this->assertSame(1, $counts['natura_habitats']);
        $this->assertStringContainsString('DELETE FROM znieff_especes;', $sql);
        $this->assertStringContainsString('DELETE FROM natura_especes;', $sql);
        $this->assertStringContainsString('DELETE FROM natura_habitats;', $sql);
        $this->assertGreaterThan(
            strpos($sql, 'DELETE FROM znieff_especes;'),
            strpos($sql, 'DELETE FROM zonages;'),
            'les espèces sont vidées avant leur zonage parent'
        );
        // Booléens des tables d'espèces au format MySQL 1/0
        // (determinante, confidentielle en fin de ligne avant les timestamps)
        $this->assertStringContainsString("'Oiseaux', 1, 0", $sql);
        $this->assertStringContainsString("'Mammifères', 0, 1", $sql);
        $this->assertStringContainsString("'Oiseaux', 0, 1, 0, 'r,w'", $sql);

        unlink($out);
    }

    public function test_refuse_un_pilote_inconnu(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(SqlPortableService::class)->dump(storage_path('framework/testing/bad.sql'), 'oracle');
    }
}
