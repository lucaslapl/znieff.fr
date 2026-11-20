<?php

namespace App\Http\Controllers;

use App\Models\Commune;
use App\Models\Zonage;
use App\Support\Croisements;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CarteController extends Controller
{
    /**
     * Carte interactive : surcouches précalculées par département (jamais
     * de calcul en ligne — les fichiers GeoJSON sont générés à l'import).
     * Sans ?dept=, la page propose le sélecteur ; les surcouches se
     * chargent pour le département choisi.
     */
    public function index(Request $request): Response
    {
        $dept = $request->query('dept') !== null ? strtoupper((string) $request->query('dept')) : null;
        if ($dept !== null && ! array_key_exists($dept, $this->departements())) {
            abort(404);
        }

        return Inertia::render('Cartes/Index', $this->props($dept, null, 'Carte interactive du patrimoine naturel'));
    }

    /**
     * Cadrage sur un zonage : /carte/znieff/{id} — le département (et
     * donc les surcouches) est résolu par la commune la plus touchée du
     * croisement précalculé. L'ancien numéro régional est accepté et
     * redirigé en 301 (cohérence avec /znieff/num/{num}).
     */
    public function znieff(string $id): Response|RedirectResponse
    {
        $zonage = Zonage::query()->find($id);
        if ($zonage === null || ($zonage->famille !== 'z1' && $zonage->famille !== 'z2')) {
            // Repli : numéro régional saisi comme identifiant.
            $parNumero = Zonage::query()->where('num_regional', $id)->first(['id']);
            if ($parNumero === null) {
                abort(404);
            }

            return redirect()->to(route('carte.znieff', $parNumero->id), 301);
        }
        $communes = Croisements::communesZonage($zonage->id);
        if ($communes === []) {
            abort(404, 'Aucun croisement communal pour ce zonage.');
        }
        $dept = (string) $communes[0]['dept'];

        $props = $this->props(
            $dept,
            ['famille' => $zonage->famille, 'cle' => $zonage->id],
            sprintf('Carte de la ZNIEFF « %s » (%s)', $zonage->nom, $zonage->id),
            route('carte.znieff', $zonage->id)
        );

        return Inertia::render('Cartes/Index', $props);
    }

    /**
     * Cadrage sur une commune : /carte/commune/{slug}.
     */
    public function commune(string $slug): Response
    {
        $commune = Commune::query()->where('slug', $slug)->firstOrFail();

        $props = $this->props(
            $commune->dept,
            ['famille' => 'commune', 'cle' => $commune->slug],
            sprintf('Carte du patrimoine naturel de %s', $commune->nom),
            route('carte.commune', $commune->slug)
        );

        return Inertia::render('Cartes/Index', $props);
    }

    // ------------------------------------------------------------------

    /**
     * « 104 départements » du référentiel COG (config portail).
     *
     * @return array<string, string>
     */
    private function departements(): array
    {
        return array_change_key_case((array) config('portail.departements'), CASE_UPPER);
    }

    /**
     * @param  array{famille: string, cle: string}|null  $focus
     * @return array<string, mixed>
     */
    private function props(?string $dept, ?array $focus, string $titre, ?string $canonical = null): array
    {
        return [
            'meta' => [
                'title' => $titre,
                'description' => 'Carte interactive du patrimoine naturel officiel : ZNIEFF I et II, sites Natura 2000, aires protégées et contours communaux (surcouches INPN précalculées, fonds OpenStreetMap ou Plan IGN v2, sans clé).',
                'canonical' => $canonical ?? ($dept === null ? route('carte.index') : route('carte.index', ['dept' => $dept])),
            ],
            'dept' => $dept,
            'deptNom' => $dept !== null ? ($this->departements()[$dept] ?? null) : null,
            'departements' => $this->departements(),
            'focus' => $focus,
        ];
    }
}
