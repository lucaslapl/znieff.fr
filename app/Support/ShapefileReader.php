<?php

namespace App\Support;

use RuntimeException;

/**
 * Lecteur de shapefiles ESRI (.shp + .dbf), en flux, sans dépendance.
 *
 * Même approche maison que le lecteur GPKG de l'Observatoire des ABC
 * (gpkgWkb) : on ne charge jamais le fichier entier, on rend une géométrie
 * WKT par enregistrement, avec ses attributs DBF décodés (cp1252 par
 * défaut, cf. le fichier .cpg quand il existe).
 *
 * Types gérés : NullShape, Point (XY/Z/M) et les Polygon/Polyline
 * (+Z, +M) — les polygones ZNIEFF/Natura sont des Polygon ou PolygonZ en
 * Lambert-93. Les valeurs Z/M sont ignorées (affichage 2D).
 */
class ShapefileReader
{
    private const TYPE_NULL = 0;

    private const TYPE_POINT = 1;

    private const TYPE_POLYLINE = 3;

    private const TYPE_POLYGON = 5;

    private const TYPE_POINTZ = 11;

    private const TYPE_POLYLINEZ = 13;

    private const TYPE_POLYGONZ = 15;

    private const TYPE_POINTM = 18;

    private const TYPE_POLYLINEM = 23;

    private const TYPE_POLYGONM = 25;

    /** @var resource */
    private $shp;

    private int $shapeType = self::TYPE_NULL;

    /** @var array{minx: float, miny: float, maxx: float, maxy: float} */
    private array $bbox = ['minx' => 0.0, 'miny' => 0.0, 'maxx' => 0.0, 'maxy' => 0.0];

    /** @var resource|null */
    private $dbf = null;

    private int $dbfRecordCount = 0;

    private int $dbfHeaderLength = 0;

    private int $dbfRecordLength = 0;

    /** @var array<int, array{name: string, type: string, length: int}> */
    private array $dbfFields = [];

    private bool $utf8 = false;

    public function __construct(string $shpPath)
    {
        if (! is_file($shpPath)) {
            throw new RuntimeException("Fichier .shp introuvable : {$shpPath}");
        }

        $this->shp = fopen($shpPath, 'rb');
        if ($this->shp === false) {
            throw new RuntimeException("Impossible d'ouvrir le .shp : {$shpPath}");
        }
        $this->readShpHeader();

        $dbfPath = substr($shpPath, 0, -3).'dbf';
        if (! is_file($dbfPath)) {
            throw new RuntimeException("Fichier .dbf introuvable : {$dbfPath}");
        }
        $this->dbf = fopen($dbfPath, 'rb');
        if ($this->dbf === false) {
            throw new RuntimeException("Impossible d'ouvrir le .dbf : {$dbfPath}");
        }
        $this->readDbfHeader();

        $cpgPath = substr($shpPath, 0, -3).'cpg';
        if (is_file($cpgPath)) {
            $cpg = strtoupper(trim((string) file_get_contents($cpgPath)));
            $this->utf8 = str_contains($cpg, 'UTF') || str_contains($cpg, '65001');
        }
    }

    public function __destruct()
    {
        if (is_resource($this->shp)) {
            fclose($this->shp);
        }
        if (is_resource($this->dbf)) {
            fclose($this->dbf);
        }
    }

    /**
     * Nombre d'enregistrements (header DBF).
     */
    public function count(): int
    {
        return $this->dbfRecordCount;
    }

    public function shapeType(): int
    {
        return $this->shapeType;
    }

    /**
     * Bbox du fichier (header SHP) — permet de valider la projection
     * (Lambert-93 : valeurs métriques ~ 10^5–10^7 ; WGS84 : ≤ 180).
     *
     * @return array{minx: float, miny: float, maxx: float, maxy: float}
     */
    public function bbox(): array
    {
        return $this->bbox;
    }

    /**
     * Parcourt les enregistrements : attributs DBF + géométrie WKT.
     *
     * @return \Generator<int, array{attrs: array<string, string>, wkt: string|null}>
     */
    public function records(): \Generator
    {
        fseek($this->shp, 100);
        $index = 0;

        while (! feof($this->shp)) {
            $header = fread($this->shp, 8);
            if (strlen($header) < 8) {
                break;
            }
            $words = unpack('N1length', substr($header, 4, 4));
            if ($words === false || $words['length'] === 0) {
                continue;
            }
            $content = fread($this->shp, $words['length'] * 2);
            if ($content === false || strlen($content) !== $words['length'] * 2) {
                throw new RuntimeException("Enregistrement SHP tronqué (n° {$index}).");
            }

            $type = unpack('V1type', $content);
            $wkt = $type === false ? null : $this->toWkt($type['type'], $content);

            yield ['attrs' => $this->dbfAttributes($index), 'wkt' => $wkt];
            $index++;
        }
    }

    /**
     * @param  string  $content  binaire brut de l'enregistrement .shp
     */
    private function toWkt(int $type, string $content): ?string
    {
        if ($type === self::TYPE_NULL) {
            return null;
        }

        if (in_array($type, [self::TYPE_POINT, self::TYPE_POINTZ, self::TYPE_POINTM], true)) {
            $xy = unpack('d2', substr($content, 4, 16));
            if ($xy === false) {
                return null;
            }

            return sprintf('POINT(%s)', $this->fmtPair($xy[1], $xy[2]));
        }

        if (! in_array($type, [
            self::TYPE_POLYLINE, self::TYPE_POLYLINEZ, self::TYPE_POLYLINEM,
            self::TYPE_POLYGON, self::TYPE_POLYGONZ, self::TYPE_POLYGONM,
        ], true)) {
            throw new RuntimeException("Type de géométrie SHP non géré : {$type}");
        }

        // Contenu d'un enregistrement polyline/polygon ESRI :
        // [0..3] type · [4..35] bbox · [36..39] numParts · [40..43] numPoints
        // [44...] index de parts · puis les couples XY.
        $numParts = unpack('V1', substr($content, 36, 4));
        $numPoints = unpack('V1', substr($content, 40, 4));
        if ($numParts === false || $numPoints === false) {
            return null;
        }
        $numParts = (int) $numParts[1];
        $numPoints = (int) $numPoints[1];
        if ($numParts < 1 || $numPoints < 1) {
            return null;
        }

        $parts = [];
        for ($i = 0; $i < $numParts; $i++) {
            $v = unpack('V1', substr($content, 44 + 4 * $i, 4));
            $parts[] = $v === false ? 0 : (int) $v[1];
        }
        $parts[] = $numPoints; // borne finale

        $pointsOffset = 44 + 4 * $numParts;
        $rings = [];
        for ($p = 0; $p < $numParts; $p++) {
            $start = $parts[$p];
            $end = $parts[$p + 1];
            $ring = [];
            for ($j = $start; $j < $end; $j++) {
                $xy = unpack('d2', substr($content, $pointsOffset + 16 * $j, 16));
                if ($xy !== false) {
                    $ring[] = [$xy[1], $xy[2]];
                }
            }
            // Un anneau ESRI est fermé par construction ; garde-fou.
            if (count($ring) >= 3) {
                $first = $ring[0];
                $last = $ring[count($ring) - 1];
                if ($first[0] !== $last[0] || $first[1] !== $last[1]) {
                    $ring[] = $first;
                }
            }
            if (count($ring) >= 4) {
                $rings[] = $ring;
            }
        }
        if ($rings === []) {
            return null;
        }

        if (in_array($type, [self::TYPE_POLYLINE, self::TYPE_POLYLINEZ, self::TYPE_POLYLINEM], true)) {
            return sprintf('MULTILINESTRING(%s)', implode(',', array_map(
                fn (array $ring): string => sprintf('(%s)', implode(',', array_map(
                    fn (array $pt): string => $this->fmtPair($pt[0], $pt[1]),
                    $ring,
                ))),
                $rings,
            )));
        }

        // Polygones ESRI : extérieurs en sens horaire (aire signée négative
        // au lacet),interne en sens trigonométrique. On regroupe chaque
        // anneau interne avec son extérieur courant.
        $polygons = [];
        foreach ($rings as $ring) {
            $area = 0.0;
            $n = count($ring);
            for ($j = 0; $j < $n - 1; $j++) {
                $area += $ring[$j][0] * $ring[$j + 1][1] - $ring[$j + 1][0] * $ring[$j][1];
            }
            if ($area < 0) { // extérieur (CW)
                $polygons[] = [$ring];
            } elseif ($polygons !== []) { // interne (CCW)
                $polygons[count($polygons) - 1][] = $ring;
            }
        }
        if ($polygons === []) {
            // Fournisseur non conforme : premier anneau en extérieur.
            $polygons[] = [$rings[0]];
        }

        return sprintf('MULTIPOLYGON(%s)', implode(',', array_map(
            fn (array $ringsOfPolygon): string => sprintf('(%s)', implode(',', array_map(
                fn (array $ring): string => sprintf('(%s)', implode(',', array_map(
                    fn (array $pt): string => $this->fmtPair($pt[0], $pt[1]),
                    $ring,
                ))),
                $ringsOfPolygon,
            ))),
            $polygons,
        )));
    }

    private function fmtPair(float $x, float $y): string
    {
        // Mètres Lambert-93 : 4 décimales = précision sub-millimétrique.
        return sprintf('%.4F %.4F', $x, $y);
    }

    private function readShpHeader(): void
    {
        $header = fread($this->shp, 100);
        if ($header === false || strlen($header) < 100) {
            throw new RuntimeException('En-tête SHP invalide ou tronqué.');
        }
        $fileCode = unpack('N1', substr($header, 0, 4));
        if ($fileCode === false || $fileCode[1] !== 9994) {
            throw new RuntimeException('Ce fichier n\'est pas un shapefile (code 9994 absent).');
        }
        $type = unpack('V1', substr($header, 32, 4));
        if ($type !== false) {
            $this->shapeType = (int) $type[1];
        }
        $box = unpack('d4', substr($header, 36, 32));
        if ($box !== false) {
            $this->bbox = ['minx' => $box[1], 'miny' => $box[2], 'maxx' => $box[3], 'maxy' => $box[4]];
        }
    }

    private function readDbfHeader(): void
    {
        $header = fread($this->dbf, 32);
        if ($header === false || strlen($header) < 32) {
            throw new RuntimeException('En-tête DBF invalide ou tronqué.');
        }
        $n = unpack('V1', substr($header, 4, 4));
        $hlen = unpack('v1', substr($header, 8, 2));
        $rlen = unpack('v1', substr($header, 10, 2));
        if ($n === false || $hlen === false || $rlen === false) {
            throw new RuntimeException('En-tête DBF illisible.');
        }
        $this->dbfRecordCount = (int) $n[1];
        $this->dbfHeaderLength = (int) $hlen[1];
        $this->dbfRecordLength = (int) $rlen[1];

        $pos = 32;
        while ($pos < $this->dbfHeaderLength - 1) {
            $field = fread($this->dbf, 32);
            if ($field === false || strlen($field) < 32) {
                break;
            }
            $name = rtrim(substr($field, 0, 11), "\0 ");
            $this->dbfFields[] = [
                'name' => $name,
                'type' => $field[11],
                'length' => ord($field[16]),
            ];
            $pos += 32;
        }
    }

    /**
     * Attributs DBF de l'enregistrement $index (alignés sur l'ordre SHP).
     *
     * @return array<string, string>
     */
    private function dbfAttributes(int $index): array
    {
        $attrs = [];
        if ($this->dbf !== null && $index < $this->dbfRecordCount) {
            fseek($this->dbf, $this->dbfHeaderLength + $index * $this->dbfRecordLength);
            $raw = fread($this->dbf, $this->dbfRecordLength);
            if ($raw !== false && strlen($raw) === $this->dbfRecordLength) {
                $pos = 1; // octet 0 : marqueur de suppression
                foreach ($this->dbfFields as $field) {
                    $value = substr($raw, $pos, $field['length']);
                    $pos += $field['length'];
                    $value = trim($value);
                    if (! $this->utf8 && $value !== '' && ! mb_check_encoding($value, 'UTF-8')) {
                        $value = mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
                    }
                    $attrs[$field['name']] = $value;
                }
            }
        }

        return $attrs;
    }
}
