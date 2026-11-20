<?php

namespace App\Http\Controllers;

use App\Models\AireProtegee;
use App\Support\Chapeaux;
use App\Support\Croisements;
use App\Support\Exports;
use App\Support\Recherche;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AireProtegeeController extends Controller
{
    private const PAR_PAGE = 25;

    /**
     * Liste des aires protégées (itération d) : catalogue APA-INPN,
     * recherche par identifiant (FR…) ou par nom, filtres type (APPB,
     * RNR, RNN…) et département. Export CSV/GeoJSON de la liste
     * filtrée, sous conditions INPN.
     */
    public function index(Request $request): Response|StreamedResponse
    {
        $dept = Recherche::dept($request->query('dept'));
        $q = Recherche::terme($request->query('q'));
        $type = is_string($request->query('type')) && $request->query('type') !== '' ? strtoupper($request->query('type')) : null;

        $export = $this->exportDemande($request->query('export'));
        if ($export !== null) {
            return $this->export($export, $q, $dept, $type);
        }

        /** @var LengthAwarePaginator<AireProtegee> $pages */
        $pages = $this->requete($q, $dept, $type)
            ->paginate(self::PAR_PAGE)
            ->appends(array_filter(['q' => $q, 'dept' => $dept, 'type' => $type], fn ($v) => $v !== null && $v !== ''));

        // Types de désignation présents dans le référentiel (10 en France), pour le filtre.
        $apTypes = AireProtegee::query()
            ->distinct()
            ->orderBy('type_code')
            ->get(['type_code', 'type_nom'])
            ->map(fn (AireProtegee $a) => ['code' => (string) $a->type_code, 'nom' => (string) $a->type_nom])
            ->all();

        $meta = sprintf(
            'Aires protégées du catalogue APA-INPN%s : %d zonages juridiques (APPB, réserves, sites classés…) avec leurs superficies et leurs communes.',
            $dept !== null ? ' — '.$dept : '',
            $pages->total(),
        );

        return Inertia::render('Aires/Index', [
            'meta' => [
                'title' => 'Aires protégées'.($dept !== null ? sprintf(' — %s (%s)', config('portail.departements')[$dept], $dept) : ''),
                'description' => Chapeaux::meta($meta),
                'canonical' => route('ap.index').($dept !== null ? '?dept='.$dept : ''),
                'noindex' => $q !== '',
            ],
            'departements' => config('portail.departements'),
            'filtres' => ['q' => $q, 'dept' => $dept, 'type' => $type],
            'deptNom' => $dept !== null ? config('portail.departements')[$dept] : null,
            'apTypes' => $apTypes,
            'aires' => array_map(fn (AireProtegee $a) => $this->ligne($a), $pages->items()),
            'pagination' => $this->pagination($pages),
        ]);
    }

    /**
     * Fiche d'une aire protégée (APPB, RNR, RNN… — zonage juridique, à
     * la différence d'une ZNIEFF qui est un inventaire scientifique).
     */
    public function show(Request $request, string $id): Response
    {
        $id = strtoupper($id);
        $aire = AireProtegee::query()->findOrFail($id);

        // Liste des communes paginée côté serveur (convention AGENTS.md).
        $page = max(1, (int) $request->query('page', '1'));
        $communes = Croisements::communesAirePagine($aire->id, $page, self::PAR_PAGE);

        $chapeau = Chapeaux::aire([
            'id' => $aire->id,
            'type_nom' => $aire->type_nom,
            'nom' => $aire->nom,
            'superficie_ha' => $aire->superficie_ha,
            'superficie_ref_ha' => $aire->superficie_ref_ha,
            'acte' => $aire->acte,
            'gestionnaire' => $aire->gestionnaire,
            'date_crea_sign' => $aire->date_crea_sign?->toDateString(),
            'date_modif_geo' => $aire->date_modif_geo?->toDateString(),
        ], $communes['total'], $communes['principale']);

        $url = route('ap.show', $aire->id);

        return Inertia::render('Aires/Show', [
            'meta' => ['title' => sprintf('« %s » — %s %s', $aire->nom, (string) ($aire->type_nom ?? 'Aire protégée'), $aire->id), 'description' => Chapeaux::meta($chapeau), 'canonical' => $url],
            'aire' => [
                'id' => $aire->id,
                'nom' => $aire->nom,
                'type_code' => $aire->type_code,
                'type_nom' => $aire->type_nom,
                'superficie_ha' => Chapeaux::ha($aire->superficie_ha),
                'superficie_ref_ha' => Chapeaux::ha($aire->superficie_ref_ha),
                'acte' => $aire->acte,
                'gestionnaire' => $aire->gestionnaire,
                'doc_gestion' => (bool) $aire->doc_gestion,
                'date_crea_sign' => Chapeaux::date($aire->date_crea_sign?->toDateString()),
                'date_modif_geo' => Chapeaux::date($aire->date_modif_geo?->toDateString()),
                'url_inpn' => $aire->url_inpn,
                'geojson' => '/geo/ap/'.$aire->id.'.geojson',
            ],
            'chapeau' => $chapeau,
            'communes' => array_map(fn (array $c) => [
                'slug' => $c['slug'],
                'nom' => $c['nom'],
                'dept' => $c['dept'],
                'surface_ha' => Chapeaux::ha($c['surface_ha']),
                'part_zonage' => Chapeaux::pct($c['part_zonage']),
                'part_commune' => Chapeaux::pct($c['part_commune']),
            ], $communes['items']),
            'nbCommunes' => $communes['total'],
            'pagination' => $this->paginationFiche('ap.show', ['id' => $aire->id], $communes['page'], self::PAR_PAGE, $communes['total']),
        ]);
    }

    // ------------------------------------------------------------------
    // Liste : construction et exports
    // ------------------------------------------------------------------

    /**
     * Requête listée : identifiant exact (FR…) ou nom ; type de
     * désignation (APPB, RNR, RNN…) ; département via les communes
     * croisées.
     *
     * @return EloquentBuilder<AireProtegee>
     */
    private function requete(string $q, ?string $dept, ?string $type): EloquentBuilder
    {
        return AireProtegee::query()
            ->withCount('communes as nb_communes')
            ->when($dept !== null, fn (EloquentBuilder $query) => $query->whereHas('communes', fn (EloquentBuilder $c) => $c->where('dept', $dept)))
            ->when($q !== '', function (EloquentBuilder $query) use ($q) {
                $query->where(function (EloquentBuilder $w) use ($q) {
                    if (Recherche::estCodeInpn($q)) {
                        $w->where('id', strtoupper($q));
                    }
                    $w->orWhere('nom', 'like', '%'.$q.'%');
                });
            })
            ->when($type !== null, fn (EloquentBuilder $query) => $query->where('type_code', $type))
            ->orderByDesc('superficie_ha');
    }

    /**
     * Une ligne de la liste (tableau pur).
     *
     * @return array{id: string, type_code: string, type_nom: string, nom: string, superficie_ha: ?string, nbCommunes: int, maj: ?string}
     */
    private function ligne(AireProtegee $a): array
    {
        return [
            'id' => $a->id,
            'type_code' => (string) $a->type_code,
            'type_nom' => (string) $a->type_nom,
            'nom' => $a->nom,
            'superficie_ha' => $a->superficie_ha !== null ? Chapeaux::ha($a->superficie_ha) : null,
            'nbCommunes' => (int) $a->nb_communes,
            'maj' => Chapeaux::date($a->date_modif_geo?->toDateString()),
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
     * Export de la liste filtrée en flux. GeoJSON : périmètre requis
     * (dept ou recherche) — ~12 000 aires protégées en France ne
     * tiennent pas dans un seul fichier, le CSV complet si.
     */
    private function export(string $format, string $q, ?string $dept, ?string $type): StreamedResponse
    {
        if ($format === 'geojson' && $dept === null && $q === '' && $type === null) {
            abort(400, 'L\'export GeoJSON nécessite un filtre (département, type ou recherche) : le catalogue complet de la France ne tient pas dans un seul fichier. L\'export CSV complet, lui, est disponible.');
        }

        $entetes = ['id', 'type_code', 'type', 'nom', 'superficie_ha', 'communes_nb', 'acte', 'gestionnaire', 'date_signature', 'date_modif_geometrique', 'fiche_inpn', 'fiche_portail'];
        $nomFichier = Exports::nomFichier('portail-znieff-aires', $format, $dept);

        if ($format === 'csv') {
            $lignes = (function () use ($q, $dept, $type) {
                foreach ($this->requete($q, $dept, $type)->cursor() as $a) {
                    yield $this->ligneCsv($a);
                }
            })();

            return Exports::csv($nomFichier, $entetes, $lignes);
        }

        $features = (function () use ($q, $dept, $type, $entetes) {
            foreach ($this->requete($q, $dept, $type)->cursor() as $a) {
                yield [
                    'type' => 'Feature',
                    'properties' => array_combine($entetes, $this->ligneCsv($a)),
                    'geometry' => Exports::geometry('ap', $a->id),
                ];
            }
        })();

        return Exports::geojson($nomFichier, $features);
    }

    /**
     * Ligne CSV d'une aire protégée.
     *
     * @return array<int, string|null>
     */
    private function ligneCsv(AireProtegee $a): array
    {
        return [
            $a->id,
            (string) $a->type_code,
            (string) $a->type_nom,
            $a->nom,
            $a->superficie_ha !== null ? Exports::nombre($a->superficie_ha) : null,
            (string) $a->nb_communes,
            $a->acte,
            $a->gestionnaire,
            $a->date_crea_sign?->toDateString(),
            $a->date_modif_geo?->toDateString(),
            $a->url_inpn,
            route('ap.show', $a->id),
        ];
    }
}
