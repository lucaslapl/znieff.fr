<?php

namespace App\Console\Commands;

use App\Services\InpnImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Import des référentiels INPN (data/inpn-data/) vers les tables du
 * portail. À lancer EN LOCAL UNIQUEMENT (conteneur Docker + PostGIS) :
 * la production reçoit ensuite le SQL portable généré par
 * portail:export-sql et les GeoJSON précalculés (public/geo).
 *
 * Idempotent : chaque source est rechargée intégralement (les FK
 * cascadent). L'ordre des sources n'importe pas, mais le référentiel
 * communal doit être à jour (portail:refresh-communes).
 *
 * IMPORTANT : le mode --dept restreint le PéRIMÈTRE DE LA TABLE de façon
 * destructive (mode test d'itération : la table zonages ne contient plus
 * que les zones du département). Sans --dept : France entière.
 */
class PortailImportInpnCommand extends Command
{
    protected $signature = 'portail:import-inpn
        {--source=all : znieff|natura|aires|all}
        {--dept= : un seul département (mode test, ex. 27) — périmètre de table destructif}
        {--skip-geojson : ne pas (ré)générer les surcouches GeoJSON}';

    protected $description = 'Importe les référentiels INPN (ZNIEFF, Natura 2000, aires protégées) et précalcule les croisements';

    public function handle(InpnImportService $inpn): int
    {
        // Import one-shot en local : les shapefiles nationaux contiennent des
        // polygones à plusieurs centaines de milliers de sommets (ZNIEFF II
        // marines notamment). Le PHP-FPM public reste à sa limite standard.
        ini_set('memory_limit', '2G');
        $source = (string) $this->option('source');
        $dept = $this->option('dept') !== null ? (string) $this->option('dept') : null;
        $avecGeojson = ! $this->option('skip-geojson');

        if (! in_array($source, ['znieff', 'natura', 'aires', 'all'], true)) {
            $this->error("Source inconnue : {$source} (znieff|natura|aires|all)");

            return self::FAILURE;
        }
        if ($dept !== null && ! preg_match('/^([0-9]{2}|2A|2B|97[1-6])$/', $dept)) {
            $this->error("Département invalide : {$dept} (ex. 27, 2A, 971)");

            return self::FAILURE;
        }
        if ($dept !== null && (int) DB::table('communes')->count() > 0) {
            $comptees = (int) DB::table('communes')->where('dept', $dept)->count();
            if ($comptees === 0) {
                $this->error("Aucune commune du département {$dept} : lancez d'abord `portail:refresh-communes --dept={$dept}`.");

                return self::FAILURE;
            }
            $this->warn('Mode départemental ('.$dept.') : périmètre de table destructif — la table ne contiendra plus que ce département.');
        }

        $tick = fn () => null;
        $progression = function (int $pas): void {};

        if (in_array($source, ['znieff', 'all'], true)) {
            $this->info('ZNIEFF…');
            $retour = $inpn->importZnieff($dept, $progression, $avecGeojson);
            $this->afficher('znieff', $retour, ['zones', 'especes', 'geometries', 'intersections']);
        }

        if (in_array($source, ['natura', 'all'], true)) {
            $this->info('Natura 2000…');
            $retour = $inpn->importNatura($dept, $progression, $avecGeojson);
            $this->afficher('natura', $retour, ['sites', 'especes', 'habitats', 'geometries', 'intersections']);
        }

        if (in_array($source, ['aires', 'all'], true)) {
            $this->info('Aires protégées…');
            $retour = $inpn->importAires($dept, $progression, $avecGeojson);
            $this->afficher('aires', $retour, ['aires', 'geometries', 'intersections']);
        }

        $this->info('Import INPN terminé.');
        $this->line('SQL portable : `php artisan portail:export-sql`.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, int|bool>  $retour
     * @param  array<int, string>  $cles
     */
    private function afficher(string $source, array $retour, array $cles): void
    {
        foreach ($cles as $cle) {
            $this->line(sprintf('  %s : %s', $cle, number_format((float) ($retour[$cle] ?? 0))));
        }
        if (! empty($retour['spatial']) === false) {
            $this->warn('  PostGIS indisponible : attributs seuls (SQLite ?) — relancer dans le conteneur Docker.');
        }
        if (($retour['geojson'] ?? 0) > 0) {
            $this->line(sprintf('  geojson : %s fichiers', number_format((float) $retour['geojson'])));
        }
        if (($retour['communes_manquantes'] ?? 0) > 0) {
            $this->warn(sprintf('  %s communes AdminExpress absentes du référentiel base.', (int) $retour['communes_manquantes']));
        }
        $this->line(sprintf('  journal « %s » : %s', $source, (string) config("portail.inpn.versions.{$source}")));
    }
}
