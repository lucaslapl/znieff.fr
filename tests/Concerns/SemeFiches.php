<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Graine de test des fiches (itération b) : un secteur réaliste
 * Louviers / Acquigny (valeurs de l'échantillon de référence du
 * portail:check), une commune sans zonage, un site Natura 2000 et une
 * aire protégée APPB — cohérent avec les migrations, sans géométrie.
 */
trait SemeFiches
{
    protected function semeFiches(): void
    {
        DB::table('communes')->insert([
            [
                'insee' => '27375', 'slug' => 'louviers', 'nom' => 'Louviers',
                'dept' => '27', 'dept_nom' => 'Eure', 'reg_code' => '28', 'reg_nom' => 'Normandie',
                'population' => 18347, 'superficie_ha' => 2710, 'superficie_calc_ha' => 2675.21,
                'lat' => 49.220364, 'lng' => 1.153689, 'codes_postaux' => '27400', 'has_abc' => true,
            ],
            [
                'insee' => '27003', 'slug' => 'acquigny', 'nom' => 'Acquigny',
                'dept' => '27', 'dept_nom' => 'Eure', 'reg_code' => '28', 'reg_nom' => 'Normandie',
                'population' => 1725, 'superficie_ha' => 1780, 'superficie_calc_ha' => 1884.30,
                'lat' => 49.18, 'lng' => 1.02, 'codes_postaux' => '27400', 'has_abc' => false,
            ],
            [
                'insee' => '27558', 'slug' => 'sainte-colombe-pres-vernnon', 'nom' => 'Sainte-Colombe-près-Vernnon',
                'dept' => '27', 'dept_nom' => 'Eure', 'reg_code' => '28', 'reg_nom' => 'Normandie',
                'population' => 318, 'superficie_ha' => 590, 'superficie_calc_ha' => 585.0,
                'lat' => 48.9, 'lng' => 1.15, 'codes_postaux' => '27999', 'has_abc' => false,
            ],
        ]);

        DB::table('commune_communes')->insert([
            ['insee_a' => '27003', 'insee_b' => '27375', 'distance_m' => 4200],
        ]);

        DB::table('zonages')->insert([
            [
                'id' => '230009093', 'famille' => 'z2', 'nom' => 'Forêt de Bord et de Louviers',
                'num_regional' => '8526', 'id_parent' => null,
                'superficie_ha' => 6557.68, 'superficie_ref_ha' => 6557.68,
                'precision_saisie' => '1/25 000', 'date_crea' => '1982-01-01',
                'date_modif_geo' => '2024-07-15', 'territoire' => 'metrop', 'volet' => 'CONT',
                'url_inpn' => 'https://inpn.mnhn.fr/zone/znieff/230009093',
            ],
            [
                'id' => '230030467', 'famille' => 'z1', 'nom' => 'Les Longues Rais',
                'num_regional' => '85260005', 'id_parent' => '230009093',
                'superficie_ha' => 338.31, 'superficie_ref_ha' => 338.31,
                'precision_saisie' => '1/25 000', 'date_crea' => '2010-01-01',
                'date_modif_geo' => '2024-07-15', 'territoire' => 'metrop', 'volet' => 'CONT',
                'url_inpn' => 'https://inpn.mnhn.fr/zone/znieff/230030467',
            ],
        ]);

        DB::table('zonage_communes')->insert([
            ['zonage_id' => '230009093', 'insee' => '27375', 'surface_ha' => 1340.10, 'part_zonage' => 20.41, 'part_commune' => 49.54],
            ['zonage_id' => '230009093', 'insee' => '27003', 'surface_ha' => 1661.22, 'part_zonage' => 25.33, 'part_commune' => 88.16],
            ['zonage_id' => '230030467', 'insee' => '27375', 'surface_ha' => 97.40, 'part_zonage' => 28.80, 'part_commune' => 3.60],
        ]);

        DB::table('natura_sites')->insert([
            [
                'code' => 'FR2300128', 'type' => 'SIC', 'nom' => 'La Vallée de l\'Eure',
                'superficie_ha' => 728.5, 'superficie_ref_ha' => 731.0,
                'precision_saisie' => '1/25 000', 'date_designation' => '1998-03-24',
                'date_modif_geo' => '2023-06-13', 'territoire' => 'metrop',
                'url_inpn' => 'https://inpn.mnhn.fr/site/natura2000/FR2300128',
            ],
        ]);
        DB::table('natura_communes')->insert([
            ['site_code' => 'FR2300128', 'insee' => '27375', 'surface_ha' => 100.50, 'part_zonage' => 13.80, 'part_commune' => 3.75],
        ]);

        DB::table('aires_protegees')->insert([
            [
                'id' => 'FR3800583', 'type_code' => 'APPB', 'type_nom' => 'Arrêté de protection de biotope, habitat ou site',
                'nom' => 'Bois de la hete',
                'superficie_ha' => 12.5, 'superficie_ref_ha' => 12.5,
                'acte' => 'Arrêté préfectoral du 12/02/1990', 'gestionnaire' => 'Préfecture de l\'Eure',
                'objectif_protection' => null, 'doc_gestion' => false,
                'date_crea_sign' => '1990-02-12', 'date_modif_geo' => '2010-09-08',
                'territoire' => 'metrop',
                'url_inpn' => 'https://inpn.mnhn.fr/protection/FR3800583',
            ],
        ]);
        DB::table('ap_communes')->insert([
            ['ap_id' => 'FR3800583', 'insee' => '27375', 'surface_ha' => 10.20, 'part_zonage' => 81.60, 'part_commune' => 0.38],
        ]);
    }
}
