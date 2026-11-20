<?php

namespace App\Http\Controllers;

use App\Models\AireProtegee;
use App\Models\Commune;
use App\Models\NaturaSite;
use App\Models\Zonage;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Accueil (itération d) : la searchbar mène à la recherche transverse,
 * le comptoir « Par département » expose les 102 départements du COG.
 *
 * Les compteurs du Stat-grid sont des faits bruts de la base (au
 * périmètre du référentiel importé — cf. versions en pied de page),
 * jamais des indicateurs composites. Cache 1 heure : la page ne
 * compte les tables qu'une fois par heure sur l'hébergement mutualisé.
 */
class HomeController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Home', [
            'pageTitre' => 'Portail ZNIEFF — le patrimoine naturel des communes de France',
            'departements' => config('portail.departements'),
            'stats' => $this->stats(),
        ]);
    }

    /**
     * Compteurs du référentiel courant (cache 1 h, comptage annulé si
     * la base n'est pas encore installée).
     *
     * @return array{communes: int, znieff: int, natura: int, aires: int, types_aires: int}
     */
    private function stats(): array
    {
        try {
            return Cache::remember('portail:accueil:stats', 3600, function (): array {
                return [
                    'communes' => Commune::query()->count(),
                    'znieff' => Zonage::query()->count(),
                    'natura' => NaturaSite::query()->count(),
                    'aires' => AireProtegee::query()->count(),
                    'types_aires' => AireProtegee::query()->distinct()->count('type_code'),
                ];
            });
        } catch (\Throwable) {
            return ['communes' => 0, 'znieff' => 0, 'natura' => 0, 'aires' => 0, 'types_aires' => 0];
        }
    }
}
