<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Lecture de l'INPG (inventaire national du patrimoine géologique, INPN/MNHN)
 * pour les fiches et listes du portail — paginées serveur (convention
 * AGENTS.md), jamais de liste complète envoyée au client.
 *
 * RÈGLE ABSOLUE (même politique que les espèces confidentielles) : un site
 * `niv_diffusion = 1` (confidentiel — gisement vulnérable, etc.) n'est
 * JAMAIS affiché ni compté, et sa géométrie n'a jamais été exportée. Le
 * filtre `where('niv_diffusion', 2)` est posé ICI, côté requête — pas côté
 * affichage — et s'applique aux listes comme aux compteurs. Même règle
 * pour les documents : `diff_doc IN (1, 2)` (diffusables), posé ici.
 * Toute lecture des tables inpg_* du site passe par cette classe.
 *
 * Les valeurs sont re-mappées en tableaux PHP typés : les fiches Inertia ne
 * reçoivent jamais de modèles.
 */
final class Inpg
{
    /**
     * Requête de base sur les sites publics : le filtre `niv_diffusion = 2`
     * est posé ici, une seule fois, et repris par toutes les méthodes
     * publiques (listes, fiche, compteurs, sitemap).
     */
    public static function sitesVisibles(): Builder
    {
        return DB::table('inpg_sites')->where('niv_diffusion', 2);
    }

    /**
     * Liste des sites INPG (page /geologie) : recherche par nom ou
     * identifiant métier, filtre département (via le pivot précalculé).
     */
    public static function requeteIndex(?string $q, ?string $dept): Builder
    {
        $query = self::sitesVisibles();
        if ($dept !== null) {
            $query->whereIn('id', function ($s) use ($dept) {
                $s->select('p.site_id')->from('inpg_communes as p')
                    ->join('communes as c', 'c.insee', '=', 'p.insee')
                    ->where('c.dept', $dept);
            });
        }
        if ($q !== null && $q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('nom', 'ilike', '%'.$q.'%')
                    ->orWhere('identifiant_metier', 'ilike', $q.'%');
            });
        }

        return $query;
    }

    /**
     * Un site public par son identifiant métier (NAQ0004…) — clé d'URL de la
     * fiche /geologie/{id}. null si inconnu ou confidentiel (→ 404).
     */
    public static function sitePublic(string $identifiantMetier): ?object
    {
        return self::sitesVisibles()
            ->where('identifiant_metier', strtoupper($identifiantMetier))
            ->first();
    }

    /**
     * Intérêts géologiques d'un site (libellés dénormalisés à l'import).
     *
     * @return array<int, array{interet: string, interet_sup: ?string, justif: ?string}>
     */
    public static function interets(string $siteId): array
    {
        return DB::table('inpg_interets')->where('site_id', $siteId)
            ->orderBy('interet_sup')->orderBy('interet')
            ->get(['interet', 'interet_sup', 'justif'])
            ->map(fn ($r) => [
                'interet' => (string) $r->interet,
                'interet_sup' => $r->interet_sup !== null && $r->interet_sup !== '' ? (string) $r->interet_sup : null,
                'justif' => $r->justif !== null && $r->justif !== '' ? self::textePur((string) $r->justif) : null,
            ])->all();
    }

    /**
     * Zonages réglementaires liés au site (ZNIEFF, SIC/ZSC, PNR, RNN…).
     * Quand la référence est un NM_SFFZN connu de nos zonages, on joint le
     * lien vers la fiche ; idem code Natura FR….
     *
     * @return array<int, array{zonage_ref: string, reference: ?string, commentaire: ?string, lien: ?array{type: string, id: string}}>
     */
    public static function zonageRefs(string $siteId): array
    {
        return DB::table('inpg_zonage_ref')->where('site_id', $siteId)
            ->orderBy('zonage_ref')
            ->get(['zonage_ref', 'reference', 'commentaire'])
            ->map(function ($r) {
                $lien = null;
                $ref = trim((string) ($r->reference ?? ''));
                if ($ref !== '' && preg_match('/^[0-9]{9}$/', $ref) === 1) {
                    if (DB::table('zonages')->where('id', $ref)->exists()) {
                        $lien = ['type' => 'znieff', 'id' => $ref];
                    }
                } elseif ($ref !== '' && preg_match('/^[Ff][Rr][0-9a-zA-Z]{6,10}$/', $ref) === 1) {
                    if (DB::table('natura_sites')->where('code', strtoupper($ref))->exists()) {
                        $lien = ['type' => 'natura', 'id' => strtoupper($ref)];
                    }
                }

                return [
                    'zonage_ref' => (string) $r->zonage_ref,
                    'reference' => $ref !== '' ? $ref : null,
                    'commentaire' => $r->commentaire !== null && $r->commentaire !== '' ? self::textePur((string) $r->commentaire) : null,
                    'lien' => $lien,
                ];
            })->all();
    }

    /**
     * Étages géologiques (stratigraphie) du site, avec âges en Ma.
     *
     * @return array<int, array{strati: string, age_inf: ?float, age_sup: ?float}>
     */
    public static function strati(string $siteId): array
    {
        return DB::table('inpg_strati')->where('site_id', $siteId)
            ->orderBy('age_sup')->orderBy('strati')
            ->get(['strati', 'age_inf', 'age_sup'])
            ->map(fn ($r) => [
                'strati' => (string) $r->strati,
                'age_inf' => $r->age_inf !== null ? (float) $r->age_inf : null,
                'age_sup' => $r->age_sup !== null ? (float) $r->age_sup : null,
            ])->all();
    }

    /**
     * Documents du site AFFICHABLES : filtre `diff_doc IN (1,2)` posé ici,
     * côté requête. Le chemin interne (path_doc) n'est jamais exposé.
     *
     * @return array<int, array{lb_doc: ?string, legende: ?string, type_doc: ?string}>
     */
    public static function documentsVisibles(string $siteId): array
    {
        return DB::table('inpg_documents')->where('site_id', $siteId)
            ->whereIn('diff_doc', [1, 2])
            ->orderBy('type_doc')->orderBy('lb_doc')
            ->get(['lb_doc', 'legende_doc', 'type_doc'])
            ->map(fn ($r) => [
                'lb_doc' => $r->lb_doc !== null && $r->lb_doc !== '' ? (string) $r->lb_doc : null,
                'legende' => $r->legende_doc !== null && $r->legende_doc !== '' ? self::textePur((string) $r->legende_doc) : null,
                'type_doc' => $r->type_doc !== null && $r->type_doc !== '' ? (string) $r->type_doc : null,
            ])->all();
    }

    /** Lieux-dits du site. @return array<int, string> */
    public static function lieudits(string $siteId): array
    {
        return DB::table('inpg_lieudits')->where('site_id', $siteId)
            ->orderBy('lb_lieudit')->pluck('lb_lieudit')->map(fn ($v) => (string) $v)->all();
    }

    /** Notes du site (géologique principale, pédagogique…). */
    public static function notes(string $siteId): array
    {
        return DB::table('inpg_notes')->where('site_id', $siteId)
            ->whereNotNull('note')->where('note', '!=', '')
            ->orderBy('type_note')
            ->get(['type_note', 'note', 'note_pond'])
            ->map(fn ($r) => [
                'type_note' => (string) $r->type_note,
                'note' => self::textePur((string) $r->note),
                'note_pond' => $r->note_pond !== null ? (float) $r->note_pond : null,
            ])->all();
    }

    /** Références bibliographiques du site. @return array<int, string> */
    public static function biblio(string $siteId): array
    {
        return DB::table('inpg_biblio')->where('site_id', $siteId)
            ->where('biblio', '!=', '')
            ->orderBy('biblio')->pluck('biblio')->map(fn ($v) => (string) $v)->all();
    }

    /** Accessibilité (Facile / Difficile / Impossible). @return array<int, string> */
    public static function accessibilites(string $siteId): array
    {
        return DB::table('inpg_accessibilites')->where('site_id', $siteId)
            ->orderBy('accessib')->pluck('accessib')->map(fn ($v) => (string) $v)->all();
    }

    /** Typologies du site (site naturel, anthropique, muséographique…). @return array<int, string> */
    public static function typologies(string $siteId): array
    {
        return DB::table('inpg_typologies')->where('site_id', $siteId)
            ->orderBy('typologie')->pluck('typologie')->map(fn ($v) => (string) $v)->all();
    }

    /** Cartes de référence du site (géologique, IGN, marine). */
    public static function cartes(string $siteId): array
    {
        return DB::table('inpg_cartes')->where('site_id', $siteId)
            ->orderBy('type_carte')->orderBy('carte')
            ->get(['type_carte', 'carte'])
            ->map(fn ($r) => ['type_carte' => (string) $r->type_carte, 'carte' => (string) $r->carte])
            ->all();
    }

    /**
     * Communes du site, paginées serveur (fiche /geologie/{id}).
     *
     * @return array{total: int, page: int, derniere: int, items: array<int, array{slug: string, nom: string, dept: string, surface_ha: ?float, part_site: ?float, part_commune: ?float}>}
     */
    public static function communesSitePagine(string $siteId, int $page, int $parPage): array
    {
        $query = DB::table('inpg_communes as p')
            ->join('communes as c', 'c.insee', '=', 'p.insee')
            ->where('p.site_id', $siteId);

        $total = (int) (clone $query)->count();
        $derniere = max(1, (int) ceil($total / $parPage));
        $page = min(max(1, $page), $derniere);

        $items = (clone $query)
            ->orderByDesc('p.surface_ha')
            ->forPage($page, $parPage)
            ->get(['c.slug', 'c.nom', 'c.dept', 'p.surface_ha', 'p.part_zonage', 'p.part_commune'])
            ->map(fn ($r) => [
                'slug' => (string) $r->slug,
                'nom' => (string) $r->nom,
                'dept' => (string) $r->dept,
                'surface_ha' => $r->surface_ha !== null ? (float) $r->surface_ha : null,
                'part_site' => $r->part_zonage !== null ? (float) $r->part_zonage : null,
                'part_commune' => $r->part_commune !== null ? (float) $r->part_commune : null,
            ])->all();

        return ['total' => $total, 'page' => $page, 'derniere' => $derniere, 'items' => $items];
    }

    /**
     * Sites INPG publics d'une commune (fiche commune), triés par superficie
     * d'intersection décroissante.
     *
     * @return array<int, array{id: string, identifiant_metier: string, nom: string, surface_ha: ?float, part_commune: ?float}>
     */
    public static function sitesCommune(string $insee): array
    {
        return self::sitesVisibles()
            ->join('inpg_communes as p', 'p.site_id', '=', 'inpg_sites.id')
            ->where('p.insee', $insee)
            ->orderByDesc('p.surface_ha')
            ->get(['inpg_sites.id', 'inpg_sites.identifiant_metier', 'inpg_sites.nom', 'p.surface_ha', 'p.part_commune'])
            ->map(fn ($r) => [
                'id' => (string) $r->id,
                'identifiant_metier' => (string) $r->identifiant_metier,
                'nom' => (string) $r->nom,
                'surface_ha' => $r->surface_ha !== null ? (float) $r->surface_ha : null,
                'part_commune' => $r->part_commune !== null ? (float) $r->part_commune : null,
            ])->all();
    }

    /** Nombre de sites INPG publics d'une commune (compteur, filtre ici). */
    public static function compteurCommune(string $insee): int
    {
        return (int) self::sitesVisibles()
            ->join('inpg_communes as p', 'p.site_id', '=', 'inpg_sites.id')
            ->where('p.insee', $insee)
            ->count();
    }

    /**
     * HTML INPN (descr_geol, descr_phys…) : nettoyage côté serveur avant
     * affichage — seules les balises de structure/texte sont conservées,
     * aucun attribut, aucun script.
     */
    public static function htmlAutorise(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }
        // Balises de structure/texte uniquement — aucun lien (le href INPN
        // interne n'est pas repris), aucun attribut, aucun script.
        $autorise = '<p><br><em><i><strong><b><ul><ol><li><sub><sup><blockquote><h3><h4>';
        $nettoye = strip_tags($html, $autorise);
        $nettoye = trim((string) preg_replace('/\s+/u', ' ', $nettoye));

        return $nettoye === '' ? null : $nettoye;
    }

    /** Texte pur (listes) : balises retirées, espaces normalisés. */
    private static function textePur(string $texte): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', strip_tags($texte)));
    }
}
