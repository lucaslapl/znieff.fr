<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Routes publiques du Portail ZNIEFF
|--------------------------------------------------------------------------
| Itération (a) : accueil + squelettes des sections (pages « en
| construction », noindex, hors sitemap) pour que la navigation et les
| gabarits de la charte soient en place dès le socle. Les vraies listes et
| fiches arrivent aux itérations (b)-(e).
*/

Route::inertia('/', 'Home', ['pageTitre' => 'Portail ZNIEFF — le patrimoine naturel des communes de France'])
    ->name('home');

Route::inertia('/communes', 'EnConstruction', [
    'titre' => 'Communes',
    'description' => 'Recherche par nom, code INSEE ou code postal. Chaque commune de France a une fiche.',
])->name('communes.index');

Route::inertia('/znieff', 'EnConstruction', [
    'titre' => 'Zonages ZNIEFF',
    'description' => 'Recherche par identifiant, par nom, par commune ou par département.',
])->name('znieff.index');

Route::inertia('/natura', 'EnConstruction', [
    'titre' => 'Sites Natura 2000',
    'description' => 'Sites d\'importance communautaire et zones de protection spéciale.',
])->name('natura.index');

Route::inertia('/ap', 'EnConstruction', [
    'titre' => 'Aires protégées',
    'description' => 'Réserve naturelle, arrêté de protection de biotope, réserve biologique…',
])->name('ap.index');

Route::inertia('/carte', 'EnConstruction', [
    'titre' => 'Carte interactive',
    'description' => 'Fond OpenStreetMap ou Plan IGN v2, surcouches INPN précalculées.',
])->name('carte.index');

Route::inertia('/donnees', 'EnConstruction', [
    'titre' => 'Données & méthodologie',
    'description' => 'Sources, versions des référentiels, conditions de réutilisation.',
])->name('donnees.index');
