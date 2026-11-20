# AGENTS.md — Portail ZNIEFF

## Qu'est-ce que ce projet

Portail public du patrimoine naturel officiel** réorganisé par commune**, sur toute la
France : ZNIEFF (I et II), Natura 2000, aires protégées. Site public :
<https://znieff.fr> — même équipe et mêmes principes que l'Observatoire des
ABC (<https://observatoire-abc.fr>) : faits bruts sourcés, jamais d'indicateur
composite, sources créditées partout.

Application **Laravel 12 + Inertia 2 / Vue 3 (SSR) + PostGIS en local uniquement**.
La **production n'héberge aucune géométrie et ne fait aucun calcul spatial** :
toutes les intersections sont précalculées en local (conteneur Docker PostGIS)
puis exportées en SQL portable + GeoJSON statiques.

## Contrainte de production (à ne jamais casser)

- Hébergement mutualisé PulseHéberg (Plesk, PHP 8.3, MySQL **sans** PostGIS).
- La base de production ne contient **que des tables attributaires** (`communes`,
  `zonages`, pivots avec `surface_ha`, `part_zonage`, `part_commune`…).
- Les cartes affichent des GeoJSON **précalculés** servis statiquement
  (`public/geo/`, générés par `portail:import-inpn`, jamais calculés en ligne).
- Interdiction absolue de rediffuser les espèces/habitats : renvoi vers l'INPN.
- Données INPN : conditions INPN/MNHN, **jamais LO 2.0**. Code : AGPL-3.0.

## Conventions UI

- **Pagination serveur obligatoire** pour toute longue liste (communes, zonages,
  espèces/habitats renvoyés, aires protégées…) : le serveur renvoie une page
  limitée (paginator Laravel, `preserveScroll`/`only` Inertia pour les
  rechargements), jamais la liste complète rendue côté client. Pas d'infinite
  scroll non borné : l'utilisateur ne doit pas devoir scroller une liste
  interminable (pagination visible par numéros si la liste dépasse ~2 pages).

## Vérifications (économie de temps)

- **Ne jamais relancer toute la suite** pour un changement ciblé : n'exécuter
  que les tests et vérifications des **fichiers touchés**.
  - Test d'un seul contrôleur/service : `php artisan test tests/Feature/<Fiche…>Test.php`
    (encore plus fin : `--filter <méthode>`).
  - Style : `./vendor/bin/pint <fichiers touchés>` (jamais sur tout le dépôt).
  - Analyse : `./vendor/bin/phpstan analyse <fichiers touchés> --no-progress`.
  - La suite complète (`php artisan test` sans filtre + Pint global) ne se
    lance qu'avant une mise en prod ou un commit de bout en bout, et les
    erreurs PHPStan « memory limit » ne sont pas des échecs : relancer avec
    `--memory-limit=1G` si besoin.

## Layout

- `app/Services/InpnImportService.php` — imports ZNIEFF (shapefile), Natura 2000
  (shapefile), aires protégées (GeoPackage, encodage cp1252).
- `app/Services/SpatialService.php` — staging PostGIS, `ST_Transform(…, 2154)`,
  `ST_MakeValid`, croisements et surfaces : **local uniquement**.
- `app/Services/CommunesService.php` — référentiel communal (AdminExpress COG),
  slugs, codes postaux (UNE requête bulk geo.api.gouv.fr), voisines 15 km,
  badge ABC (API de l'Observatoire, une seule requête en cache).
- `app/Services/GeojsonExportService.php` — surcouches GeoJSON précalculées par
  famille et par département (simplifications calibrées : z1 20 m, z2 55 m,
  natura 25 m, AP 5 m, communes 100 m).
- `app/Services/SqlPortableService.php` — dump SQL portable (pilote `mysql` :
  `SET NAMES utf8mb4`, booléens 1/0, `FOREIGN_KEY_CHECKS`, ordre inverse des
  FK pour les `DELETE`, `zonages.id_parent` posée en second passage `UPDATE`).
- `app/Support/ShapefileReader.php` — lecteur shapefile ESRI natif pur PHP
  (pas d'extension), WKT MULTIPOLYGON trous/îlots — testé unitairement.
- `app/Support/GeojsonStream.php` — parcours en flux du GeoJSON AdminExpress
  (43 Mo), repris de l'ABC.
- `config/portail.php` — config métier : répertoires INPN, versions de
  référentiels affichées en pied de page, simplifications, types d'aires
  protégées, URLs INPN, seuil « point » (< 1 ha).
- `resources/js/map/constants.js` — palette/cartes (Plan IGN v2 WMTS libres
  **sans clé**), testés par `tests/js/carte.test.mjs`.
- `database/migrations/` — 9 migrations. Aucune colonne géométrique.

## Stack & versions

- PHP 8.3+ (extensions : `pdo_pgsql`, `pgsql`, `intl`, `zip`, `pdo_sqlite`).
- Laravel 12, Inertia 2, Vue **3.5.43 épinglé** (bug SSR vuejs/core#15769),
  Vite 8, league/csv, tightenco/ziggy, Leaflet 1.9.4.
- **Local** : Docker Compose — `postgis/postgis:16-3.4` (PostGIS 3.4), PHP
  image `docker/php/Dockerfile`, profils `ssr`, `build`, `hmr`.
- **Production** : MySQL mutualisé sans extension spatiale.
- Tests : PHPUnit 12 (`phpunit.xml`, SQLite en mémoire — le pipeline spatial
  n'y tourne jamais). Style : Laravel Pint. Analyse : PHPStan niveau 5
  (Larastan).
- CI GitHub Actions : `.github/workflows/ci.yml` (PHP 8.3 + Node 24 : build
  Vite, PHPUnit, tests JS, Pint, PHPStan).

## Développement local — Docker

```bash
docker compose up -d app          # app + PostGIS : http://localhost:8010
                                  # (8010 hôte → 8000 conteneur ; abc-app,
                                  #  l'Observatoire, occupe déjà 8000)
docker compose --profile build run --rm assets   # build Vite (npm run build)
docker compose --profile hmr up vite              # dev avec HMR (port 5173)
docker compose --profile ssr up -d ssr            # serveur de rendu Inertia (node)
# Activation du SSR (titres/contenu rendus serveur, preuve SEO) :
INERTIA_SSR_ENABLED=true docker compose --profile ssr up -d app ssr
# Retour au rendu client uniquement : recréer le conteneur app sans la
# variable (le .env local garde INERTIA_SSR_ENABLED=false).
docker compose run --rm app php artisan test      # PHPUnit
docker compose logs app                            # suivi entrypoint

# PostGIS : port hôte 5434 (abc-postgis occupe 5433)
psql -h localhost -p 5434 -U znieff -d znieff
```

Les données INPN brutes ne sont **pas** dans le dépôt : bind mount en lecture
seule depuis l'Observatoire (`C:/wamp64/www/abc_scrapper/data/inpn-data` →
`/data/inpn-data` dans le conteneur, voir `docker-compose.yml`).

### Cycle d'import (itération (a) : un département test, ex. Eure 27)

```bash
docker compose exec app php artisan portail:refresh-communes --dept=27 --skip-abc
docker compose exec app php artisan portail:import-inpn --source=all --dept=27
docker compose exec app php artisan portail:check           # fixtures Louviers/Acquigny
docker compose exec app php artisan portail:export-sql     # storage/app/portail/deploy/
```

- `--dept` est **destructif par design** : les tables ne contiennent plus que
  le département.
- L'import est idempotent (upsert + purge du périmètre), journalisé dans
  `referentiel_imports` (version de référentiel datée, affichée en pied de
  page).
- Sans l'option `--dept` : France entière (CAF/DOM inclus, communes hors COG
  filtrées).

## Pièges connus (gains de la vieille équipe)

- **Composer lock** : plate-forme épinglée `php 8.3.36`
  (`config.platform.php`) — l'image `composer:2` tourne en PHP 8.4. Toujours
  régénérer le lock dans l'image app.
- Bind mount Windows : `storage/` doit appartenir à `www-data`
  (l'entrypoint le fait, sinon « tempnam() » en erreur 500).
- Une commande d'import qui échoue en plein vol laisse des tables
  partiellement créées en base PostGIS : reprendre par un
  `DROP SCHEMA public CASCADE; CREATE SCHEMA public;` **puis recréer
  l'extension** `postgis` (le DROP l'emporte aussi).
- GPKG AP : jamais accumuler les géométries en mémoire (sig_metrop.gpkg =
  148 Mo doublé en hex) — déverser par lots de 50.
- FK auto-référente `zonages.id_parent` : jamais dans le gros `INSERT`
  (l'ordre n'est pas garanti) — `NULL` puis `UPDATE`.
- Clés de tableau numériques = **int** en PHP : caster `(string)` avant une
  comparaison stricte avec un identifiant INPN.
- Eloquent ne pluralise pas `ZonageCommune` correctement → `$table` déclarée
  explicitement sur tous les pivots.
- **Ziggy + SSR (itération e)** : les contraintes `where()` des routes sont
  compilées en RegExp **JavaScript** par Ziggy — jamais de drapeau PCRE
  inline `(?i)` (crash silencieux du rendu serveur) : utiliser des classes
  (`[Ff][Rr][0-9a-zA-Z]{6,10}`). La config Ziggy passe par la prop partagée
  `ziggy` (HandleInertiaRequests) → `use(ZiggyVue, props.initialPage.props.ziggy)`
  dans app.js **et** ssr.js ; plus de `@routes` en inline dans le blade.
- **SSR strict sur les props** : une page Vue qui lit une prop non déclarée
  dans `defineProps` fonctionne côté client (JSON) mais crashe le SSR en
  boucle de warning — toujours déclarer `meta`, etc.
- **larastan + withCount** : les compteurs attribués par `withCount('x as nb')`
  ne sont pas devinés → docblock `@property-read int $nb_x` sur le modèle.
- **`Illuminate\Contracts\Database\Eloquent\Builder` n'est pas générique** :
  typer les helpers de requête avec le Builder **concret**
  (`@return Builder<\App\Models\X>`, import `Illuminate\Database\Eloquent\Builder`)
  pour que `cursor()`/`get()` informent le type des lignes.
- **fputcsv (conteneur) quote les champs contenant des espaces** (enclosure
  RFC) : les tests d'exports découpent leurs assertions en fragments de
  champs, jamais sur des fragments chevauchant plusieurs champs.
- **`try_files` de nginx sert `public/robots.txt` avant le routage PHP** :
  le robots statique du socle a été supprimé — c'est la route
  `robots` (SitemapController) qui fait foi ; ne pas recréer de
  fichier `public/robots.txt`.
- **Workflows GitHub Actions** : `codeql.yml` a `runs-on: ubuntu-latest`
  **figé** — le contexte `runner` n'existe pas au niveau
  `jobs.<id>.runs-on` (uniquement dans les steps et les `if`) :
  `runs-on: ${{ runner.os }}` fait rejeter le workflow avant tout job
  (« Unrecognized named-value: 'runner' »). Dans les `with:` des steps
  (`${{ runner.os }}` de la clé cache composer), c'est en revanche valide.

## Tests

```bash
docker compose run --rm app php artisan test   # PHPUnit (SQLite mémoire)
npm run test:js                                 # modules carte (sans navigateur)
docker compose run --rm app ./vendor/bin/pint --test
docker compose run --rm app ./vendor/bin/phpstan analyse --no-progress
```

## Déploiement (Plesk / PulseHéberg)

1. `composer install --no-dev` + `npm ci && npm run build` (assets commités
   interdits : build à chaque mise en prod).
2. Importer le SQL portable généré en local
   (`storage/app/portail/deploy/portail-deploy-mysql.sql`).
3. Copier `public/geo/` tel quel (généré par l'import local) ; cache long
   sur `/geo/` (surcouches précalculées, exports GeoJSON les relisent).
4. `.env` production : `APP_URL=https://znieff.fr`, `APP_ENV=production`,
   base MySQL du mutualisé.
5. **SSR (SEO — titles, descriptions, contenu rendus)** : lancer
   `node bootstrap/ssr/ssr.js` en service (SSR_PORT dédié sur 127.0.0.1 —
   le défaut 13714 est partagé par toutes les apps Inertia du serveur) puis
   `.env` : `INERTIA_SSR_ENABLED=true`,
   `INERTIA_SSR_URL=http://127.0.0.1:{port dédié}` ; un watchdog (cron)
   relance le service. Si le service tombe, les pages retombent sur le
   shell client (jamais d'erreur 500).
6. **Sitemap** : soumettre `https://znieff.fr/sitemap.xml` à Search Console
   (index + lots de 10 000 URLs par famille — toutes les fiches, toutes les
   communes même sans zonage). `robots.txt` est servi par la route `robots` :
   ne jamais recréer de `public/robots.txt` statique (nginx le servirait
   avant PHP).

## Licence

Code : AGPL-3.0 (`LICENSE`). Données : conditions INPN/MNHN, INSEE, IGN —
attribution MNHN sur chaque page du site.
