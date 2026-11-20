<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lecture des croisements précalculés pour les fiches (uniquement les
 * pivots par code INSEE — jamais de calcul spatial en ligne).
 *
 * Toutes les valeurs sont re-mappées en tableaux PHP typés : les fiches
 * Inertia ne reçoivent jamais de modèles ni d'objets dynamiques.
 */
final class Croisements
{
    /**
     * Zonages ZNIEFF touchant une commune, triés par part du territoire
     * communal couvert (décroissant) — les grandes ZNIEFF II d'abord.
     *
     * @return array<int, array{id: string, famille: string, nom: string, num_regional: ?string, superficie_ha: ?float, surface_ha: float, part_zonage: float, part_commune: float}>
     */
    public static function zonagesCommune(string $insee): array
    {
        return self::rows(
            'zonage_communes as p',
            'zonages as z',
            'z.id',
            'p.zonage_id',
            $insee,
            ['z.id', 'z.famille', 'z.nom', 'z.num_regional', 'z.superficie_ha'],
            'p.part_commune'
        )->map(fn ($r) => [
            'id' => (string) $r->id,
            'famille' => (string) $r->famille,
            'nom' => (string) $r->nom,
            'num_regional' => $r->num_regional !== null ? (string) $r->num_regional : null,
            'superficie_ha' => $r->superficie_ha !== null ? (float) $r->superficie_ha : null,
            'surface_ha' => (float) $r->p_surface_ha,
            'part_zonage' => (float) $r->part_zonage,
            'part_commune' => (float) $r->part_commune,
        ])->all();
    }

    /**
     * Sites Natura 2000 touchant une commune.
     *
     * @return array<int, array{code: string, type: string, nom: string, superficie_ha: ?float, surface_ha: float, part_zonage: float, part_commune: float}>
     */
    public static function naturaCommune(string $insee): array
    {
        return self::rows(
            'natura_communes as p',
            'natura_sites as z',
            'z.code',
            'p.site_code',
            $insee,
            ['z.code', 'z.type', 'z.nom', 'z.superficie_ha'],
            'p.part_commune'
        )->map(fn ($r) => [
            'code' => (string) $r->code,
            'type' => (string) $r->type,
            'nom' => (string) $r->nom,
            'superficie_ha' => $r->superficie_ha !== null ? (float) $r->superficie_ha : null,
            'surface_ha' => (float) $r->p_surface_ha,
            'part_zonage' => (float) $r->part_zonage,
            'part_commune' => (float) $r->part_commune,
        ])->all();
    }

    /**
     * Aires protégées touchant une commune.
     *
     * @return array<int, array{id: string, type_code: string, type_nom: ?string, nom: string, superficie_ha: ?float, surface_ha: float, part_zonage: float, part_commune: float}>
     */
    public static function airesCommune(string $insee): array
    {
        return self::rows(
            'ap_communes as p',
            'aires_protegees as z',
            'z.id',
            'p.ap_id',
            $insee,
            ['z.id', 'z.type_code', 'z.type_nom', 'z.nom', 'z.superficie_ha'],
            'p.part_commune'
        )->map(fn ($r) => [
            'id' => (string) $r->id,
            'type_code' => (string) $r->type_code,
            'type_nom' => $r->type_nom !== null && $r->type_nom !== '' ? (string) $r->type_nom : null,
            'nom' => (string) $r->nom,
            'superficie_ha' => $r->superficie_ha !== null ? (float) $r->superficie_ha : null,
            'surface_ha' => (float) $r->p_surface_ha,
            'part_zonage' => (float) $r->part_zonage,
            'part_commune' => (float) $r->part_commune,
        ])->all();
    }

    /**
     * Communes d'un zonage ZNIEFF, triées par surface d'intersection
     * décroissante (table de croisement de la fiche).
     *
     * @return array<int, array{insee: string, slug: string, nom: string, dept: string, surface_ha: float, part_zonage: float, part_commune: float}>
     */
    public static function communesZonage(string $zonageId): array
    {
        return self::communes('zonage_communes', 'zonage_id', $zonageId);
    }

    /** Communes d'un site Natura 2000. */
    public static function communesNatura(string $code): array
    {
        return self::communes('natura_communes', 'site_code', $code);
    }

    /** Communes d'une aire protégée. */
    public static function communesAire(string $id): array
    {
        return self::communes('ap_communes', 'ap_id', $id);
    }

    /**
     * Communes d'un zonage ZNIEFF, paginées : la liste complète n'est
     * jamais envoyée dans les props d'une fiche (convention « pagination
     * serveur », AGENTS.md). Le chapeau a besoin du total et de la commune
     * la plus couverte — calculés sur le périmètre entier, hors page.
     *
     * @return array{total: int, page: int, derniere: int, items: array<int, array{insee: string, slug: string, nom: string, dept: string, surface_ha: float, part_zonage: float, part_commune: float}>, principale: ?array{nom: string, part_commune: float}}
     */
    public static function communesZonagePagine(string $zonageId, int $page, int $parPage): array
    {
        return self::communesPaginees('zonage_communes', 'zonage_id', $zonageId, $page, $parPage);
    }

    /** Communes d'un site Natura 2000, paginées (même contrat). */
    public static function communesNaturaPagine(string $code, int $page, int $parPage): array
    {
        return self::communesPaginees('natura_communes', 'site_code', $code, $page, $parPage);
    }

    /** Communes d'une aire protégée, paginées (même contrat). */
    public static function communesAirePagine(string $id, int $page, int $parPage): array
    {
        return self::communesPaginees('ap_communes', 'ap_id', $id, $page, $parPage);
    }

    // ------------------------------------------------------------------
    // Interne
    // ------------------------------------------------------------------

    /**
     * Lignes d'un pivot (côté commune), joined à la table des zonages,
     * triées par colonne décroissante.
     *
     * @param  array<int, string>  $colonnes
     * @return Collection<int, \stdClass>
     */
    private static function rows(string $pivot, string $table, string $idCol, string $pivotIdCol, string $insee, array $colonnes, string $tri)
    {
        return DB::table($pivot)
            ->join($table, $idCol, '=', $pivotIdCol)
            ->where('p.insee', $insee)
            ->orderByDesc($tri)
            ->get(array_merge($colonnes, [
                DB::raw('p.surface_ha as p_surface_ha'),
                'p.part_zonage',
                'p.part_commune',
            ]));
    }

    /**
     * @return array<int, array{insee: string, slug: string, nom: string, dept: string, surface_ha: float, part_zonage: float, part_commune: float}>
     */
    private static function communes(string $pivot, string $idCol, string $id): array
    {
        return self::communesQuery($pivot, $idCol, $id)
            ->get(['c.insee', 'c.slug', 'c.nom', 'c.dept', 'p.surface_ha', 'p.part_zonage', 'p.part_commune'])
            ->map(fn ($r) => self::ligneCommune($r))
            ->all();
    }

    /**
     * Version paginée de {@see communes()} : total, page bornée
     * (au-delà de la dernière → dernière page) et commune la plus
     * couverte calculées sur le périmètre entier.
     *
     * @return array{total: int, page: int, derniere: int, items: array<int, array{insee: string, slug: string, nom: string, dept: string, surface_ha: float, part_zonage: float, part_commune: float}>, principale: ?array{nom: string, part_commune: float}}
     */
    private static function communesPaginees(string $pivot, string $idCol, string $id, int $page, int $parPage): array
    {
        $total = (int) DB::table($pivot)->where($idCol, $id)->count();
        $derniere = max(1, (int) ceil($total / $parPage));
        $page = min(max(1, $page), $derniere);

        $items = self::communesQuery($pivot, $idCol, $id)
            ->forPage($page, $parPage)
            ->get(['c.insee', 'c.slug', 'c.nom', 'c.dept', 'p.surface_ha', 'p.part_zonage', 'p.part_commune'])
            ->map(fn ($r) => self::ligneCommune($r))
            ->all();

        $principale = self::communesQuery($pivot, $idCol, $id)
            ->reorder('p.part_commune', 'desc')
            ->first(['c.nom', 'p.part_commune']);

        return [
            'total' => $total,
            'page' => $page,
            'derniere' => $derniere,
            'items' => $items,
            'principale' => $principale !== null
                ? ['nom' => (string) $principale->nom, 'part_commune' => (float) $principale->part_commune]
                : null,
        ];
    }

    /**
     * Lignes d'un pivot côté communes, triées par surface d'intersection
     * décroissante —base commune des variantes complètes et paginées.
     *
     * @return Builder
     */
    private static function communesQuery(string $pivot, string $idCol, string $id)
    {
        return DB::table($pivot.' as p')
            ->join('communes as c', 'c.insee', '=', 'p.insee')
            ->where('p.'.$idCol, $id)
            ->orderByDesc('p.surface_ha');
    }

    /**
     * @return array{insee: string, slug: string, nom: string, dept: string, surface_ha: float, part_zonage: float, part_commune: float}
     */
    private static function ligneCommune(\stdClass $r): array
    {
        return [
            'insee' => (string) $r->insee,
            'slug' => (string) $r->slug,
            'nom' => (string) $r->nom,
            'dept' => (string) $r->dept,
            'surface_ha' => (float) $r->surface_ha,
            'part_zonage' => (float) $r->part_zonage,
            'part_commune' => (float) $r->part_commune,
        ];
    }
}
