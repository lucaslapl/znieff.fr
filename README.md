# Portail ZNIEFF

Site public : **<https://znieff.fr>** (MVP complet — itérations a à e).
Même équipe que [l'Observatoire des ABC](https://github.com/lucaslapl/Observatoire-des-ABC) :
faits bruts sourcés, jamais d'indicateur composite, sources créditées.

## Présentation

Le Portail ZNIEFF réorganise l'inventaire du patrimoine naturel officiel
français **par commune**, sur toute la France : ZNIEFF de type I et II,
sites Natura 2000, aires protégées. Chaque commune — même sans aucun zonage —
a une fiche ; chaque zonage expose sa table de croisement avec les communes
(surface d'intersection, part du zonage, part du territoire communal).

**Aucune géométrie en production.** PostGIS ne tourne qu'en local (conteneur
Docker import uniquement) : les intersections et surfaces sont précalculées
lors de l'import annuel, puis exportées en SQL portable (MySQL mutualisé) et
GeoJSON statiques. La production ne fait jamais de calcul spatial à la volée.

## Fonctionnalités (MVP)

- Fiches communes (code INSEE, population INSEE, superficie, codes postaux,
  zonages avec pourcentages du territoire couverts, communes voisines à 15 km,
  lien vers l'Observatoire des ABC si la commune a un ABC).
- Fiches zonages : ZNIEFF (date de création/modification géométrique INPN,
  superficie calculée en Lambert-93, réf. SU_ZN), Natura 2000 (SIC/ZPS),
  aires protégées (APPB, RNR, RNN…) — avec table de croisement par commune
  et lien vers la fiche INPN. Espèces et habitats **jamais rediffusés**.
- Carte Leaflet : fonds OSM ou Plan IGN v2 (WMTS libres Géoplateforme,
  **sans clé**), surcouches GeoJSON précalculées, cadrages
  `/carte/znieff/{id}` et `/carte/commune/{slug}` (popups cliquables).
- Listes paginées (communes, ZNIEFF, Natura 2000, aires protégées) avec
  filtres simples (département, type, recherche nom/identifiant/code
  postal), pagination serveur 25/page ; recherche transverse
  (`/recherche`) : un terme exact mène à la fiche (« Louviers », « 8526 »,
  « Eure »).
- Exports CSV et GeoJSON des listes filtrées (conditions INPN, jamais
  LO 2.0) : CSV complet de France en flux, GeoJSON par périmètre.
- Page « Données & méthodologie » : sources, versions des référentiels
  datées, ce que le portail ne fait pas, réutilisation, technique.
- SEO : sitemap exhaustif (`/sitemap.xml` — index + lots de 10 000 URLs
  par famille, toutes les fiches), `robots.txt` servi par le portail,
  canonical par page, rendu serveur Inertia (SSR) des `<title>`,
  descriptions et contenu.
- Audience anonyme révocable, aucun traceur publicitaire.

## Sources de données

- **ZNIEFF, Natura 2000, aires protégées** : INPN (MNHN), conditions de
  réutilisation INPN — **jamais LO 2.0** — attribution MNHN partout.
- **Contours communaux** : AdminExpress (IGN), édition 2025.
- **Population, superficie, codes postaux** : INSEE / geo.api.gouv.fr.
- Badge « ABC » : API de l'[Observatoire des ABC](https://observatoire-abc.fr)
  (une seule requête en cache).
- Les surfaces affichées sont des **aires géométriques calculées en
  Lambert-93** (EPSG:2154) ; l'écart constaté avec les superficies INSEE
  est affiché « ≈ ± 1 % » sur le site.

## Démarrage (docker Compose)

```bash
git clone https://github.com/lucaslapl/znieff.fr && cd znieff.fr
cp .env.example .env
docker compose up -d app                        # http://localhost:8010
docker compose --profile build run --rm assets # build front
```

Import d'un département (ex. Eure 27) avec les données INPN montées dans
`/data/inpn-data` :

```bash
docker compose exec app php artisan portail:refresh-communes --dept=27 --skip-abc
docker compose exec app php artisan portail:import-inpn --source=all --dept=27
docker compose exec app php artisan portail:check
docker compose exec app php artisan portail:export-sql
```

Commandes idempotes et journalisées ; import complet France :
sans `--dept`. Détails : `AGENTS.md`.

## Tests et qualité

- PHPUnit (SQLite mémoire), tests JS sans navigateur (`node --test`).
- Laravel Pint, PHPStan niveau 5 (Larastan).
- CI GitHub Actions (PHP 8.3 + Node 24) : `.github/workflows/ci.yml`.

## Déploiement (PulseHéberg / Plesk)

1. `composer install --no-dev`, `npm ci && npm run build`.
2. Importer `storage/app/portail/deploy/portail-deploy-mysql.sql` (généré en
   local par `portail:export-sql`, booléens 1/0, `utf8mb4`, FK `id_parent`
   posée en second passage).
3. Copier `public/geo/` (surcouches précalculées) tel quel.
4. `.env` : `APP_URL=https://znieff.fr`, `APP_ENV=production`.

## Licence

Code : **AGPL-3.0** (`LICENSE`). Données : conditions INPN/MNHN, INSEE, IGN —
attribution MNHN sur chaque page. Ce projet ne rediffuse ni les espèces ni
les habitats des ZNIEFF : renvoi systématique vers les fiches INPN.
