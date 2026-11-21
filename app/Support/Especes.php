<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Lecture des données naturalistes INPN pour les fiches (paginées serveur,
 * convention AGENTS.md : jamais de liste complète envoyée au client).
 *
 * RÈGLE ABSOLUE (politique INPN/MNHN, identique à l'Observatoire des ABC) :
 * les espèces ZNIEFF marquées `confidentielle` ne sont JAMAIS affichées ni
 * comptées. Le filtre `where('confidentielle', false)` est posé ICI, côté
 * requête — pas côté affichage — et s'applique aux listes comme aux
 * compteurs. Toute lecture de `znieff_especes` du site passe par cette
 * classe.
 *
 * Les valeurs sont re-mappées en tableaux PHP typés : les fiches Inertia ne
 * reçoivent jamais de modèles.
 */
final class Especes
{
    /**
     * Espèces déterminantes d'une ZNIEFF, paginées, triées par groupe
     * taxonomique puis nom — hors espèces confidentielles (filtre absolu).
     * Filtre optionnel par groupe taxonomique (sous-onglets de la fiche :
     * le filtre reste posé côté serveur, la pagination avec).
     *
     * @return array{total: int, page: int, derniere: int, items: array<int, array{cd_ref: int, nom: string, groupe: string}>}
     */
    public static function especesZonagePagine(string $zonageId, int $page, int $parPage, ?string $groupe = null): array
    {
        $query = self::znieffVisibles()
            ->where('zonage_id', $zonageId)
            ->where('determinante', true)
            ->when($groupe !== null && $groupe !== '' && $groupe !== 'Tous', function ($q) use ($groupe) {
                // « Autres » = groupe_taxo null ou vide en base
                if ($groupe === 'Autres') {
                    $q->where(fn ($w) => $w->whereNull('groupe_taxo')->orWhere('groupe_taxo', ''));
                } else {
                    $q->where('groupe_taxo', $groupe);
                }
            });

        // Une espèce citée peut apparaître sur plusieurs lignes REF_ESPECE :
        // on compte et on liste les cd_ref DISTINCTS (cohérent avec la somme
        // des compteurs des sous-onglets groupesZonage).
        $total = (int) (clone $query)->distinct()->count('cd_ref');
        $derniere = max(1, (int) ceil($total / $parPage));
        $page = min(max(1, $page), $derniere);

        $items = (clone $query)
            ->distinct()
            ->orderBy('groupe_taxo')
            ->orderBy('nom_cite')
            ->forPage($page, $parPage)
            ->get(['cd_ref', 'nom_cite', 'groupe_taxo'])
            ->map(fn ($r) => [
                'cd_ref' => (int) $r->cd_ref,
                'nom' => self::textePur((string) $r->nom_cite),
                'groupe' => self::groupe($r->groupe_taxo),
            ])
            ->all();

        return [
            'total' => $total,
            'page' => $page,
            'derniere' => $derniere,
            'items' => $items,
        ];
    }

    /**
     * Groupes taxonomiques des espèces déterminantes visibles d'une ZNIEFF,
     * avec leur nombre — alimentent les sous-onglets de la fiche.
     *
     * @return array<int, array{groupe: string, n: int}>
     */
    public static function groupesZonage(string $zonageId): array
    {
        return self::znieffVisibles()
            ->where('zonage_id', $zonageId)
            ->where('determinante', true)
            ->groupBy('groupe_taxo')
            ->selectRaw('groupe_taxo, COUNT(DISTINCT cd_ref) AS n')
            ->orderBy('groupe_taxo')
            ->get()
            ->map(fn ($r) => ['groupe' => self::groupe($r->groupe_taxo), 'n' => (int) $r->n])
            ->all();
    }

    /**
     * Espèces des directives (Habitats annexe II / Oiseaux annexe I) d'un
     * site Natura 2000, paginées, triées par nom scientifique.
     *
     * @return array{total: int, page: int, derniere: int, items: array<int, array{code_n2000: string, nom_scientifique: ?string, nom_vernaculaire: ?string, taxgroup: ?string, annexe_ii: bool, annexe_1: bool, prioritaire: bool, presences: ?string}>}
     */
    public static function especesNaturaPagine(string $siteCode, int $page, int $parPage): array
    {
        $query = DB::table('natura_especes')->where('site_code', $siteCode);

        return self::paginer($query, $page, $parPage, [
            'code_n2000', 'nom_scientifique', 'nom_vernaculaire', 'taxgroup',
            'annexe_ii', 'annexe_1', 'prioritaire', 'presences',
        ], function ($r): array {
            return [
                'code_n2000' => (string) $r->code_n2000,
                'nom_scientifique' => $r->nom_scientifique !== null ? (string) $r->nom_scientifique : null,
                'nom_vernaculaire' => $r->nom_vernaculaire !== null && $r->nom_vernaculaire !== '' ? (string) $r->nom_vernaculaire : null,
                'taxgroup' => $r->taxgroup !== null ? (string) $r->taxgroup : null,
                'annexe_ii' => (bool) $r->annexe_ii,
                'annexe_1' => (bool) $r->annexe_1,
                'prioritaire' => (bool) $r->prioritaire,
                'presences' => $r->presences !== null ? (string) $r->presences : null,
            ];
        }, ['nom_scientifique', 'code_n2000']);
    }

    /**
     * Habitats d'intérêt communautaire d'un site Natura 2000, paginés,
     * triés par code EUR.
     *
     * @return array{total: int, page: int, derniere: int, items: array<int, array{cd_ue: string, nom: ?string, prioritaire: bool, cd_hab: ?string, representativite: ?string, conservation: ?string, evaluation_globale: ?string, cover: ?float, area: ?float}>}
     */
    public static function habitatsNaturaPagine(string $siteCode, int $page, int $parPage): array
    {
        $query = DB::table('natura_habitats')->where('site_code', $siteCode);

        return self::paginer($query, $page, $parPage, [
            'cd_ue', 'nom', 'prioritaire', 'cd_hab', 'representativite',
            'conservation', 'evaluation_globale', 'cover', 'area',
        ], function ($r): array {
            return [
                'cd_ue' => (string) $r->cd_ue,
                'nom' => $r->nom !== null && $r->nom !== '' ? (string) $r->nom : null,
                'prioritaire' => (bool) $r->prioritaire,
                'cd_hab' => $r->cd_hab !== null && $r->cd_hab !== '' ? (string) $r->cd_hab : null,
                'representativite' => $r->representativite !== null ? (string) $r->representativite : null,
                'conservation' => $r->conservation !== null ? (string) $r->conservation : null,
                'evaluation_globale' => $r->evaluation_globale !== null ? (string) $r->evaluation_globale : null,
                'cover' => $r->cover !== null ? (float) $r->cover : null,
                'area' => $r->area !== null ? (float) $r->area : null,
            ];
        }, ['cd_ue']);
    }

    /**
     * Compteurs d'une commune (fiche commune) : nombre d'espèces
     * déterminantes par ZNIEFF touchant la commune (hors confidentielles —
     * filtre absolu, comptage compris) et nombres d'espèces / habitats par
     * site Natura 2000.
     *
     * @return array{znieff: array<string, int>, natura_especes: array<string, int>, natura_habitats: array<string, int>}
     */
    public static function compteursCommune(string $insee): array
    {
        $znieff = self::znieffVisibles()
            ->join('zonage_communes as p', 'p.zonage_id', '=', 'znieff_especes.zonage_id')
            ->where('p.insee', $insee)
            ->where('znieff_especes.determinante', true)
            ->groupBy('znieff_especes.zonage_id')
            ->selectRaw('znieff_especes.zonage_id AS zid, COUNT(DISTINCT znieff_especes.cd_ref) AS n')
            ->pluck('n', 'zid')
            ->map(fn ($n) => (int) $n)
            ->all();

        $naturaEsp = DB::table('natura_especes as e')
            ->join('natura_communes as p', 'p.site_code', '=', 'e.site_code')
            ->where('p.insee', $insee)
            ->groupBy('e.site_code')
            ->selectRaw('e.site_code AS sid, COUNT(DISTINCT e.code_n2000) AS n')
            ->pluck('n', 'sid')
            ->map(fn ($n) => (int) $n)
            ->all();

        $naturaHab = DB::table('natura_habitats as h')
            ->join('natura_communes as p', 'p.site_code', '=', 'h.site_code')
            ->where('p.insee', $insee)
            ->groupBy('h.site_code')
            ->selectRaw('h.site_code AS sid, COUNT(DISTINCT h.cd_ue) AS n')
            ->pluck('n', 'sid')
            ->map(fn ($n) => (int) $n)
            ->all();

        return [
            'znieff' => $znieff,
            'natura_especes' => $naturaEsp,
            'natura_habitats' => $naturaHab,
        ];
    }

    // ------------------------------------------------------------------
    // Interne
    // ------------------------------------------------------------------

    /**
     * Requête de base sur les espèces ZNIEFF visibles : le filtre
     * `confidentielle = false` est posé ici, une seule fois, et repris par
     * toutes les méthodes publiques (listes et compteurs).
     */
    private static function znieffVisibles(): Builder
    {
        return DB::table('znieff_especes')->where('confidentielle', false);
    }

    /**
     * Pagination serveur générique (total sur la requête entière, page bornée).
     *
     * @param  array<int, string>  $colonnes
     * @param  array<int, string>  $tri
     */
    private static function paginer(Builder $query, int $page, int $parPage, array $colonnes, callable $mapper, array $tri): array
    {
        $total = (int) (clone $query)->count();
        $derniere = max(1, (int) ceil($total / $parPage));
        $page = min(max(1, $page), $derniere);

        $items = (clone $query)
            ->orderBy($tri[0])
            ->when(isset($tri[1]), fn (Builder $q) => $q->orderBy($tri[1]))
            ->forPage($page, $parPage)
            ->get($colonnes)
            ->map(fn ($r) => $mapper($r))
            ->all();

        return [
            'total' => $total,
            'page' => $page,
            'derniere' => $derniere,
            'items' => $items,
        ];
    }

    /**
     * Nom cité INPN en texte pur : le référentiel contient de l'italique
     * HTML (<i>Otus scops</i>) — en liste on affiche du texte pur.
     */
    private static function textePur(string $nom): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', strip_tags($nom)));
    }

    private static function groupe(?string $groupe): string
    {
        $g = trim((string) $groupe);

        return $g === '' ? 'Autres' : $g;
    }
}
