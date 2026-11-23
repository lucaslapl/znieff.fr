<?php

use App\Http\Controllers\AireProtegeeController;
use App\Http\Controllers\CarteController;
use App\Http\Controllers\CommuneController;
use App\Http\Controllers\DonneesController;
use App\Http\Controllers\EspeceController;
use App\Http\Controllers\GeologieController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\NaturaSiteController;
use App\Http\Controllers\RechercheController;
use App\Http\Controllers\SitemapController;
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
| Itération (e) : Données & méthodologie, sitemap exhaustif
| (index + lots de 10 000 URLs par famille), robots.txt.
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
Route::get('/geologie', [GeologieController::class, 'index'])->name('geologie.index');

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
// Les identifiants INPN des ZNIEFF peuvent contenir des lettres
// (ex. 03M000017, ZNIEFF marine) : contrainte alphanumérique.
Route::get('/carte/znieff/{id}', [CarteController::class, 'znieff'])
    ->where('id', '[0-9A-Za-z]{4,12}')
    ->name('carte.znieff');

Route::get('/carte/commune/{slug}', [CarteController::class, 'commune'])
    ->where('slug', '[a-z0-9-]+')
    ->name('carte.commune');

Route::get('/donnees', [DonneesController::class, 'index'])->name('donnees.index');

/*
|--------------------------------------------------------------------------
| SEO (itération e) — sitemap exhaustif (toutes les fiches), robots
|--------------------------------------------------------------------------
*/

Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('sitemap.index');

Route::get('/sitemaps/{famille}-{lot}.xml', [SitemapController::class, 'lot'])
    ->where('famille', '[a-z]+')
    ->where('lot', '[0-9]+')
    ->name('sitemap.lot');

Route::get('/robots.txt', [SitemapController::class, 'robots'])->name('robots');

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
    ->where('id', '[0-9A-Za-z]{4,12}')
    ->name('znieff.show');

// -- Natura 2000 : /natura/{code} (code européen FR…).
//    Les contraintes de route doivent rester compatibles JavaScript :
//    Ziggy les compile côté client et SSR — pas de drapeau PCRE « (?i) »
//    (crash du rendu serveur) ; classes de caractères à la place.
Route::get('/natura/{code}', [NaturaSiteController::class, 'show'])
    ->where('code', '[Ff][Rr][0-9a-zA-Z]{6,10}')
    ->name('natura.show');

// -- Aire protégée : /ap/{id}.
Route::get('/ap/{id}', [AireProtegeeController::class, 'show'])
    ->where('id', '[Ff][Rr][0-9a-zA-Z]{6,12}')
    ->name('ap.show');

// -- Espèce : /espece/{cd_ref} (TAXREF v18 + statuts BDC v18) — uniquement
//    les taxons non sensibles (référentiel SINP v18) et non confidentiels :
//    les autres renvoient 404 (jamais de fiche d'espèce sensible).
Route::get('/espece/{cd_ref}', [EspeceController::class, 'show'])
    ->where('cd_ref', '[0-9]{1,10}')
    ->name('espece.show');

// -- Patrimoine géologique (INPG) : /geologie/{identifiant_metier} (ex. NAQ0004).
//    Un site confidentiel (niv_diffusion = 1) renvoie 404.
Route::get('/geologie/{id}', [GeologieController::class, 'show'])
    ->where('id', '[A-Za-z0-9]{5,10}')
    ->name('geologie.show');
