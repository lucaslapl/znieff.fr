<?php

namespace App\Http\Controllers;

use App\Models\AireProtegee;
use App\Support\Chapeaux;
use App\Support\Croisements;
use Inertia\Inertia;
use Inertia\Response;

class AireProtegeeController extends Controller
{
    /**
     * Fiche d'une aire protégée (APPB, RNR, RNN… — zonage juridique, à
     * la différence d'une ZNIEFF qui est un inventaire scientifique).
     */
    public function show(string $id): Response
    {
        $id = strtoupper($id);
        $aire = AireProtegee::query()->findOrFail($id);

        $communes = Croisements::communesAire($aire->id);

        $chapeau = Chapeaux::aire([
            'id' => $aire->id,
            'type_nom' => $aire->type_nom,
            'nom' => $aire->nom,
            'superficie_ha' => $aire->superficie_ha,
            'superficie_ref_ha' => $aire->superficie_ref_ha,
            'acte' => $aire->acte,
            'gestionnaire' => $aire->gestionnaire,
            'date_crea_sign' => $aire->date_crea_sign?->toDateString(),
            'date_modif_geo' => $aire->date_modif_geo?->toDateString(),
        ], $communes);

        $url = route('ap.show', $aire->id);

        return Inertia::render('Aires/Show', [
            'meta' => ['title' => sprintf('« %s » — %s %s', $aire->nom, (string) ($aire->type_nom ?? 'Aire protégée'), $aire->id), 'description' => Chapeaux::meta($chapeau), 'canonical' => $url],
            'aire' => [
                'id' => $aire->id,
                'nom' => $aire->nom,
                'type_code' => $aire->type_code,
                'type_nom' => $aire->type_nom,
                'superficie_ha' => Chapeaux::ha($aire->superficie_ha),
                'superficie_ref_ha' => Chapeaux::ha($aire->superficie_ref_ha),
                'acte' => $aire->acte,
                'gestionnaire' => $aire->gestionnaire,
                'doc_gestion' => (bool) $aire->doc_gestion,
                'date_crea_sign' => Chapeaux::date($aire->date_crea_sign?->toDateString()),
                'date_modif_geo' => Chapeaux::date($aire->date_modif_geo?->toDateString()),
                'url_inpn' => $aire->url_inpn,
                'geojson' => '/geo/ap/'.$aire->id.'.geojson',
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
