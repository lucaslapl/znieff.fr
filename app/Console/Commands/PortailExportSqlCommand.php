<?php

namespace App\Console\Commands;

use App\Services\SqlPortableService;
use Illuminate\Console\Command;

/**
 * Génère le SQL portable destiné à la production (MySQL mutualisé
 * PulseHéberg, sans PostGIS) : data-only, sans aucune géométrie, chaque
 * table remplacée (DELETE avant INSERT, ordre inverse des FK). À générer
 * en local après portail:refresh-communes + portail:import-inpn.
 *
 * Les GeoJSON précalculés (public/geo/) sont à copier tels quels.
 */
class PortailExportSqlCommand extends Command
{
    protected $signature = 'portail:export-sql
        {--out= : fichier SQL de sortie}
        {--driver=mysql : mysql (production Plesk) | pgsql}';

    protected $description = 'Génère le SQL portable de déploiement (sans géométrie, pilote mysql/pgsql)';

    public function handle(SqlPortableService $dump): int
    {
        // Dump mono-fichier de toutes les tables : les pivots France entière
        // représentent plusieurs millions de lignes en mémoire tampon.
        ini_set('memory_limit', '2G');
        $driver = (string) $this->option('driver');
        if (! in_array($driver, ['mysql', 'pgsql'], true)) {
            $this->error("Pilote inconnu : {$driver} (mysql|pgsql)");

            return self::FAILURE;
        }

        $out = (string) ($this->option('out')
            ?? config('portail.export_dir')."/portail-deploy-{$driver}.sql");

        $counts = $dump->dump($out, $driver);
        foreach ($counts as $table => $count) {
            $this->line(sprintf('  %s : %s lignes', $table, number_format((float) $count)));
        }

        $this->info(sprintf('Fichier généré : %s (%s octets) — pilote %s.',
            $out,
            number_format(filesize($out)),
            $driver,
        ));
        $this->line('Les surcouches GeoJSON (public/geo/) sont à copier telles quelles.');

        return self::SUCCESS;
    }
}
