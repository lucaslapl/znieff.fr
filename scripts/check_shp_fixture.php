<?php

// Vérification ponctuelle du lecteur SHP sur la fixture (hors PHPUnit).
require __DIR__.'/../app/Support/ShapefileReader.php';

use App\Support\ShapefileReader;

$r = new ShapefileReader(__DIR__.'/../tests/fixtures/shapefile/ZNIEFF_TEST.shp');
echo 'count: ', $r->count(), "\n";
echo 'type: ', $r->shapeType(), "\n";
echo 'bbox: ', json_encode($r->bbox()), "\n";
foreach ($r->records() as $i => $rec) {
    echo "--- #$i attrs: ", json_encode($rec['attrs'], JSON_UNESCAPED_UNICODE), "\n";
    echo 'wkt: ', substr((string) $rec['wkt'], 0, 170), "\n";
}
