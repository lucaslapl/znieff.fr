<?php

use App\Http\Controllers\AireProtegeeController;
use App\Http\Controllers\CarteController;
use App\Http\Controllers\CommuneController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\NaturaSiteController;
use App\Http\Controllers\RechercheController;
use App\Http\Controllers\ZonageController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Routes publiques du Portail ZNIEFF
|--------------------------------------------------------------------------
| Itération (a) : accueil, migrations, pipeline d'import (artisan).
| Itération (b) : fiches communes et zonages (ZNIEFF, Natura 2000,
| aires protégées) — indexables, canonical par fiche, ancien numéro
| régional ZNIEFF redirigé en 301 vers l'identifiant national.
| Itération (c) : carte interactive (surcouches précalculées /geo).
| Itération (d) : listes paginées (25/page), recherche transverse,
| exports CSV/GeoJSON des listes filtrées.
*/

Route::get('/', [HomeController::class, 'index'])->name('home');

/*
|--------------------------------------------------------------------------
| Listes et recherche (itération d) — pagination serveur, filtres
| simples (dept, type, recherche), exports CSV/GeoJSON de la liste
| filtrée (?export=csv|geojson, sous conditions INPN, jamais LO 2.0).
|--------------------------------------------------------------------------
*/

Route::get('/communes', [CommuneController::class, 'index'])->name('communes.index');
Route::get('/znieff', [ZonageController::class, 'index'])->name('znieff.index');
Route::get('/natura', [NaturaSiteController::class, 'index'])->name('natura.index');
Route::get('/ap', [AireProtegeeController::class, 'index'])->name('ap.index');

// Recherche transverse (searchbar de l'accueil) : redirection vers la
// fiche en cas de correspondance exacte, sinon résultats par section.
Route::get('/recherche', [RechercheController::class, 'index'])->name('recherche.index');

/*
|--------------------------------------------------------------------------
| Carte interactive (itération c) — surcouches précalculées par dept
|--------------------------------------------------------------------------
*/

Route::get('/carte', [CarteController::class, 'index'])->name('carte.index');

// Cadrage sur un zonage ou une commune (fitBounds + popup ouverte).
Route::get('/carte/znieff/{id}', [CarteController::class, 'znieff'])
    ->where('id', '[0-9]{4,12}')
    ->name('carte.znieff');

Route::get('/carte/commune/{slug}', [CarteController::class, 'commune'])
    ->where('slug', '[a-z0-9-]+')
    ->name('carte.commune');

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
