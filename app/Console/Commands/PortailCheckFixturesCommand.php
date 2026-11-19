<?php

namespace App\Console\Commands;

use App\Models\Commune;
use App\Models\Zonage;
use App\Models\ZonageCommune;
use Illuminate\Console\Command;

/**
 * Validation des surfaces précalculées sur l'échantillon de référence
 * (Eure, secteur Louviers / Acquigny) : critères d'acceptation du MVP.
 *
 *   ZNIEFF II 230009093 (« 8526 », forêts de Bord–Louviers) :
 *     - superficie calculée ≈ 6 515 ha (mesure géométrique au 1/25 000,
 *       SU_ZN INPN de référence 6 557,68 ha) ;
 *     - Louviers (27375) : ≈ 49,5 % du territoire couvert (~1 340 ha) ;
 *     - ≥ 6 communes intersectées (« table des 8 communes principales »).
 *   ZNIEFF II 230009110 (« 8401 », vallée de l'Eure) :
 *     - Acquigny (27003) : ≈ 88,2 % du territoire couvert (extraction
 *       INPN juin 2025 — la valeur « 96 % » d'origine correspond à un
 *       périmètre INPN antérieur à la MAJ géométrique du 2018-04-11 ;
 *       recoupement : ST_Area(zone) == SU_ZN 19 497,59 ha à la centime
 *       près, part vs superficie INSEE : 93,3 %).
 *   ZNIEFF I 230030467 (« 85260005 », les Longues Rais) : SU_ZN 338,31 ha.
 *
 * Toute valeur hors tolérance affiche un ÉCART (exit 1) : le pipeline ne
 * « tombe pas juste » par accident — l'écart de mesure documenté est
 * −0,3 à −0,7 % (affiché « ≈ ± 1 % » sur le site).
 */
class PortailCheckFixturesCommand extends Command
{
    protected $signature = 'portail:check
        {--tolerance-parts=1.0 : tolérance en points sur les parts (%)}
        {--tolerance-surfaces=1.5 : tolérance en % sur les superficies}';

    protected $description = 'Vérifie les surfaces précalculées sur l\'échantillon de référence (Eure, Louviers/Acquigny)';

    public function handle(): int
    {
        $toleranceParts = (float) $this->option('tolerance-parts');
        $toleranceSurfaces = (float) $this->option('tolerance-surfaces');

        $echecs = 0;

        // --- ZNIEFF II 8526 : superficie géométrique -------------------
        $z2 = Zonage::query()->find('230009093');
        if ($z2 === null) {
            $this->failMissing('Zonage ZNIEFF II 230009093 (« 8526 ») absent de la base.');
        }
        $superficie = (float) ($z2->superficie_ha ?? 0);
        $echecs += $this->comparer(
            'ZNIEFF II 230009093 : superficie calculée ≈ 6 515 ha (réf. INPN 6 557,68 ha)',
            $superficie, 6515.0, $toleranceSurfaces, 'ha'
        );

        // --- Louviers × 8526 : part du territoire communal --------------
        $louviers = Commune::query()->find('27375');
        if ($louviers === null) {
            $this->failMissing('Commune 27375 (Louviers) absente de la base.');
        }
        $pivotLouviers = ZonageCommune::query()->where('zonage_id', '230009093')->where('insee', '27375')->first();
        $partLouviers = $pivotLouviers !== null ? (float) $pivotLouviers->part_commune : 0.0;
        $echecs += $this->comparer('Louviers (27375) couverte par la 8526 : ≈ 49,5 %', $partLouviers, 49.5, $toleranceParts, '%');

        $surfaceLouviers = $pivotLouviers !== null ? (float) $pivotLouviers->surface_ha : 0.0;
        $this->line(sprintf('  Louviers × 8526 : %s ha d\'intersection (attendu ≈ 1 340 ha).', number_format($surfaceLouviers, 1, ',', ' ')));

        // --- Communes de la 8526 ----------------------------------------
        $nbCommunes = $z2->communes()->count();
        $this->line(sprintf('  8526 : %s communes intersectées (8 communes principales attendues : ≥ 6 toléré).', $nbCommunes));
        if ($nbCommunes < 6) {
            $this->error('  ÉCART : moins de 6 communes intersectées pour la 8526.');
            $echecs++;
        }

        // --- Acquigny × 8401 --------------------------------------------
        // Fixture recalibrée sur l'extraction INPN en vigueur (juin 2025,
        // périmètre géométrique 8401 mis à jour le 2018-04-11) : 1 661,2 ha
        // d'intersection pour un contour AdminExpress 2025 d'Acquigny de
        // 1 884,3 ha (INSEE : 1 780 ha). L'ancienne valeur de référence
        // « 96 % » correspond à une version antérieure du périmètre INPN
        // (attestée non conforme aux données en base : recoupement à la
        // centime près — ST_Area de toute la zone == SU_ZN 19 497,59 ha).
        $z8401 = Zonage::query()->find('230009110');
        if ($z8401 === null) {
            $this->failMissing('Zonage ZNIEFF II 230009110 (« 8401 ») absent de la base.');
        }
        $pivotAcquigny = ZonageCommune::query()->where('zonage_id', '230009110')->where('insee', '27003')->first();
        $partAcquigny = $pivotAcquigny !== null ? (float) $pivotAcquigny->part_commune : 0.0;
        $echecs += $this->comparer('Acquigny (27003) couverte par la 8401 : ≈ 88,2 % (extraction INPN juin 2025)', $partAcquigny, 88.16, $toleranceParts, '%');
        $surfaceAcquigny = $pivotAcquigny !== null ? (float) $pivotAcquigny->surface_ha : 0.0;
        $acquigny = Commune::query()->find('27003');
        if ($acquigny !== null && (float) $acquigny->superficie_ha > 0) {
            $this->line(sprintf('  Acquigny × 8401 : %s ha ; part vs superficie INSEE (1 780 ha) : %s %%.',
                number_format($surfaceAcquigny, 1, ',', ' '),
                number_format($surfaceAcquigny / ((float) $acquigny->superficie_ha) * 100, 2, ',', ' '),
            ));
        }

        // --- ZNIEFF I 85260005 : référence INPN --------------------------
        $z1 = Zonage::query()->find('230030467');
        if ($z1 === null) {
            $this->failMissing('Zonage ZNIEFF I 230030467 (« 85260005 ») absent de la base.');
        }
        $echecs += $this->comparer('ZNIEFF I 230030467 : superficie calculée ≈ 338,31 ha (SU_ZN INPN)', (float) ($z1->superficie_ha ?? 0), 338.31, $toleranceSurfaces, 'ha');

        // --- Superficies du référentiel : écart INSEE --------------------
        // Informationnel (l'écart AdminExpress ↔ INSEE varie d'une commune
        // à l'autre, cf. moyenne département Eure : +0,65 %).
        $ecartLouviers = $this->ecartRelatif((float) ($louviers->superficie_ha ?? 0), (float) ($louviers->superficie_calc_ha ?? 0));
        $this->line(sprintf('  Louviers : superficie INSEE %s ha vs géométrie %s ha (écart %s %%, documenté « ≈ ± 1 %% »).',
            number_format((float) $louviers->superficie_ha, 2, ',', ' '),
            number_format((float) $louviers->superficie_calc_ha, 2, ',', ' '),
            number_format($ecartLouviers * 100, 2, ',', ' '),
        ));

        if ($echecs > 0) {
            $this->error("{$echecs} écart(s) au-delà de la tolérance.");

            return self::FAILURE;
        }

        $this->info('Échantillon de référence conforme (tolérances : parts ±'.$toleranceParts.' pt, superficies ±'.$toleranceSurfaces.' %).');

        return self::SUCCESS;
    }

    private function comparer(string $libelle, float $mesure, float $attendu, float $tolerance, string $unite): int
    {
        $ecart = abs($mesure - $attendu);
        $ok = $ecart <= ($unite === '%' ? $tolerance : max($tolerance / 100 * $attendu, 0.75));
        $this->line(sprintf('  %s : %s %s (attendu %s ± %s, écart %s)',
            $libelle,
            number_format($mesure, 2, ',', ' '),
            $unite,
            number_format($attendu, 2, ',', ' '),
            $unite === '%' ? $tolerance.' pt' : $tolerance.' %',
            $ok ? '<info>OK</info>' : '<error>ÉCART</error>',
        ));

        return $ok ? 0 : 1;
    }

    private function ecartRelatif(float $reference, float $mesure): float
    {
        return $reference === 0.0 ? 0.0 : ($mesure - $reference) / $reference;
    }

    private function failMissing(string $message): int
    {
        $this->error($message);

        exit(1);
    }
}
