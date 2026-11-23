<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Client de l'API de l'Observatoire des ABC (même équipe) : liste des
 * communes couvertes par un ABC, pour le badge « ABC » des fiches
 * communes. Interrogé UNE fois par an au rafraîchissement des
 * référentiels — une seule requête, jamais de rafale (l'hébergeur de
 * l'Observatoire bloque les IPs en rafale : cf. AGENTS.md de l'ABC).
 */
class AbcClient
{
    /**
     * Codes INSEE des communes ayant un ABC, ou null si indisponible
     * (le portail continue alors sans badges, has_abc = false).
     *
     * @return array<int, string>|null
     */
    public function communesAvecAbc(): ?array
    {
        if (! config('portail.abc_api.enabled')) {
            return null;
        }

        $reponse = Http::timeout(120)
            ->connectTimeout(30)
            ->acceptJson()
            ->get((string) config('portail.abc_api.geojson'));

        if ($reponse->failed()) {
            Log::warning('ABC API indisponible (badges ABC ignorés).', [
                'status' => $reponse->status(),
            ]);

            return null;
        }

        // Structure GeoJsonService de l'Observatoire : une feature par
        // commune (ou par couple ABC–commune), le code INSEE dans les
        // propriétés sous « code_commune » (tolérant au nom).
        $codes = [];
        foreach ($reponse->json('features', []) as $feature) {
            $props = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
            $code = $this->code($props);
            if ($code !== null) {
                $codes[] = $code;
            }
        }

        return array_values(array_unique($codes));
    }

    /** Lien fiche communes de l'Observatoire des ABC. */
    public function lienCommune(string $insee): string
    {
        return rtrim((string) config('portail.urls.abc_commune'), '/').'/'.$insee;
    }

    /**
     * @param  array<string, mixed>  $props
     */
    private function code(array $props): ?string
    {
        foreach (['code_commune', 'insee', 'code_insee', 'code', 'insee_com'] as $cle) {
            $v = strtoupper(trim((string) ($props[$cle] ?? '')));
            if (preg_match('/^([0-9]{2}|2A|2B)[0-9]{3}$/', $v)) {
                return $v;
            }
        }

        return null;
    }
}
