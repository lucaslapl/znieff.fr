<?php

namespace App\Http\Controllers;

use App\Support\Chapeaux;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Données & méthodologie (itération e) : sources, versions des
 * référentiels (table referentiel_imports, affichées telles quelles),
 * ce que le portail ne fait pas (jamais d'indicateur composite,
 * aucune localisation d'espèce, aucune géométrie en production) et
 * conditions de réutilisation INPN — jamais LO 2.0 pour ces données.
 *
 * Les versions datées viennent des props partagées site.referentiels
 * (HandleInertiaRequests) : une seule source de vérité, la même que
 * le pied de page.
 */
class DonneesController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Donnees/Index', [
            'meta' => [
                'title' => 'Données & méthodologie',
                'description' => Chapeaux::meta(
                    'Sources et versions des référentiels du Portail ZNIEFF : INPN/MNHN, INSEE, IGN. Faits bruts, aucun indicateur composite, conditions de réutilisation INPN affichées.',
                ),
                'canonical' => route('donnees.index'),
            ],
        ]);
    }
}
