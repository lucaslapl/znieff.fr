<?php

namespace App\Http\Controllers;

use App\Support\Chapeaux;
use App\Support\Habitats;
use App\Support\Recherche;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Référentiel national des habitats HABREF v7.0 (MNHN) : liste /habitats
 * et fiches /habitat/{cd_hab} (unités HIC du périmètre Natura 2000 +
 * correspondances EUNIS/CORINE + parents).
 *
 * Les espèces caractéristiques affichées passent par
 * App\Support\Habitats::requeteTaxonsVisibles() : filtre absolu côté requête
 * (sensibilité SINP v18, flag confidentiel) — jamais côté affichage.
 */
class HabitatController extends Controller
{
    private const PAR_PAGE = 25;

    /**
     * Liste des habitats importés : recherche par libellé FR ou code,
     * filtre par typologie (7 = EUNIS, 8 = HIC, 22 = CORINE).
     * Pagination serveur obligatoire.
     */
    public function index(Request $request): Response
    {
        $q = Recherche::terme($request->query('q'));
        $typoBrut = $request->query('type');
        $typo = is_string($typoBrut) && isset(Habitats::TYPO_LABEL[(int) $typoBrut]) ? (int) $typoBrut : null;

        /** @var LengthAwarePaginator<object> $pages */
        $pages = Habitats::requeteIndex($q !== '' ? $q : null, $typo)
            ->paginate(self::PAR_PAGE)
            ->appends(array_filter(['q' => $q, 'type' => $typo], fn ($v) => $v !== null && $v !== ''));

        $meta = sprintf(
            'Référentiel national des habitats HABREF (MNHN)%s : %d unités — recherche par libellé ou par code.',
            $typo !== null ? ' — '.Habitats::TYPO_LABEL[$typo] : '',
            $pages->total(),
        );

        return Inertia::render('Habitats/Index', [
            'meta' => [
                'title' => 'Habitats (HABREF)'.($typo !== null ? ' — '.Habitats::TYPO_LABEL[$typo] : ''),
                'description' => Chapeaux::meta($meta),
                'canonical' => route('habitats.index'),
                'noindex' => $q !== '',
            ],
            'typologies' => Habitats::TYPO_LABEL,
            'filtres' => ['q' => $q, 'type' => $typo],
            'habitats' => array_map(fn (object $h): array => Habitats::ligneIndex($h), $pages->items()),
            'pagination' => $this->pagination($pages),
        ]);
    }

    /**
     * Fiche d'une unité HABREF par cd_hab : libellés, hiérarchie,
     * correspondances, espèces caractéristiques (visibles) et sites
     * Natura 2000 du périmètre la citant — listes paginées serveur.
     */
    public function show(Request $request, int $cdHab): Response
    {
        $habitat = Habitats::habitat($cdHab);
        if ($habitat === null) {
            abort(404);
        }

        $pageTaxons = max(1, (int) $request->query('page_taxons', '1'));
        $taxons = Habitats::taxonsHabitatPagine($cdHab, $pageTaxons, self::PAR_PAGE);
        $pageSites = max(1, (int) $request->query('page_sites', '1'));
        $sites = Habitats::sitesHabitatPagine($cdHab, $pageSites, self::PAR_PAGE);

        $chapeau = sprintf(
            '%s %s « %s »%s. %d espèce(s) caractéristique(s) (hors espèces sensibles), %d site(s) Natura 2000 du périmètre.',
            $habitat['typo'],
            $habitat['lb_code'] !== null ? 'code '.$habitat['lb_code'] : 'cd_hab '.$habitat['cd_hab'],
            $habitat['nom_fr'],
            $habitat['nom_en'] !== null ? ' ('.$habitat['nom_en'].')' : '',
            $taxons['total'],
            $sites['total'],
        );

        return Inertia::render('Habitats/Show', [
            'meta' => [
                'title' => sprintf('%s — %s (HABREF)', $habitat['lb_code'] ?? 'cd_hab '.$habitat['cd_hab'], $habitat['nom_fr']),
                'description' => Chapeaux::meta($chapeau),
                'canonical' => route('habitat.show', $cdHab),
            ],
            'habitat' => $habitat,
            'taxons' => $taxons['items'],
            'nbTaxons' => $taxons['total'],
            'paginationTaxons' => $this->paginationFiche('habitat.show', ['cd_hab' => $cdHab], $taxons['page'], self::PAR_PAGE, $taxons['total'], 'page_taxons'),
            'sites' => $sites['items'],
            'nbSites' => $sites['total'],
            'paginationSites' => $this->paginationFiche('habitat.show', ['cd_hab' => $cdHab], $sites['page'], self::PAR_PAGE, $sites['total'], 'page_sites'),
        ]);
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
}
