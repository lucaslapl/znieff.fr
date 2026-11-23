<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Lecture du référentiel HABREF v7.0 (MNHN) pour les fiches habitats.
 *
 * RÈGLE ABSOLUE (identique à App\Support\Especes) : les espèces
 * caractéristiques d'un habitat qui sont dans le référentiel de sensibilité
 * SINP v18 (pré-liste nationale comprise — filtre conservateur sans
 * territoire, comme les fiches espèces) ou marquées `confidentielle` ne sont
 * JAMAIS affichées ni comptées. Le filtre est posé ICI, côté requête.
 * Un taxon sans cd_ref résolu (hors TAXREF) est exclu aussi (conservateur).
 *
 * Les valeurs sont re-mappées en tableaux PHP typés : les fiches Inertia ne
 * reçoivent jamais de modèles.
 */
final class Habitats
{
    /** Libellés des typologies retenues à l'import. */
    public const TYPO_LABEL = [
        7 => 'EUNIS 2012',
        8 => 'HIC — Natura 2000 (EUR)',
        22 => 'CORINE Biotopes',
    ];

    /**
     * Requête des espèces caractéristiques VISIBLES d'un habitat :
     * joint taxons_ref (nom valide TAXREF) + filtre absolu côté requête
     * (sensibilité SINP v18, tout cd_dept — conservateur ; flag confidentiel).
     */
    public static function requeteTaxonsVisibles(): Builder
    {
        return DB::table('habref_taxons as ht')
            ->join('taxons_ref as t', 't.cd_ref', '=', 'ht.cd_ref')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('sensibilite_especes as s')
                    ->whereColumn('s.cd_ref', 'ht.cd_ref');
            })
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('znieff_especes as e')
                    ->whereColumn('e.cd_ref', 'ht.cd_ref')
                    ->where('e.confidentielle', true);
            });
    }

    /**
     * Espèces caractéristiques visibles d'un habitat, paginées serveur,
     * triées par type de relation puis nom valide.
     *
     * @return array{total: int, page: int, derniere: int, items: array<int, array{cd_ref: int, nom: string, vern: ?string, type: string}>}
     */
    public static function taxonsHabitatPagine(int $cdHab, int $page, int $parPage): array
    {
        $base = self::requeteTaxonsVisibles()
            ->leftJoin('znieff_especes as e', 'e.cd_ref', '=', 'ht.cd_ref')
            ->where('ht.cd_hab', $cdHab);

        $total = (int) (clone $base)->distinct()->count('ht.cd_ref');
        $derniere = max(1, (int) ceil($total / $parPage));
        $page = min(max(1, $page), $derniere);

        $items = (clone $base)
            ->groupBy('ht.cd_ref', 't.nom_valide', 'ht.type_libelle')
            ->select('ht.cd_ref', 't.nom_valide', 'ht.type_libelle', DB::raw('MAX(e.nom_vernaculaire) as nom_vernaculaire'))
            ->orderBy('ht.type_libelle')
            ->orderBy('t.nom_valide')
            ->forPage($page, $parPage)
            ->get()
            ->map(fn ($r) => [
                'cd_ref' => (int) $r->cd_ref,
                'nom' => (string) $r->nom_valide,
                'vern' => $r->nom_vernaculaire !== null && $r->nom_vernaculaire !== '' ? (string) $r->nom_vernaculaire : null,
                'type' => (string) $r->type_libelle,
            ])
            ->all();

        return ['total' => $total, 'page' => $page, 'derniere' => $derniere, 'items' => $items];
    }

    /**
     * Fiche d'une unité HABREF : libellés, hiérarchie (parent/enfants),
     * correspondances vers les autres unités importées.
     *
     * @return array{cd_hab: int, cd_typo: int, typo: string, lb_code: ?string, nom_fr: string, nom_fr_complet: ?string, nom_en: ?string, niveau: ?int, description: ?string, territoires: ?string, france: bool, url_inpn: string, parent: ?array{cd_hab: int, nom_fr: string}, enfants: array<int, array{cd_hab: int, nom_fr: string}>, corresp: array<int, array{cd_hab: int, cd_typo: int, typo: string, lb_code: ?string, nom_fr: string}>}|null
     */
    public static function habitat(int $cdHab): ?array
    {
        $h = DB::table('habref_habitats')->where('cd_hab', $cdHab)->first();
        if ($h === null) {
            return null;
        }

        $parent = null;
        if ($h->cd_hab_sup !== null) {
            $p = DB::table('habref_habitats')->where('cd_hab', $h->cd_hab_sup)->first(['cd_hab', 'nom_fr']);
            if ($p !== null) {
                $parent = ['cd_hab' => (int) $p->cd_hab, 'nom_fr' => (string) $p->nom_fr];
            }
        }

        $enfants = DB::table('habref_habitats')
            ->where('cd_hab_sup', $cdHab)
            ->orderBy('lb_code')
            ->get(['cd_hab', 'nom_fr'])
            ->map(fn ($e) => ['cd_hab' => (int) $e->cd_hab, 'nom_fr' => (string) $e->nom_fr])
            ->all();

        $corresp = DB::table('habref_corresp as c')
            ->join('habref_habitats as h', function ($j) {
                $j->on('h.cd_hab', '=', 'c.cd_hab_sortie')->orOn('h.cd_hab', '=', 'c.cd_hab_entre');
            })
            ->where(function ($w) use ($cdHab) {
                $w->where('c.cd_hab_entre', $cdHab)->orWhere('c.cd_hab_sortie', $cdHab);
            })
            ->where('h.cd_hab', '!=', $cdHab)
            ->distinct()
            ->orderBy('h.cd_typo')
            ->get(['h.cd_hab', 'h.cd_typo', 'h.lb_code', 'h.nom_fr'])
            ->map(fn ($c) => [
                'cd_hab' => (int) $c->cd_hab,
                'cd_typo' => (int) $c->cd_typo,
                'typo' => self::TYPO_LABEL[(int) $c->cd_typo] ?? 'Typo '.$c->cd_typo,
                'lb_code' => $c->lb_code !== null ? (string) $c->lb_code : null,
                'nom_fr' => (string) $c->nom_fr,
            ])
            ->all();

        return [
            'cd_hab' => (int) $h->cd_hab,
            'cd_typo' => (int) $h->cd_typo,
            'typo' => self::TYPO_LABEL[(int) $h->cd_typo] ?? 'Typo '.$h->cd_typo,
            'lb_code' => $h->lb_code !== null ? (string) $h->lb_code : null,
            'nom_fr' => (string) $h->nom_fr,
            'nom_fr_complet' => $h->nom_fr_complet !== null ? (string) $h->nom_fr_complet : null,
            'nom_en' => $h->nom_en !== null ? (string) $h->nom_en : null,
            'niveau' => $h->niveau !== null ? (int) $h->niveau : null,
            'description' => $h->description !== null ? (string) $h->description : null,
            'territoires' => $h->territoires !== null ? (string) $h->territoires : null,
            'france' => (bool) $h->france,
            'url_inpn' => (string) config('portail.urls.habitat_inpn').$h->cd_hab,
            'parent' => $parent,
            'enfants' => $enfants,
            'corresp' => $corresp,
        ];
    }

    /**
     * Habitats (unités importées) où un taxon est espèce caractéristique —
     * pour la fiche espèce (déjà filtrée : l'espèce affichée est visible).
     *
     * @return array{total: int, page: int, derniere: int, items: array<int, array{cd_hab: int, cd_typo: int, typo: string, lb_code: ?string, nom_fr: string, type: string}>}
     */
    public static function habitatsEspecePagine(int $cdRef, int $page, int $parPage): array
    {
        $query = DB::table('habref_taxons as ht')
            ->join('habref_habitats as h', 'h.cd_hab', '=', 'ht.cd_hab')
            ->where('ht.cd_ref', $cdRef)
            ->select('h.cd_hab', 'h.cd_typo', 'h.lb_code', 'h.nom_fr', 'ht.type_libelle');

        $total = (int) (clone $query)->distinct()->count('h.cd_hab');
        $derniere = max(1, (int) ceil($total / $parPage));
        $page = min(max(1, $page), $derniere);

        $items = (clone $query)
            ->distinct()
            ->orderBy('h.cd_typo')
            ->orderBy('h.lb_code')
            ->forPage($page, $parPage)
            ->get()
            ->map(fn ($r) => [
                'cd_hab' => (int) $r->cd_hab,
                'cd_typo' => (int) $r->cd_typo,
                'typo' => self::TYPO_LABEL[(int) $r->cd_typo] ?? 'Typo '.$r->cd_typo,
                'lb_code' => $r->lb_code !== null ? (string) $r->lb_code : null,
                'nom_fr' => (string) $r->nom_fr,
                'type' => (string) $r->type_libelle,
            ])
            ->all();

        return ['total' => $total, 'page' => $page, 'derniere' => $derniere, 'items' => $items];
    }

    /**
     * Sites Natura 2000 du périmètre dont un habitat FSD a pour cd_hab
     * l'unité affichée ou une unité en correspondance directe avec elle,
     * paginés serveur.
     *
     * @return array{total: int, page: int, derniere: int, items: array<int, array{code: string, nom: string, type: string}>}
     */
    public static function sitesHabitatPagine(int $cdHab, int $page, int $parPage): array
    {
        $cdHabs = [$cdHab];
        foreach (DB::table('habref_corresp')->where('cd_hab_entre', $cdHab)->pluck('cd_hab_sortie') as $s) {
            $cdHabs[] = (int) $s;
        }
        foreach (DB::table('habref_corresp')->where('cd_hab_sortie', $cdHab)->pluck('cd_hab_entre') as $e) {
            $cdHabs[] = (int) $e;
        }
        $cdHabs = array_values(array_unique($cdHabs));

        $query = DB::table('natura_sites as n')
            ->whereIn('n.code', function ($q) use ($cdHabs) {
                $q->select('site_code')->from('natura_habitats')->whereIn('cd_hab', $cdHabs)->distinct();
            });

        $total = (int) (clone $query)->count();
        $derniere = max(1, (int) ceil($total / $parPage));
        $page = min(max(1, $page), $derniere);

        $items = (clone $query)
            ->orderBy('n.code')
            ->forPage($page, $parPage)
            ->get(['n.code', 'n.nom', 'n.type'])
            ->map(fn ($r) => ['code' => (string) $r->code, 'nom' => (string) $r->nom, 'type' => (string) $r->type])
            ->all();

        return ['total' => $total, 'page' => $page, 'derniere' => $derniere, 'items' => $items];
    }

    /**
     * Index des habitats importés : recherche sur le libellé FR ou le code,
     * filtre par typologie (7/8/22).
     */
    public static function requeteIndex(?string $q, ?int $typo): Builder
    {
        $query = DB::table('habref_habitats')
            ->when($typo !== null, fn ($w) => $w->where('cd_typo', $typo))
            ->when($q !== null && $q !== '', function ($w) use ($q) {
                $w->where(function ($w2) use ($q) {
                    $w2->where('nom_fr', 'like', '%'.$q.'%')
                        ->orWhere('lb_code', 'like', $q.'%');
                });
            })
            ->orderBy('cd_typo')
            ->orderBy('lb_code');

        return $query;
    }

    /** Ligne d'index d'un habitat. */
    public static function ligneIndex(object $h): array
    {
        return [
            'cd_hab' => (int) $h->cd_hab,
            'cd_typo' => (int) $h->cd_typo,
            'typo' => self::TYPO_LABEL[(int) $h->cd_typo] ?? 'Typo '.$h->cd_typo,
            'lb_code' => $h->lb_code !== null ? (string) $h->lb_code : null,
            'nom_fr' => (string) $h->nom_fr,
        ];
    }
}
