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
3. Copier `public/geo/` tel quel (généré par l'import local).
4. `.env` production : `APP_URL=https://znieff.fr`, `APP_ENV=production`,
   base MySQL du mutualisé, `PORTAIL_GEO_DIR` sur le chemin servi.

## Licence

Code : AGPL-3.0 (`LICENSE`). Données : conditions INPN/MNHN, INSEE, IGN —
attribution MNHN sur chaque page du site.
