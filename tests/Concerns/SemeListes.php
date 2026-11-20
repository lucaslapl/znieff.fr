<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Graine des listes (itération d) : 30 communes fictives en
 * Ille-et-Vilaine (28) par-dessus l'échantillon Louviers / Acquigny
 * du trait SemeFiches — assez pour paginer (25/page) sans alourdir
 * la base des tests.
 */
trait SemeListes
{
    /** @var array<int, string> fichiers GeoJSON stubs créés puis nettoyés */
    private array $stubsGeo = [];

    protected function semeListes(): void
    {
        $lignes = [];
        for ($i = 1; $i <= 30; $i++) {
            $lignes[] = [
                'insee' => sprintf('28%03d', $i),
                'slug' => sprintf('commune-test-%02d', $i),
                'nom' => sprintf('Commune Test %02d', $i),
                'dept' => '28', 'dept_nom' => 'Ille-et-Vilaine', 'reg_code' => '53', 'reg_nom' => 'Bretagne',
                'population' => 100 + $i, 'superficie_ha' => 100 + $i, 'superficie_calc_ha' => 100 + $i,
                'lat' => 48.1, 'lng' => -1.6, 'codes_postaux' => '35000', 'has_abc' => false,
            ];
        }
        DB::table('communes')->insert($lignes);
    }

    /**
     * Garantit qu'un GeoJSON précalculé existe (celui de l'import
     * local s'il est là, sinon un stub minimal créé puis supprimé :
     * public/geo est gitignored, la CI n'a pas les fichiers réels).
     */
    protected function garantitGeojson(string $relatif): void
    {
        $chemin = public_path('geo/'.$relatif.'.geojson');
        if (is_file($chemin)) {
            return;
        }

        $dossier = dirname($chemin);
        if (! is_dir($dossier)) {
            mkdir($dossier, 0777, true);
        }
        file_put_contents($chemin, (string) json_encode([
            'type' => 'Feature',
            'properties' => [],
            'geometry' => ['type' => 'Point', 'coordinates' => [1.1, 49.1]],
        ]));
        $this->stubsGeo[] = $chemin;
    }

    protected function nettoieStubsGeo(): void
    {
        foreach ($this->stubsGeo as $chemin) {
            if (is_file($chemin)) {
                @unlink($chemin);
            }
        }
        $this->stubsGeo = [];
    }
}
