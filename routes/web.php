<?php

use App\Http\Controllers\AireProtegeeController;
use App\Http\Controllers\CommuneController;
use App\Http\Controllers\NaturaSiteController;
use App\Http\Controllers\ZonageController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Routes publiques du Portail ZNIEFF
|--------------------------------------------------------------------------
| Itération (a) : accueil + squelettes des sections (pages « en
| construction », noindex, hors sitemap) pour que la navigation et les
| gabarits de la charte soient en place dès le socle. Les vraies listes et
| fiches arrivent aux itérations (b)-(e).
|
| Itération (b) : fiches communes et zonages (ZNIEFF, Natura 2000, aires
| protégées) — tout indexable, canonical par fiche, ancien numéro
| régional ZNIEFF redirigé en 301 vers l'identifiant national.
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

/*
|--------------------------------------------------------------------------
| Fiches (itération b) — indexables, canonical par fiche
|--------------------------------------------------------------------------
*/

// -- Commune : /commune/{slug} — toute commune a une fiche, même sans zonage.
Route::get('/commune/{slug}', [CommuneController::class, 'show'])
    ->where('slug', '[a-z0-9-]+')
    ->name('commune.show');

// -- ZNIEFF : l'ancien numéro régional d'abord (301 vers l'identifiant national).
Route::get('/znieff/num/{num}', [ZonageController::class, 'numero'])
    ->where('num', '[0-9]{4,12}')
    ->name('znieff.num');

Route::get('/znieff/{id}', [ZonageController::class, 'showOuNumero'])
    ->where('id', '[0-9]{4,12}')
    ->name('znieff.show');

// -- Natura 2000 : /natura/{code} (code européen FR…).
Route::get('/natura/{code}', [NaturaSiteController::class, 'show'])
    ->where('code', '(?i)fr[0-9a-z]{6,10}')
    ->name('natura.show');

// -- Aire protégée : /ap/{id}.
Route::get('/ap/{id}', [AireProtegeeController::class, 'show'])
    ->where('id', '(?i)fr[0-9a-z]{6,12}')
    ->name('ap.show');
