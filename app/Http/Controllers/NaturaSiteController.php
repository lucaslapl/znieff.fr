<?php

namespace App\Http\Controllers;

use App\Models\NaturaSite;
use App\Support\Chapeaux;
use App\Support\Croisements;
use Inertia\Inertia;
use Inertia\Response;

class NaturaSiteController extends Controller
{
    /**
     * Fiche d'un site Natura 2000 (SIC/ZSC ou ZPS) — code européen FR.
     * Espèces et habitats d'intérêt communautaire : renvoi INPN, jamais
     * rediffusés.
     */
    public function show(string $code): Response
    {
        $code = strtoupper($code);
        $site = NaturaSite::query()->findOrFail($code);

        $communes = Croisements::communesNatura($site->code);

        $chapeau = Chapeaux::natura([
            'code' => $site->code,
            'type' => $site->type,
            'nom' => $site->nom,
            'superficie_ha' => $site->superficie_ha,
            'superficie_ref_ha' => $site->superficie_ref_ha,
            'date_designation' => $site->date_designation?->toDateString(),
            'date_modif_geo' => $site->date_modif_geo?->toDateString(),
        ], $communes);

        $url = route('natura.show', $site->code);

        return Inertia::render('Natura/Show', [
            'meta' => ['title' => sprintf('%s « %s » — Natura 2000 %s', strtoupper((string) $site->type), $site->nom, $site->code), 'description' => Chapeaux::meta($chapeau), 'canonical' => $url],
            'site' => [
                'code' => $site->code,
                'nom' => $site->nom,
                'type' => strtoupper((string) $site->type),
                'superficie_ha' => Chapeaux::ha($site->superficie_ha),
                'superficie_ref_ha' => Chapeaux::ha($site->superficie_ref_ha),
                'precision_saisie' => $site->precision_saisie,
                'date_designation' => Chapeaux::date($site->date_designation?->toDateString()),
                'date_modif_geo' => Chapeaux::date($site->date_modif_geo?->toDateString()),
                'url_inpn' => $site->url_inpn,
                'geojson' => '/geo/natura/'.$site->code.'.geojson',
            ],
            'chapeau' => $chapeau,
            'communes' => array_map(fn (array $c) => [
                'slug' => $c['slug'],
                'nom' => $c['nom'],
                'dept' => $c['dept'],
                'surface_ha' => Chapeaux::ha($c['surface_ha']),
                'part_zonage' => Chapeaux::pct($c['part_zonage']),
                'part_commune' => Chapeaux::pct($c['part_commune']),
            ], $communes),
            'nbCommunes' => count($communes),
        ]);
    }
}
