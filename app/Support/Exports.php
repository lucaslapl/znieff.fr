<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exports des listes filtrées (itération d) : CSV et GeoJSON, en flux
 * (streamDownload) — des dizaines de milliers de lignes sans charger
 * la mémoire du serveur mutualisé.
 *
 * Conditions de réutilisation INPN rappelées dans les interfaces
 * (jamais LO 2.0 pour ces données, attribution MNHN) ; le contenu
 * n'est que des faits bruts du référentiel, aucun indicateur.
 *
 * CSV : séparateur point-virgule (usage français, INSEE/INPN), BOM
 * UTF-8 (Excel), dates ISO, nombres bruts à virgule décimale. Chaque
 * export porte l'URL de la fiche correspondante du portail.
 *
 * GeoJSON : FeatureCollection dont la géométrie de chaque objet vient
 * du fichier précalculé de l'import (public/geo — jamais recalculée).
 * Les propriétés sont les mêmes colonnes que le CSV.
 */
final class Exports
{
    /**
     * CSV en flux. $lignes : itérateur de tableaux associatifs indexés
     * dans l'ordre de $entetes.
     *
     * @param  array<int, string>  $entetes
     * @param  iterable<int, array<int, string|null>>  $lignes
     */
    public static function csv(string $nomFichier, array $entetes, iterable $lignes): StreamedResponse
    {
        return response()->streamDownload(
            function () use ($entetes, $lignes): void {
                $sortie = fopen('php://output', 'w');
                if ($sortie === false) {
                    return;
                }
                fwrite($sortie, "\xEF\xBB\xBF"); // BOM UTF-8 (Excel)
                fputcsv($sortie, $entetes, ';');
                foreach ($lignes as $ligne) {
                    fputcsv($sortie, $ligne, ';');
                }
                fclose($sortie);
            },
            $nomFichier,
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }

    /**
     * GeoJSON FeatureCollection en flux. $features : itérateur de
     * Features GeoJSON complets (tableaux PHP : type, properties,
     * geometry — geometry à null si le précalcul manque, jamais
     * inventée).
     *
     * @param  iterable<int, array{type: string, properties: array<string, mixed>, geometry: array<string, mixed>|null}>  $features
     */
    public static function geojson(string $nomFichier, iterable $features): StreamedResponse
    {
        return response()->streamDownload(
            function () use ($features): void {
                echo '{"type":"FeatureCollection","name":"portail-znieff","features":[';
                $premier = true;
                foreach ($features as $feature) {
                    echo ($premier ? '' : ',').json_encode($feature, JSON_UNESCAPED_UNICODE);
                    $premier = false;
                }
                echo ']}';
            },
            $nomFichier,
            ['Content-Type' => 'application/geo+json; charset=UTF-8'],
        );
    }

    /**
     * Nome le fichier téléchargé selon le périmètre (département ou
     * « france ») et la date du jour : cache-transparent, collisions
     * de fichiers locales évitées.
     */
    public static function nomFichier(string $prefixe, string $suffixe, ?string $dept): string
    {
        $perimetre = $dept !== null ? $dept : 'france';

        return sprintf('%s-%s-%s.%s', $prefixe, $perimetre, date('Y-m-d'), $suffixe);
    }

    /**
     * Géométrie précalculée d'un objet, lue depuis public/geo (jamais
     * recalculée ni stockée en base). Fichier absent → null, la
     * propriété geojson absent le dit honnêtement.
     *
     * @return array<string, mixed>|null
     */
    public static function geometry(string $sousDossier, string $id): ?array
    {
        $fichier = config('portail.geojson.dir').'/'.$sousDossier.'/'.$id.'.geojson';
        if (! is_file($fichier)) {
            return null;
        }

        $feature = json_decode((string) file_get_contents($fichier), true);
        if (! is_array($feature) || ! isset($feature['geometry']) || ! is_array($feature['geometry'])) {
            return null;
        }

        return $feature['geometry'];
    }

    /**
     * Nombre à virgule décimale française, sans espace de milliers
     * (rechargement propre dans un tableur).
     */
    public static function nombre(?float $v, int $decimales = 2): string
    {
        return number_format((float) $v, $decimales, ',', '');
    }
}
