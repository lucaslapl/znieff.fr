<?php

namespace App\Http\Controllers;

use App\Support\Chapeaux;
use App\Support\Recherche;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Organismes adhérents au SINP (référentiel INPN des producteurs de
 * données) : liste /organismes avec recherche par libellé et filtre par
 * domaine d'intervention. Pagination serveur obligatoire.
 *
 * Les coordonnées personnelles (mail, téléphone, adresse) ne sont jamais
 * importées — voir la migration de la table `organismes`.
 */
class OrganismeController extends Controller
{
    private const PAR_PAGE = 25;

    /** Domaines d'intervention proposés en filtre (les plus fréquents). */
    private const DOMAINES = ['Faune', 'Flore', 'Habitats', 'Fonge', 'Géologie', 'Milieux marins'];

    public function index(Request $request): Response
    {
        $q = Recherche::terme($request->query('q'));
        $domaine = $request->query('domaine');
        $domaine = is_string($domaine) && in_array($domaine, self::DOMAINES, true) ? $domaine : null;

        /** @var LengthAwarePaginator<object> $pages */
        $pages = DB::table('organismes')
            ->when($q !== '', function ($w) use ($q) {
                // Insensible à la casse, portable Postgres/SQLite (tests) :
                // LOWER(...) LIKE, jamais « ilike » (inconnu de SQLite).
                $like = '%'.mb_strtolower($q).'%';
                $w->where(function ($w2) use ($like) {
                    $w2->whereRaw('LOWER(libelle_court) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(libelle_long) LIKE ?', [$like]);
                });
            })
            ->when($domaine !== null, fn ($w) => $w->where('domaines', 'like', '%'.$domaine.'%'))
            ->orderBy('libelle_court')
            ->paginate(self::PAR_PAGE)
            ->appends(array_filter(['q' => $q, 'domaine' => $domaine], fn ($v) => $v !== null && $v !== ''));

        $meta = sprintf(
            'Organismes adhérents au Système d\'Information sur la Nature et les Paysages (SINP, INPN) : %d producteurs de données — recherche par libellé, filtre par domaine.',
            $pages->total(),
        );

        return Inertia::render('Organismes/Index', [
            'meta' => [
                'title' => 'Organismes adhérents au SINP (producteurs de données)',
                'description' => Chapeaux::meta($meta),
                'canonical' => route('organismes.index'),
                'noindex' => $q !== '' || $domaine !== null,
            ],
            'domaines' => self::DOMAINES,
            'filtres' => ['q' => $q, 'domaine' => $domaine],
            'organismes' => array_map(fn (object $o): array => [
                'id' => (string) $o->id,
                'libelle_court' => (string) $o->libelle_court,
                'libelle_long' => $o->libelle_long !== null ? (string) $o->libelle_long : null,
                'domaines' => $o->domaines !== null ? (string) $o->domaines : null,
                'zones' => $o->zones !== null ? (string) $o->zones : null,
                'niveau_adhesion' => $o->niveau_adhesion !== null ? (string) $o->niveau_adhesion : null,
                'statut_organisme' => $o->statut_organisme !== null ? (string) $o->statut_organisme : null,
                'type_organisme' => $o->type_organisme !== null ? (string) $o->type_organisme : null,
                'url' => $o->url !== null ? (string) $o->url : null,
                'date_adhesion' => $o->date_adhesion !== null ? Chapeaux::date(substr((string) $o->date_adhesion, 0, 10)) : null,
            ], $pages->items()),
            'pagination' => $this->pagination($pages),
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
