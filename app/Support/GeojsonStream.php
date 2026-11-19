<?php

namespace App\Support;

use RuntimeException;

/**
 * Parcourt un GeoJSON FeatureCollection en flux : le fichier Admin Express
 * (~43 Mo) ne tient pas en mémoire une fois décodé. On capture chaque objet
 * enfant direct de la racine et on ne rend que ceux de type Feature.
 *
 * Repris de l'Observatoire des ABC (InpnImportService::geojsonFeatures),
 * même équipe, même licence AGPL-3.0.
 */
class GeojsonStream
{
    /**
     * @return \Generator<int, array{properties: array<string, mixed>, geometry: array<string, mixed>|string}>
     */
    public static function features(string $file): \Generator
    {
        $handle = fopen($file, 'r');
        if ($handle === false) {
            throw new RuntimeException("Impossible de lire le GeoJSON : {$file}");
        }

        $depth = 0;
        $inString = false;
        $escaped = false;
        $obj = null;
        while (($char = fgetc($handle)) !== false) {
            if ($inString) {
                if ($obj !== null) {
                    $obj .= $char;
                }
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;
                if ($obj !== null) {
                    $obj .= $char;
                }

                continue;
            }

            if ($char === '{') {
                $depth++;
                if ($depth === 2) {
                    $obj = '{'; // enfant direct de la racine
                } elseif ($obj !== null) {
                    $obj .= $char;
                }

                continue;
            }

            if ($char === '}' && $depth > 0) {
                $depth--;
                if ($depth === 1 && $obj !== null) {
                    $obj .= '}'; // accolade fermante de l'objet capturé
                    $feature = json_decode($obj, true);
                    if (is_array($feature) && ($feature['type'] ?? '') === 'Feature') {
                        $properties = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
                        yield [
                            'properties' => $properties,
                            'geometry' => $feature['geometry'] ?? '',
                        ];
                    }
                    $obj = null;

                    continue;
                }
                if ($obj !== null) {
                    $obj .= $char;
                }
                if ($depth === 0) {
                    break; // fin du FeatureCollection
                }

                continue;
            }

            if ($obj !== null) {
                $obj .= $char;
            }
        }
        fclose($handle);
    }
}
