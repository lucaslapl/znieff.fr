<?php

namespace App\Http\Controllers;

use App\Support\Chapeaux;
use App\Support\Inpg;
use App\Support\Recherche;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Patrimoine géologique (INPG, INPN/MNHN) : liste /geologie et fiches
 * /geologie/{identifiant_metier} (ex. NAQ0004).
 *
 * Un site confidentiel (niv_diffusion = 1) n'a JAMAIS de fiche : 404 —
 * le filtre `niv_diffusion = 2` est posé côté requête dans App\Support\Inpg.
 */
class GeologieController extends Controller
{
    private const PAR_PAGE = 25;

    /**
     * Liste des sites INPG : recherche par nom ou identifiant métier,
     * filtre département (via le pivot précalculé). Pagination serveur.
     */
    public function index(Request $request): Response
    {
        $dept = Recherche::dept($request->query('dept'));
        $q = Recherche::terme($request->query('q'));

        /** @var LengthAwarePaginator<object> $pages */
        $pages = Inpg::requeteIndex($q, $dept)
            ->orderBy('nom')
            ->paginate(self::PAR_PAGE)
            ->appends(array_filter(['q' => $q, 'dept' => $dept], fn ($v) => $v !== null && $v !== ''));

        $meta = sprintf(
            'Sites de l\'inventaire national du patrimoine géologique (INPG, INPN/MNHN)%s : %d sites avec leurs communes et leurs intérêts géologiques.',
            $dept !== null ? ' — '.$dept : '',
            $pages->total(),
        );

        return Inertia::render('Geologie/Index', [
            'meta' => [
                'title' => 'Patrimoine géologique (INPG)'.($dept !== null ? sprintf(' — %s (%s)', config('portail.departements')[$dept], $dept) : ''),
                'description' => Chapeaux::meta($meta),
                'canonical' => route('geologie.index').($dept !== null ? '?dept='.$dept : ''),
                'noindex' => $q !== '',
            ],
            'departements' => config('portail.departements'),
            'filtres' => ['q' => $q, 'dept' => $dept],
            'deptNom' => $dept !== null ? config('portail.departements')[$dept] : null,
            'sites' => array_map(fn (object $s): array => [
                'id' => (string) $s->identifiant_metier,
                'nom' => (string) $s->nom,
                'superficie_ha' => $s->superficie_ha !== null ? Chapeaux::ha((float) $s->superficie_ha) : ($s->superficie_saisie !== null ? Chapeaux::ha((float) $s->superficie_saisie).' (saisie)' : null),
                'territoire' => (string) $s->territoire,
                'nb_communes' => (int) DB::table('inpg_communes')->where('site_id', $s->id)->count(),
            ], $pages->items()),
            'pagination' => $this->pagination($pages),
        ]);
    }

    /**
     * Fiche d'un site INPG par identifiant métier (NAQ0004…).
     */
    public function show(Request $request, string $id): Response
    {
        $site = Inpg::sitePublic($id);
        if ($site === null) {
            abort(404); // inconnu ou confidentiel : jamais de fiche
        }

        $page = max(1, (int) $request->query('page', '1'));
        $communes = Inpg::communesSitePagine($site->id, $page, self::PAR_PAGE);

        $superficie = $site->superficie_ha !== null
            ? Chapeaux::ha((float) $site->superficie_ha)
            : ($site->superficie_saisie !== null ? Chapeaux::ha((float) $site->superficie_saisie).' (saisie INPN)' : null);

        $chapeau = sprintf(
            'Site INPG %s « %s »%s : %s. %d commune(s) concernée(s), %d intérêt(s) géologique(s) recensé(s).',
            $site->identifiant_metier,
            $site->nom,
            $site->territoire !== 'metrop' ? ' ('.$site->territoire.')' : '',
            $superficie !== null ? 'Superficie '.$superficie : 'Superficie non renseignée',
            $communes['total'],
            count(Inpg::interets($site->id)),
        );

        return Inertia::render('Geologie/Show', [
            'meta' => [
                'title' => sprintf('%s — %s (INPG)', $site->identifiant_metier, $site->nom),
                'description' => Chapeaux::meta($chapeau),
                'canonical' => route('geologie.show', $site->identifiant_metier),
            ],
            'site' => [
                'id' => (string) $site->id,
                'identifiant_metier' => (string) $site->identifiant_metier,
                'nom' => (string) $site->nom,
                'superficie_ha' => $superficie,
                'unite_superficie' => $site->unite_superficie !== null && $site->unite_superficie !== '' ? (string) $site->unite_superficie : null,
                'justif_superficie' => $site->justif_superficie !== null && $site->justif_superficie !== '' ? (string) $site->justif_superficie : null,
                'etat' => $site->etat !== null && $site->etat !== '' ? (string) $site->etat : null,
                'present_succincte' => $site->present_succincte !== null && $site->present_succincte !== '' ? (string) $site->present_succincte : null,
                'descr_phys' => Inpg::htmlAutorise($site->descr_phys !== null ? (string) $site->descr_phys : null),
                'descr_geol' => Inpg::htmlAutorise($site->descr_geol !== null ? (string) $site->descr_geol : null),
                'itineraire' => $site->itineraire !== null && $site->itineraire !== '' ? (string) $site->itineraire : null,
                'gilges' => $site->gilges !== null && $site->gilges !== '' ? (string) $site->gilges : null,
                'phenom_geol' => $site->phenom_geol !== null && $site->phenom_geol !== '' ? (string) $site->phenom_geol : null,
                'interet_hist_geol' => $site->interet_hist_geol !== null && $site->interet_hist_geol !== '' ? (string) $site->interet_hist_geol : null,
                'rarete' => $site->rarete !== null && $site->rarete !== '' ? (string) $site->rarete : null,
                'comment_eval' => $site->comment_eval !== null && $site->comment_eval !== '' ? (string) $site->comment_eval : null,
                'vuln_nat' => $site->vuln_nat !== null && $site->vuln_nat !== '' ? (string) $site->vuln_nat : null,
                'menace_anthrop' => $site->menace_anthrop !== null && $site->menace_anthrop !== '' ? (string) $site->menace_anthrop : null,
                'suivi_protec' => $site->suivi_protec !== null && $site->suivi_protec !== '' ? (string) $site->suivi_protec : null,
                'comment_gnrl_menace' => $site->comment_gnrl_menace !== null && $site->comment_gnrl_menace !== '' ? (string) $site->comment_gnrl_menace : null,
                'date_crea_site' => $site->date_crea_site !== null ? Chapeaux::date((string) $site->date_crea_site) : null,
                'date_prem_visite' => $site->date_prem_visite !== null ? Chapeaux::date((string) $site->date_prem_visite) : null,
                'date_der_visite' => $site->date_der_visite !== null ? Chapeaux::date((string) $site->date_der_visite) : null,
                'date_modif' => $site->date_modif !== null ? Chapeaux::date((string) $site->date_modif) : null,
                'ref_carto' => $site->ref_carto !== null && $site->ref_carto !== '' ? (string) $site->ref_carto : null,
                'territoire' => (string) $site->territoire,
                'url_inpn' => $site->url_inpn !== null ? (string) $site->url_inpn : null,
                'geojson' => '/geo/inpg/'.$site->id.'.geojson',
            ],
            'chapeau' => $chapeau,
            'interets' => Inpg::interets($site->id),
            'zonageRefs' => Inpg::zonageRefs($site->id),
            'strati' => Inpg::strati($site->id),
            'documents' => Inpg::documentsVisibles($site->id),
            'lieudits' => Inpg::lieudits($site->id),
            'notes' => Inpg::notes($site->id),
            'biblio' => Inpg::biblio($site->id),
            'accessibilites' => Inpg::accessibilites($site->id),
            'typologies' => Inpg::typologies($site->id),
            'cartes' => Inpg::cartes($site->id),
            'communes' => $communes['items'],
            'nbCommunes' => $communes['total'],
            'pagination' => $this->paginationFiche('geologie.show', ['id' => $site->identifiant_metier], $communes['page'], self::PAR_PAGE, $communes['total']),
        ]);
    }

    /**
     * @param  LengthAwarePaginator<object>  $pages
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
