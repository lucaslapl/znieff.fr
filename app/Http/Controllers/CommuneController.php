<?php

namespace App\Http\Controllers;

use App\Models\Commune;
use App\Support\Chapeaux;
use App\Support\Croisements;
use App\Support\Especes;
use App\Support\Exports;
use App\Support\Recherche;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Fiches et liste des communes (itérations b et d). Toutes les
 * requêtes sont des clauses indexées simples : aucun calcul spatial
 * en ligne — uniquement les pivots précalculés à l'import annuel.
 */
class CommuneController extends Controller
{
    private const PAR_PAGE = 25;

    /**
     * Liste / recherche des communes : nom (casse et accents), code
     * INSEE, code postal ; filtre département (COG 102 départements).
     * Pagination serveur, exports CSV/GeoJSON de la liste filtrée
     * (?export=csv|geojson, sous conditions INPN, jamais LO 2.0).
     */
    public function index(Request $request): Response|StreamedResponse
    {
        $dept = Recherche::dept($request->query('dept'));
        $q = Recherche::terme($request->query('q'));

        $export = $this->exportDemande($request->query('export'));
        if ($export !== null) {
            return $this->export($export, $q, $dept);
        }

        /** @var LengthAwarePaginator<Commune> $pages */
        $pages = $this->requete($q, $dept)
            ->paginate(self::PAR_PAGE)
            ->appends(array_filter(['q' => $q, 'dept' => $dept], fn ($v) => $v !== null && $v !== ''));

        $meta = sprintf(
            'Communes de France%s : %d communes, avec leurs ZNIEFF, sites Natura 2000 et aires protégées — recherche par nom, code INSEE ou code postal.',
            $dept !== null ? ' — '.$dept : '',
            $pages->total(),
        );

        return Inertia::render('Communes/Index', [
            'meta' => [
                'title' => 'Communes'.($dept !== null ? sprintf(' — %s (%s)', config('portail.departements')[$dept], $dept) : ''),
                'description' => Chapeaux::meta($meta),
                'canonical' => route('communes.index').($dept !== null ? '?dept='.$dept : ''),
                'noindex' => $q !== '',
            ],
            'departements' => config('portail.departements'),
            'filtres' => ['q' => $q, 'dept' => $dept],
            'deptNom' => $dept !== null ? config('portail.departements')[$dept] : null,
            'communes' => array_map(fn (Commune $c) => $this->ligne($c), $pages->items()),
            'pagination' => $this->pagination($pages),
        ]);
    }

    /**
     * Fiche d'une commune — toutes les communes de France en ont une,
     * y compris sans aucun zonage (leur fiche le dit explicitement,
     * jamais de page vide). Aucun calcul spatial : pivots précalculés.
     */
    public function show(string $slug): Response
    {
        $commune = Commune::query()->where('slug', $slug)->firstOrFail();

        $zonages = Croisements::zonagesCommune($commune->insee);
        $natura = Croisements::naturaCommune($commune->insee);
        $aires = Croisements::airesCommune($commune->insee);
        $voisines = $commune->voisinesAvecDistance();
        // Compteurs d'espèces déterminantes (hors confidentielles — filtre
        // absolu dans App\Support\Especes) par zonage, et espèces/habitats
        // par site Natura 2000.
        $compteurs = Especes::compteursCommune($commune->insee);

        $url = route('commune.show', $commune->slug);
        $meta = sprintf(
            'Commune de %s (%s) : %d ZNIEFF, %d site(s) Natura 2000, %d aire(s) protégée(s) recensé(s) par l\'INPN sur le territoire communal.',
            $commune->nom,
            $commune->dept,
            count($zonages),
            count($natura),
            count($aires),
        );

        return Inertia::render('Communes/Show', [
            'meta' => ['title' => sprintf('Patrimoine naturel à %s (%s)', $commune->nom, $commune->dept), 'description' => Chapeaux::meta($meta), 'canonical' => $url],
            'commune' => [
                'insee' => $commune->insee,
                'slug' => $commune->slug,
                'nom' => $commune->nom,
                'dept' => $commune->dept,
                'dept_nom' => $commune->dept_nom,
                'reg_nom' => $commune->reg_nom,
                'population' => $commune->population !== null ? Chapeaux::nombre($commune->population, 0) : null,
                'superficie_ha' => Chapeaux::ha($commune->superficie_ha),
                'codes_postaux' => $commune->codes_postaux,
                'has_abc' => (bool) $commune->has_abc,
                'geojson' => '/geo/communes/'.$commune->insee.'.geojson',
            ],
            'zonages' => array_map(fn (array $z) => [
                'id' => $z['id'],
                'famille' => $z['famille'],
                'badge' => $z['famille'] === 'z1' ? 'znieff1' : 'znieff2',
                'nom' => $z['nom'],
                'superficie_ha' => Chapeaux::ha($z['superficie_ha']),
                'surface_ha' => Chapeaux::ha($z['surface_ha']),
                'part_commune' => Chapeaux::pct($z['part_commune']),
                'nb_especes' => (int) ($compteurs['znieff'][$z['id']] ?? 0),
            ], $zonages),
            'natura' => array_map(fn (array $s) => [
                'code' => $s['code'],
                'type' => strtoupper($s['type']),
                'nom' => $s['nom'],
                'superficie_ha' => Chapeaux::ha($s['superficie_ha']),
                'surface_ha' => Chapeaux::ha($s['surface_ha']),
                'part_commune' => Chapeaux::pct($s['part_commune']),
                'nb_especes' => (int) ($compteurs['natura_especes'][$s['code']] ?? 0),
                'nb_habitats' => (int) ($compteurs['natura_habitats'][$s['code']] ?? 0),
            ], $natura),
            'aires' => array_map(fn (array $a) => [
                'id' => $a['id'],
                'type_nom' => $a['type_nom'] ?? $a['type_code'],
                'nom' => $a['nom'],
                'superficie_ha' => Chapeaux::ha($a['superficie_ha']),
                'surface_ha' => Chapeaux::ha($a['surface_ha']),
                'part_commune' => Chapeaux::pct($a['part_commune']),
            ], $aires),
            'voisines' => $voisines->map(fn (array $v) => [
                'slug' => (string) $v['commune']->slug,
                'nom' => (string) $v['commune']->nom,
                'distance_m' => (int) $v['distance_m'],
            ])->all(),
            'nbZonages' => count($zonages),
            'nbNatura' => count($natura),
            'nbAires' => count($aires),
        ]);
    }

    // ------------------------------------------------------------------
    // Liste : construction et exports
    // ------------------------------------------------------------------

    /**
     * Requête listée : filtres simples indexés. Les compteurs de
     * zonages (colonne « Zonages ») viennent de trois sous-requêtes
     * `count` sur les pivots — précalculés, jamais recalculés.
     *
     * @return EloquentBuilder<Commune>
     */
    private function requete(string $q, ?string $dept): EloquentBuilder
    {
        return Commune::query()
            ->withCount([
                'zonages as nb_zonages',
                'naturaSites as nb_natura',
                'airesProtegees as nb_ap',
            ])
            ->when($dept !== null, fn (EloquentBuilder $query) => $query->where('dept', $dept))
            ->when($q !== '', function (EloquentBuilder $query) use ($q) {
                $slug = Recherche::slug($q);
                $insee = str_replace(' ', '', $q);

                $query->where(function (EloquentBuilder $w) use ($q, $slug, $insee) {
                    $w->where('nom', 'like', '%'.$q.'%')
                        ->orWhere('slug', 'like', '%'.$slug.'%')
                        ->orWhere('insee', 'like', $insee.'%')
                        ->orWhere('codes_postaux', 'like', $insee.'%')
                        ->orWhere('codes_postaux', 'like', '%,'.$insee.'%');
                });
            })
            ->orderBy('nom');
    }

    /**
     * Une ligne de la liste (tableau pur, jamais de modèle Inertia).
     *
     * @return array{insee: string, slug: string, nom: string, dept: string, reg_nom: ?string, population: ?string, superficie_ha: ?string, nbZonages: int, nbNatura: int, nbAp: int, has_abc: bool}
     */
    private function ligne(Commune $c): array
    {
        return [
            'insee' => $c->insee,
            'slug' => $c->slug,
            'nom' => $c->nom,
            'dept' => $c->dept,
            'reg_nom' => $c->reg_nom,
            'population' => $c->population !== null ? Chapeaux::nombre($c->population, 0) : null,
            'superficie_ha' => $c->superficie_ha !== null ? Chapeaux::ha($c->superficie_ha) : null,
            'nbZonages' => (int) $c->nb_zonages,
            'nbNatura' => (int) $c->nb_natura,
            'nbAp' => (int) $c->nb_ap,
            'has_abc' => (bool) $c->has_abc,
        ];
    }

    /**
     * @return array{total: int, page: int, derniere: int, url_precedente: ?string, url_suivante: ?string}
     */
    private function pagination(LengthAwarePaginator $pages): array
    {
        $courante = $pages->currentPage();

        return [
            'total' => $pages->total(),
            'page' => $courante,
            'derniere' => max(1, $pages->lastPage()),
            'url_precedente' => $courante > 1 ? $pages->url($courante - 1) : null,
            'url_suivante' => $courante < $pages->lastPage() ? $pages->url($courante + 1) : null,
        ];
    }

    /**
     * Format d'export demandé (?export=csv|geojson), sinon null.
     */
    private function exportDemande(mixed $export): ?string
    {
        return $export === 'csv' || $export === 'geojson' ? $export : null;
    }

    /**
     * Export de la liste filtrée, en flux (même requête que la liste).
     * GeoJSON : nécessite un périmètre raisonnable (département ou
     * recherche) — le CSV complet de France reste disponible.
     */
    private function export(string $format, string $q, ?string $dept): StreamedResponse
    {
        if ($format === 'geojson' && $dept === null && $q === '') {
            abort(400, 'L\'export GeoJSON nécessite un filtre (département ou recherche) : le périmètre complet de la France ne tient pas dans un seul fichier. L\'export CSV complet, lui, est disponible.');
        }

        $entetes = ['insee', 'commune', 'dept', 'departement', 'region', 'population_insee', 'superficie_ha', 'codes_postaux', 'znieff_nb', 'natura2000_nb', 'aires_protegees_nb', 'abc', 'fiche'];
        $nomFichier = Exports::nomFichier('portail-znieff-communes', $format, $dept);

        if ($format === 'csv') {
            $lignes = (function () use ($q, $dept) {
                foreach ($this->requete($q, $dept)->cursor() as $c) {
                    yield $this->ligneCsv($c);
                }
            })();

            return Exports::csv($nomFichier, $entetes, $lignes);
        }

        $features = (function () use ($q, $dept, $entetes) {
            foreach ($this->requete($q, $dept)->cursor() as $c) {
                yield [
                    'type' => 'Feature',
                    'properties' => array_combine($entetes, $this->ligneCsv($c)),
                    'geometry' => Exports::geometry('communes', $c->insee),
                ];
            }
        })();

        return Exports::geojson($nomFichier, $features);
    }

    /**
     * Ligne CSV d'une commune : nombres bruts (virgule décimale), date
     * ISO, URL de la fiche du portail.
     *
     * @return array<int, string|null>
     */
    private function ligneCsv(Commune $c): array
    {
        return [
            $c->insee,
            $c->nom,
            $c->dept,
            $c->dept_nom,
            $c->reg_nom,
            $c->population !== null ? (string) $c->population : null,
            $c->superficie_ha !== null ? Exports::nombre($c->superficie_ha) : null,
            $c->codes_postaux,
            (string) $c->nb_zonages,
            (string) $c->nb_natura,
            (string) $c->nb_ap,
            $c->has_abc ? 'oui' : 'non',
            route('commune.show', $c->slug),
        ];
    }
}
