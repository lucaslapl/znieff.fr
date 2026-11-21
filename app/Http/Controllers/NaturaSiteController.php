<?php

namespace App\Http\Controllers;

use App\Models\NaturaSite;
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

class NaturaSiteController extends Controller
{
    private const PAR_PAGE = 25;

    /**
     * Liste des sites Natura 2000 (itération d) : recherche par code
     * (FR…) ou par nom, filtres type (SIC/ZPS) et département.
     * Export CSV/GeoJSON de la liste filtrée sous conditions INPN.
     */
    public function index(Request $request): Response|StreamedResponse
    {
        $dept = Recherche::dept($request->query('dept'));
        $q = Recherche::terme($request->query('q'));
        $type = is_string($request->query('type')) ? strtoupper($request->query('type')) : null;
        if ($type !== null && $type !== 'SIC' && $type !== 'ZPS') {
            $type = null;
        }

        $export = $this->exportDemande($request->query('export'));
        if ($export !== null) {
            return $this->export($export, $q, $dept, $type);
        }

        /** @var LengthAwarePaginator<NaturaSite> $pages */
        $pages = $this->requete($q, $dept, $type)
            ->paginate(self::PAR_PAGE)
            ->appends(array_filter(['q' => $q, 'dept' => $dept, 'type' => $type], fn ($v) => $v !== null && $v !== ''));

        $meta = sprintf(
            'Sites Natura 2000 (SIC/ZPS) du référentiel INPN%s : %d sites avec leurs superficies et leurs communes — recherche par code ou par nom.',
            $dept !== null ? ' — '.$dept : '',
            $pages->total(),
        );

        return Inertia::render('Natura/Index', [
            'meta' => [
                'title' => 'Sites Natura 2000'.($dept !== null ? sprintf(' — %s (%s)', config('portail.departements')[$dept], $dept) : ''),
                'description' => Chapeaux::meta($meta),
                'canonical' => route('natura.index').($dept !== null ? '?dept='.$dept : ''),
                'noindex' => $q !== '',
            ],
            'departements' => config('portail.departements'),
            'filtres' => ['q' => $q, 'dept' => $dept, 'type' => $type],
            'deptNom' => $dept !== null ? config('portail.departements')[$dept] : null,
            'sites' => array_map(fn (NaturaSite $s) => $this->ligne($s), $pages->items()),
            'pagination' => $this->pagination($pages),
        ]);
    }

    /**
     * Fiche d'un site Natura 2000 (SIC/ZSC ou ZPS) — code européen FR.
     * Habitats et espèces d'intérêt communautaire du formulaire standard,
     * listés tels quels (paginés serveur) — crédit INPN/MNHN ; le DOCOB
     * reste sur la fiche INPN.
     */
    public function show(Request $request, string $code): Response
    {
        $code = strtoupper($code);
        $site = NaturaSite::query()->findOrFail($code);

        // Liste des communes paginée côté serveur (convention AGENTS.md).
        $page = max(1, (int) $request->query('page', '1'));
        $communes = Croisements::communesNaturaPagine($site->code, $page, self::PAR_PAGE);
        // Habitats et espèces du FSD, paginés eux aussi (?page_habitats=,
        // ?page_especes=) : la liste complète n'est jamais envoyée au client.
        $pageHabitats = max(1, (int) $request->query('page_habitats', '1'));
        $pageEspeces = max(1, (int) $request->query('page_especes', '1'));
        $habitats = Especes::habitatsNaturaPagine($site->code, $pageHabitats, self::PAR_PAGE);
        $especes = Especes::especesNaturaPagine($site->code, $pageEspeces, self::PAR_PAGE);

        $chapeau = Chapeaux::natura([
            'code' => $site->code,
            'type' => $site->type,
            'nom' => $site->nom,
            'superficie_ha' => $site->superficie_ha,
            'superficie_ref_ha' => $site->superficie_ref_ha,
            'date_designation' => $site->date_designation?->toDateString(),
            'date_modif_geo' => $site->date_modif_geo?->toDateString(),
        ], $communes['total'], $communes['principale']);

        $url = route('natura.show', $site->code);

        return Inertia::render('Natura/Show', [
            'meta' => ['title' => sprintf('%s « %s » — Natura 2000 %s', strtoupper((string) $site->type), $site->nom, $site->code), 'description' => Chapeaux::meta($chapeau), 'canonical' => $url],
            'site' => [
                'code' => $site->code,
                'nom' => $site->nom,
                'type' => strtoupper((string) $site->type),
                'superficie_ha' => Chapeaux::ha($site->superficie_ha),
                'superficie_ref_ha' => Chapeaux::ha($site->superficie_ref_ha),
                'precision_saisie' => $site->precision_saisie,
                'date_designation' => Chapeaux::date($site->date_designation?->toDateString()),
                'date_modif_geo' => Chapeaux::date($site->date_modif_geo?->toDateString()),
                'url_inpn' => $site->url_inpn,
                'geojson' => '/geo/natura/'.$site->code.'.geojson',
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
            'pagination' => $this->paginationFiche('natura.show', ['code' => $site->code], $communes['page'], self::PAR_PAGE, $communes['total']),
            'habitats' => $habitats['items'],
            'nbHabitats' => $habitats['total'],
            'paginationHabitats' => $this->paginationFiche('natura.show', ['code' => $site->code], $habitats['page'], self::PAR_PAGE, $habitats['total'], 'page_habitats'),
            'especes' => $especes['items'],
            'nbEspeces' => $especes['total'],
            'paginationEspeces' => $this->paginationFiche('natura.show', ['code' => $site->code], $especes['page'], self::PAR_PAGE, $especes['total'], 'page_especes'),
        ]);
    }

    // ------------------------------------------------------------------
    // Liste : construction et exports
    // ------------------------------------------------------------------

    /**
     * Requête listée : code exact (FR…) ou nom ; type SIC/ZPS (case
     * insensible, la base INPN mélange majuscules/minuscules) ;
     * département via les communes croisées.
     *
     * @return EloquentBuilder<NaturaSite>
     */
    private function requete(string $q, ?string $dept, ?string $type): EloquentBuilder
    {
        return NaturaSite::query()
            ->withCount('communes as nb_communes')
            ->when($dept !== null, fn (EloquentBuilder $query) => $query->whereHas('communes', fn (EloquentBuilder $c) => $c->where('dept', $dept)))
            ->when($q !== '', function (EloquentBuilder $query) use ($q) {
                $query->where(function (EloquentBuilder $w) use ($q) {
                    if (Recherche::estCodeInpn($q)) {
                        $w->where('code', strtoupper($q));
                    }
                    $w->orWhere('nom', 'like', '%'.$q.'%');
                });
            })
            ->when($type !== null, function (EloquentBuilder $query) use ($type) {
                $query->where(function (EloquentBuilder $w) use ($type) {
                    $w->where('type', $type)->orWhere('type', strtolower($type));
                });
            })
            ->orderByDesc('superficie_ha');
    }

    /**
     * Une ligne de la liste (tableau pur).
     *
     * @return array{code: string, type: string, nom: string, superficie_ha: ?string, nbCommunes: int, maj: ?string}
     */
    private function ligne(NaturaSite $s): array
    {
        return [
            'code' => $s->code,
            'type' => strtoupper((string) $s->type),
            'nom' => $s->nom,
            'superficie_ha' => $s->superficie_ha !== null ? Chapeaux::ha($s->superficie_ha) : null,
            'nbCommunes' => (int) $s->nb_communes,
            'maj' => Chapeaux::date($s->date_modif_geo?->toDateString()),
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
     * (dept ou recherche) — ~2 000 sites sur la France ne tiennent
     * pas dans un seul fichier, le CSV complet si.
     */
    private function export(string $format, string $q, ?string $dept, ?string $type): StreamedResponse
    {
        if ($format === 'geojson' && $dept === null && $q === '' && $type === null) {
            abort(400, 'L\'export GeoJSON nécessite un filtre (département ou recherche) : le périmètre complet de la France ne tient pas dans un seul fichier. L\'export CSV complet, lui, est disponible.');
        }

        $entetes = ['code_natura2000', 'type', 'nom', 'superficie_ha', 'communes_nb', 'date_designation', 'date_modif_geometrique', 'fiche_inpn', 'fiche_portail'];
        $nomFichier = Exports::nomFichier('portail-znieff-natura', $format, $dept);

        if ($format === 'csv') {
            $lignes = (function () use ($q, $dept, $type) {
                foreach ($this->requete($q, $dept, $type)->cursor() as $s) {
                    yield $this->ligneCsv($s);
                }
            })();

            return Exports::csv($nomFichier, $entetes, $lignes);
        }

        $features = (function () use ($q, $dept, $type, $entetes) {
            foreach ($this->requete($q, $dept, $type)->cursor() as $s) {
                yield [
                    'type' => 'Feature',
                    'properties' => array_combine($entetes, $this->ligneCsv($s)),
                    'geometry' => Exports::geometry('natura', $s->code),
                ];
            }
        })();

        return Exports::geojson($nomFichier, $features);
    }

    /**
     * Ligne CSV d'un site Natura 2000.
     *
     * @return array<int, string|null>
     */
    private function ligneCsv(NaturaSite $s): array
    {
        return [
            $s->code,
            strtoupper((string) $s->type),
            $s->nom,
            $s->superficie_ha !== null ? Exports::nombre($s->superficie_ha) : null,
            (string) $s->nb_communes,
            $s->date_designation?->toDateString(),
            $s->date_modif_geo?->toDateString(),
            $s->url_inpn,
            route('natura.show', $s->code),
        ];
    }
}
