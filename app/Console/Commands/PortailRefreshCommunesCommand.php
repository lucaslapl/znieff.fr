<?php

namespace App\Console\Commands;

use App\Services\CommunesService;
use Illuminate\Console\Command;

/**
 * Rafraîchissement du référentiel communal (AdminExpress COG — IGN) :
 * communes, slugs uniques, population, superficies, centroïdes, codes
 * postaux (geo.api.gouv.fr), voisines (15 km), badges ABC (API de
 * l'Observatoire des ABC) et surcouches GeoJSON communales.
 *
 * À lancer en local (Docker + PostGIS) avant portail:import-inpn.
 * Idempotent (upserts par code INSEE).
 */
class PortailRefreshCommunesCommand extends Command
{
    protected $signature = 'portail:refresh-communes
        {--dept= : un seul département (mode test, ex. 27)}
        {--skip-geojson : ne pas (ré)générer les contours GeoJSON}
        {--skip-codes-postaux : ne pas requêter geo.api.gouv.fr}
        {--skip-abc : ne pas requêter l\'Observatoire des ABC}';

    protected $description = 'Rafraîchit le référentiel communal (AdminExpress, INSEE, geo.api, ABC)';

    public function handle(CommunesService $communes): int
    {
        // Import local one-shot : simplification des 35 000 contours
        // communaux, distance des voisines — gourmand en mémoire.
        ini_set('memory_limit', '2G');
        $dept = $this->option('dept') !== null ? (string) $this->option('dept') : null;
        if ($dept !== null && ! preg_match('/^([0-9]{2}|2A|2B|97[1-6])$/', $dept)) {
            $this->error("Département invalide : {$dept} (ex. 27, 2A, 971)");

            return self::FAILURE;
        }

        $this->info('Rafraîchissement des communes…');

        $retour = $communes->refresh(
            dept: $dept,
            tick: null,
            avecGeojson: ! $this->option('skip-geojson'),
            avecCodesPostaux: ! $this->option('skip-codes-postaux'),
            avecAbc: ! $this->option('skip-abc'),
        );

        $this->line(sprintf('  communes traitées : %s (ignorées : %s, total base : %s)',
            number_format((float) $retour['communes']),
            number_format((float) $retour['ignores']),
            number_format((float) $retour['total']),
        ));
        $this->line(sprintf('  voisines ≤ 15 km : %s paires', number_format((float) $retour['voisines'])));
        $this->line(sprintf('  codes postaux : %s mises à jour', number_format((float) $retour['codes_postaux'])));
        if ((int) $retour['abc'] === -1) {
            $this->warn('  badges ABC : Observatoire indisponible (has_abc inchangé)');
        } else {
            $this->line(sprintf('  communes ABC : %s', number_format((float) $retour['abc'])));
        }
        if ((int) $retour['geojson'] > 0) {
            $this->line(sprintf('  geojson : %s fichiers', number_format((float) $retour['geojson'])));
        }
        if (! $retour['spatial']) {
            $this->warn('  PostGIS indisponible : mode attributs seuls (relancer dans le conteneur Docker).');
        }

        return self::SUCCESS;
    }
}
