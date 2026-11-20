<?php

namespace App\Http\Controllers;

use App\Models\Zonage;
use App\Support\Chapeaux;
use App\Support\Croisements;
use App\Support\Exports;
use App\Support\Recherche;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ZonageController extends Controller
{
    private const PAR_PAGE = 25;

    /**
     * Liste des ZNIEFF (itération d) : recherche par identifiant
     * (national ou ancien numéro régional, ex. 8526) ou par nom,
     * filtres type I/II et département (via les communes croisées).
     * Export CSV/GeoJSON de la liste filtrée sous conditions INPN.
     */
    public function index(Request $request): Response|StreamedResponse
    {
        $dept = Recherche::dept($request->query('dept'));
        $q = Recherche::terme($request->query('q'));
        $type = $request->query('type') === 'z1' || $request->query('type') === 'z2' ? (string) $request->query('type') : null;

        $export = $this->exportDemande($request->query('export'));
        if ($export !== null) {
            return $this->export($export, $q, $dept, $type);
        }

        /** @var LengthAwarePaginator<Zonage> $pages */
        $pages = $this->requete($q, $dept, $type)
            ->paginate(self::PAR_PAGE)
            ->appends(array_filter(['q' => $q, 'dept' => $dept, 'type' => $type], fn ($v) => $v !== null && $v !== ''));

        $meta = sprintf(
            'Zonages ZNIEFF (types I et II) du référentiel INPN%s : %d zonages avec leurs superficies et leurs communes — recherche par identifiant ou par nom.',
            $dept !== null ? ' — '.$dept : '',
            $pages->total(),
        );

        return Inertia::render('Zonages/Index', [
            'meta' => [
                'title' => 'Zonages ZNIEFF'.($dept !== null ? sprintf(' — %s (%s)', config('portail.departements')[$dept], $dept) : ''),
                'description' => Chapeaux::meta($meta),
                'canonical' => route('znieff.index').($dept !== null ? '?dept='.$dept : ''),
                'noindex' => $q !== '',
            ],
            'departements' => config('portail.departements'),
            'filtres' => ['q' => $q, 'dept' => $dept, 'type' => $type],
            'deptNom' => $dept !== null ? config('portail.departements')[$dept] : null,
            'zonages' => array_map(fn (Zonage $z) => $this->ligne($z), $pages->items()),
            'pagination' => $this->pagination($pages),
        ]);
    }

    /**
     * Fiche d'une ZNIEFF (type I ou II) par identifiant national INPN.
     * Superficie affichée = aire géométrique Lambert-93 (cohérente avec
     * les parts), référence INPN conservée — écart ≈ ± 1 % documenté.
     */
    public function show(Request $request, string $id): Response
    {
        $zonage = Zonage::query()->findOrFail($id);
        if ($zonage->famille !== 'z1' && $zonage->famille !== 'z2') {
            abort(404);
        }

        // Liste des communes paginée côté serveur (convention AGENTS.md) :
        // une ZNIEFF II peut couvrir plus de 100 communes, la liste
        // complète n'est jamais envoyée au client.
        $page = max(1, (int) $request->query('page', '1'));
        $communes = Croisements::communesZonagePagine($zonage->id, $page, self::PAR_PAGE);
        /** @var Zonage|null $parent */
        $parent = $zonage->parent()->first(['id', 'nom', 'famille']);
        /** @var Collection<int, Zonage> $enfants */
        $enfants = $zonage->enfants()->orderBy('nom')->get(['id', 'nom', 'famille']);

        $chapeau = Chapeaux::znieff([
            'id' => $zonage->id,
            'famille' => $zonage->famille,
            'nom' => $zonage->nom,
            'superficie_ha' => $zonage->superficie_ha,
            'superficie_ref_ha' => $zonage->superficie_ref_ha,
            'date_crea' => $zonage->date_crea?->toDateString(),
            'date_modif_geo' => $zonage->date_modif_geo?->toDateString(),
        ], $communes['total'], $communes['principale']);

        $url = route('znieff.show', $zonage->id);

        return Inertia::render('Zonages/Show', [
            'meta' => ['title' => sprintf('« %s » — ZNIEFF %s n° %s', $zonage->nom, $zonage->famille === 'z1' ? 'I' : 'II', $zonage->id), 'description' => Chapeaux::meta($chapeau), 'canonical' => $url],
            'zonage' => [
                'id' => $zonage->id,
                'nom' => $zonage->nom,
                'famille' => $zonage->famille,
                'badge' => $zonage->badgeClasse(),
                'num_regional' => $zonage->num_regional,
                'superficie_ha' => Chapeaux::ha($zonage->superficie_ha),
                'superficie_ref_ha' => Chapeaux::ha($zonage->superficie_ref_ha),
                'precision_saisie' => $zonage->precision_saisie,
                'date_crea' => Chapeaux::date($zonage->date_crea?->toDateString()),
                'date_modif_geo' => Chapeaux::date($zonage->date_modif_geo?->toDateString()),
                'url_inpn' => $zonage->url_inpn,
                'geojson' => '/geo/zonages/'.$zonage->famille.'/'.$zonage->id.'.geojson',
            ],
            'chapeau' => $chapeau,
            'parent' => $parent !== null ? [
                'id' => $parent->id,
                'nom' => $parent->nom,
                'badge' => $parent->badgeClasse(),
            ] : null,
            'enfants' => $enfants->map(fn (Zonage $z) => [
                'id' => $z->id,
                'nom' => $z->nom,
                'badge' => $z->badgeClasse(),
            ])->all(),
            'communes' => array_map(fn (array $c) => [
                'slug' => $c['slug'],
                'nom' => $c['nom'],
                'dept' => $c['dept'],
                'surface_ha' => Chapeaux::ha($c['surface_ha']),
                'part_zonage' => Chapeaux::pct($c['part_zonage']),
                'part_commune' => Chapeaux::pct($c['part_commune']),
            ], $communes['items']),
            'nbCommunes' => $communes['total'],
            'pagination' => $this->paginationFiche('znieff.show', ['id' => $zonage->id], $communes['page'], self::PAR_PAGE, $communes['total']),
        ]);
    }

    /**
     * Ancien numéro régional (NM_REGZN, ex. 8526) : redirection
     * permanente vers la fiche canonique par identifiant national.
     */
    public function numero(string $num): RedirectResponse
    {
        $zonage = Zonage::query()->where('num_regional', $num)->first(['id']);
        if ($zonage === null) {
            abort(404);
        }

        return redirect()->to(route('znieff.show', $zonage->id), 301);
    }

    /**
     * Repli : /znieff/{numéro régional saisi comme id} → redirection.
     */
    public function showOuNumero(string $id): Response|RedirectResponse
    {
        $zonage = Zonage::query()->find($id);
        if ($zonage !== null) {
            return $this->show(request(), $id);
        }

        return $this->numero($id);
    }

    // ------------------------------------------------------------------
    // Liste : construction et exports
    // ------------------------------------------------------------------

    /**
     * Requête listée : identifiant exact (national NM_SFFZN ou ancien
     * numéro régional NM_REGZN) ou nom ; type I/II ; département via
     * les communes croisées (un zonage peut déborder : il est listé
     * dès qu'il touche une commune du département).
     *
     * @return EloquentBuilder<Zonage>
     */
    private function requete(string $q, ?string $dept, ?string $type): EloquentBuilder
    {
        return Zonage::query()
            ->withCount('communes as nb_communes')
            ->when($type !== null, fn (EloquentBuilder $query) => $query->where('famille', $type))
            ->when($dept !== null, fn (EloquentBuilder $query) => $query->whereHas('communes', fn (EloquentBuilder $c) => $c->where('dept', $dept)))
            ->when($q !== '', function (EloquentBuilder $query) use ($q) {
                $query->where(function (EloquentBuilder $w) use ($q) {
                    if (Recherche::estIdentifiantZnieff($q)) {
                        $w->where('id', $q)->orWhere('num_regional', $q);
                    }
                    $w->orWhere('nom', 'like', '%'.$q.'%');
                });
            })
            ->orderByDesc('superficie_ha');
    }

    /**
     * Une ligne de la liste (tableau pur).
     *
     * @return array{id: string, famille: string, badge: string, nom: string, num_regional: ?string, superficie_ha: ?string, nbCommunes: int, maj: ?string}
     */
    private function ligne(Zonage $z): array
    {
        return [
            'id' => $z->id,
            'famille' => $z->famille,
            'badge' => $z->badgeClasse(),
            'nom' => $z->nom,
            'num_regional' => $z->num_regional,
            'superficie_ha' => $z->superficie_ha !== null ? Chapeaux::ha($z->superficie_ha) : null,
            'nbCommunes' => (int) $z->nb_communes,
            'maj' => Chapeaux::date($z->date_modif_geo?->toDateString()),
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
     * (dept, recherche ou type) — le catalogue complet de France ne
     * tient pas dans un seul fichier, le CSV complet si.
     */
    private function export(string $format, string $q, ?string $dept, ?string $type): StreamedResponse
    {
        if ($format === 'geojson' && $dept === null && $q === '' && $type === null) {
            abort(400, 'L\'export GeoJSON nécessite un filtre (département, type ou recherche) : le catalogue ZNIEFF complet de la France ne tient pas dans un seul fichier. L\'export CSV complet, lui, est disponible.');
        }

        $entetes = ['id_znieff', 'num_regional', 'type', 'nom', 'superficie_ha', 'communes_nb', 'date_creation', 'date_modif_geometrique', 'fiche_inpn', 'fiche_portail'];
        $nomFichier = Exports::nomFichier('portail-znieff-zonages', $format, $dept);

        if ($format === 'csv') {
            $lignes = (function () use ($q, $dept, $type) {
                foreach ($this->requete($q, $dept, $type)->cursor() as $z) {
                    yield $this->ligneCsv($z);
                }
            })();

            return Exports::csv($nomFichier, $entetes, $lignes);
        }

        $features = (function () use ($q, $dept, $type, $entetes) {
            foreach ($this->requete($q, $dept, $type)->cursor() as $z) {
                yield [
                    'type' => 'Feature',
                    'properties' => array_combine($entetes, $this->ligneCsv($z)),
                    'geometry' => Exports::geometry('zonages/'.$z->famille, $z->id),
                ];
            }
        })();

        return Exports::geojson($nomFichier, $features);
    }

    /**
     * Ligne CSV d'un zonage : faits bruts du référentiel + URL des
     * fiches (INPN et portail).
     *
     * @return array<int, string|null>
     */
    private function ligneCsv(Zonage $z): array
    {
        return [
            $z->id,
            $z->num_regional,
            $z->famille === 'z1' ? 'I' : 'II',
            $z->nom,
            $z->superficie_ha !== null ? Exports::nombre($z->superficie_ha) : null,
            (string) $z->nb_communes,
            $z->date_crea?->toDateString(),
            $z->date_modif_geo?->toDateString(),
            $z->url_inpn,
            route('znieff.show', $z->id),
        ];
    }
}
