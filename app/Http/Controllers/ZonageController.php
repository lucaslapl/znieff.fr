<?php

namespace App\Http\Controllers;

use App\Models\Zonage;
use App\Support\Chapeaux;
use App\Support\Croisements;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ZonageController extends Controller
{
    /**
     * Fiche d'une ZNIEFF (type I ou II) par identifiant national INPN.
     * Superficie affichée = aire géométrique Lambert-93 (cohérente avec
     * les parts), référence INPN conservée — écart ≈ ± 1 % documenté.
     */
    public function show(string $id): Response
    {
        $zonage = Zonage::query()->findOrFail($id);
        if ($zonage->famille !== 'z1' && $zonage->famille !== 'z2') {
            abort(404);
        }

        $communes = Croisements::communesZonage($zonage->id);
        /** @var Zonage|null $parent */
        $parent = $zonage->parent()->first(['id', 'nom', 'famille']);
        /** @var Collection<int, Zonage> $enfants */
        $enfants = $zonage->enfants()->orderBy('nom')->get(['id', 'nom', 'famille']);

        $chapeau = Chapeaux::znieff([
            'id' => $zonage->id,
            'famille' => $zonage->famille,
            'nom' => $zonage->nom,
            'superficie_ha' => $zonage->superficie_ha,
            'superficie_ref_ha' => $zonage->superficie_ref_ha,
            'date_crea' => $zonage->date_crea?->toDateString(),
            'date_modif_geo' => $zonage->date_modif_geo?->toDateString(),
        ], $communes);

        $url = route('znieff.show', $zonage->id);

        return Inertia::render('Zonages/Show', [
            'meta' => ['title' => sprintf('« %s » — ZNIEFF %s n° %s', $zonage->nom, $zonage->famille === 'z1' ? 'I' : 'II', $zonage->id), 'description' => Chapeaux::meta($chapeau), 'canonical' => $url],
            'zonage' => [
                'id' => $zonage->id,
                'nom' => $zonage->nom,
                'famille' => $zonage->famille,
                'badge' => $zonage->badgeClasse(),
                'num_regional' => $zonage->num_regional,
                'superficie_ha' => Chapeaux::ha($zonage->superficie_ha),
                'superficie_ref_ha' => Chapeaux::ha($zonage->superficie_ref_ha),
                'precision_saisie' => $zonage->precision_saisie,
                'date_crea' => Chapeaux::date($zonage->date_crea?->toDateString()),
                'date_modif_geo' => Chapeaux::date($zonage->date_modif_geo?->toDateString()),
                'url_inpn' => $zonage->url_inpn,
                'geojson' => '/geo/zonages/'.$zonage->famille.'/'.$zonage->id.'.geojson',
            ],
            'chapeau' => $chapeau,
            'parent' => $parent !== null ? [
                'id' => $parent->id,
                'nom' => $parent->nom,
                'badge' => $parent->badgeClasse(),
            ] : null,
            'enfants' => $enfants->map(fn (Zonage $z) => [
                'id' => $z->id,
                'nom' => $z->nom,
                'badge' => $z->badgeClasse(),
            ])->all(),
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

    /**
     * Ancien numéro régional (NM_REGZN, ex. 8526) : redirection
     * permanente vers la fiche canonique par identifiant national.
     */
    public function numero(string $num): RedirectResponse
    {
        $zonage = Zonage::query()->where('num_regional', $num)->first(['id']);
        if ($zonage === null) {
            abort(404);
        }

        return redirect()->to(route('znieff.show', $zonage->id), 301);
    }

    /**
     * Repli : /znieff/{numéro régional saisi comme id} → redirection.
     */
    public function showOuNumero(string $id): Response|RedirectResponse
    {
        $zonage = Zonage::query()->find($id);
        if ($zonage !== null) {
            return $this->show($id);
        }

        return $this->numero($id);
    }
}
