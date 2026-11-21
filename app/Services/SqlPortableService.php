<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dump SQL data-only en pur PHP (sans pg_dump, donc sans proc_open) :
 * généré en local après l'import, à importer côté hébergeur (Plesk, MySQL
 * sans PostGIS — cf. portail:export-sql). Le dump REMPLACE les données
 * existantes (DELETE avant chaque INSERT, ordre inverse des FK), aucune
 * géométrie ne quitte le conteneur local.
 *
 * Deux pilotes :
 * - mysql (production mutualisée) : booléens 1/0, SET NAMES utf8mb4,
 *   identifiants non quotés ;
 * - pgsql (repli local) : booléens true/false, client_encoding UTF8.
 */
class SqlPortableService
{
    /**
     * Ordre d'insertion respecté (parents avant enfants) + FK
     * auto-référente zonages.id_parent réglée à part (UPDATE après).
     */
    private array $tables = [
        'referentiel_imports',
        'communes',
        'zonages',            // id_parent omis des INSERT (voir ci-dessous)
        'znieff_especes',
        'commune_communes',
        'zonage_communes',
        'natura_sites',
        'natura_especes',
        'natura_habitats',
        'natura_communes',
        'aires_protegees',
        'ap_communes',
    ];

    /**
     * Colonnes booléennes par table : le pilote SQLite (tests) stringifie
     * tout ('1'/'0'), Postgres rend de vrais booléens — sans cette carte,
     * un « 1 » textuel serait dumpé '1' au lieu de 1 (pilote mysql).
     */
    private array $booleens = [
        'communes' => ['has_abc'],
        'aires_protegees' => ['doc_gestion'],
        'znieff_especes' => ['determinante', 'confidentielle'],
        'natura_especes' => ['annexe_ii', 'annexe_1', 'prioritaire'],
        'natura_habitats' => ['prioritaire'],
    ];

    /**
     * @return array<string, int> lignes écrites par table
     */
    public function dump(string $out, string $driver = 'mysql'): array
    {
        if (! in_array($driver, ['mysql', 'pgsql'], true)) {
            throw new \InvalidArgumentException("Pilote inattendu : {$driver} (mysql|pgsql)");
        }
        if (! is_dir(dirname($out))) {
            mkdir(dirname($out), 0777, true);
        }

        $handle = fopen($out, 'w');
        fwrite($handle, '-- Export portable Portail ZNIEFF (pilote '.$driver.', sans géométrie) — '.now()->toDateTimeString()."\n");
        if ($driver === 'mysql') {
            fwrite($handle, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n");
        } else {
            fwrite($handle, "SET client_encoding = 'UTF8';\nSET statement_timeout = 0;\n");
        }
        fwrite($handle, "BEGIN;\n\n");

        foreach (array_reverse($this->tables) as $table) {
            fwrite($handle, "DELETE FROM {$table};\n");
        }
        fwrite($handle, "\n");

        $counts = [];
        foreach ($this->tables as $table) {
            $counts[$table] = $this->dumpTable($handle, $table, $driver);
            fwrite($handle, "\n");
        }

        // FK auto-référente : les liens ZNIEFF I → ZNIEFF II sont posés en
        // second passage (pas de FK deferrable en MySQL).
        $counts['zonages.id_parent'] = $this->dumpParents($handle);

        if ($driver === 'mysql') {
            fwrite($handle, "SET FOREIGN_KEY_CHECKS = 1;\n");
        }
        fwrite($handle, "COMMIT;\n");
        fclose($handle);

        return $counts;
    }

    private function dumpTable($handle, string $table, string $driver): int
    {
        // zonages : id_parent inséré à part (ordre topologique inconnu
        // au dump — la colonne reste NULL puis est mise à jour).
        $exclure = $table === 'zonages' ? ['id_parent'] : [];

        $columns = array_values(array_diff(
            Schema::getColumnListing($table),
            $exclure
        ));
        $bools = $this->booleens[$table] ?? [];

        $batch = [];
        $count = 0;
        foreach (DB::table($table)->select($columns)->cursor() as $row) {
            $batch[] = '('.implode(', ', array_map(
                fn ($c) => $this->sqlValue($row->{$c}, $driver, in_array($c, $bools, true)),
                $columns
            )).')';
            $count++;
            if (count($batch) >= 500) {
                $this->writeBatch($handle, $table, $columns, $batch);
                $batch = [];
            }
        }
        if ($batch !== []) {
            $this->writeBatch($handle, $table, $columns, $batch);
        }

        return $count;
    }

    private function dumpParents($handle): int
    {
        $count = 0;
        foreach (DB::table('zonages')->whereNotNull('id_parent')->select(['id', 'id_parent'])->cursor() as $row) {
            fwrite($handle, 'UPDATE zonages SET id_parent = '.$this->sqlValue($row->id_parent, 'mysql')
                .' WHERE id = '.$this->sqlValue($row->id, 'mysql').";\n");
            $count++;
        }
        if ($count > 0) {
            fwrite($handle, "\n");
        }

        return $count;
    }

    /**
     * @param  array<int, string>  $columns
     * @param  array<int, string>  $batch
     */
    private function writeBatch($handle, string $table, array $columns, array $batch): void
    {
        fwrite($handle, 'INSERT INTO '.$table.' ('.implode(', ', $columns).") VALUES\n"
            .implode(",\n", $batch).";\n");
    }

    private function sqlValue(mixed $v, string $driver, bool $booleen = false): string
    {
        if ($v === null) {
            return 'NULL';
        }
        if ($v === true || $v === 't') {
            return $driver === 'mysql' ? '1' : 'true';
        }
        if ($v === false || $v === 'f') {
            return $driver === 'mysql' ? '0' : 'false';
        }
        // SQLite (tests) : booléens stringifiés '1'/'0'
        if ($booleen && ($v === '1' || $v === 1)) {
            return $driver === 'mysql' ? '1' : 'true';
        }
        if ($booleen && ($v === '0' || $v === 0)) {
            return $driver === 'mysql' ? '0' : 'false';
        }

        return "'".str_replace("'", "''", (string) $v)."'";
    }
}
