<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Lecture des données naturalistes INPN pour les fiches (paginées serveur,
 * convention AGENTS.md : jamais de liste complète envoyée au client).
 *
 * RÈGLE ABSOLUE (politique INPN/MNHN, identique à l'Observatoire des ABC) :
 * les espèces ZNIEFF marquées `confidentielle` (flag REF_ESPECE) OU présentes
 * dans le référentiel de sensibilité SINP v18 (`sensibilite_especes`, pour
 * le département de la zone affichée — pré-liste nationale comprise) ne
 * sont JAMAIS affichées ni comptées. Les filtres sont posés ICI, côté
 * requête — pas côté affichage — et s'appliquent aux listes comme aux
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
     * `vern` : nom vernaculaire officiel TAXREF (MNHN) ; null quand le
     * référentiel n'en a pas — la fiche retombe alors sur le latin.
     *
     * @return array{total: int, page: int, derniere: int, items: array<int, array{cd_ref: int, nom: string, vern: ?string, groupe: string}>}
     */
    public static function especesZonagePagine(string $zonageId, int $page, int $parPage, ?string $groupe = null): array
    {
        $query = self::znieffVisibles(self::deptsZonage($zonageId))
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
            ->get(['cd_ref', 'nom_cite', 'nom_vernaculaire', 'groupe_taxo'])
            ->map(fn ($r) => [
                'cd_ref' => (int) $r->cd_ref,
                'nom' => self::textePur((string) $r->nom_cite),
                'vern' => $r->nom_vernaculaire !== null && $r->nom_vernaculaire !== '' ? (string) $r->nom_vernaculaire : null,
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
        return self::znieffVisibles(self::deptsZonage($zonageId))
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
        $dept = DB::table('communes')->where('insee', $insee)->value('dept');
        $depts = is_string($dept) && $dept !== '' ? [$dept] : null;

        $znieff = self::znieffVisibles($depts)
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
    // Fiche espèce (statuts BDC v18 + référentiel TAXREF v18)
    // ------------------------------------------------------------------

    /**
     * Requête des taxons ayant une fiche publique : présents dans taxons_ref,
     * JAMAIS dans le référentiel de sensibilité v18 et jamais flaggés
     * `confidentielle` dans znieff_especes. Une espèce sensible n'a donc
     * aucune page dédiée (pas de localisation indirecte), où que ce soit.
     */
    public static function requeteVisibles(): Builder
    {
        return DB::table('taxons_ref as t')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('sensibilite_especes as s')
                    ->whereColumn('s.cd_ref', 't.cd_ref');
            })
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('znieff_especes as e')
                    ->whereColumn('e.cd_ref', 't.cd_ref')
                    ->where('e.confidentielle', true);
            });
    }

    /** Le taxon a-t-il une fiche publique (/espece/{cd_ref}) ? */
    public static function especeVisible(int $cdRef): bool
    {
        return self::requeteVisibles()->where('t.cd_ref', $cdRef)->exists();
    }

    /**
     * Nom cited / vernaculaire / groupe d'un taxon, repli depuis
     * znieff_especes quand taxons_ref ne le couvre pas (hors périmètre
     * TAXREF). Hors espèces confidentielles.
     *
     * @return array{nom: string, vern: ?string, groupe: string}|null
     */
    public static function nomDepuisZnieff(int $cdRef): ?array
    {
        $r = DB::table('znieff_especes')
            ->where('cd_ref', $cdRef)
            ->where('confidentielle', false)
            ->orderBy('zonage_id')
            ->first(['nom_cite', 'nom_vernaculaire', 'groupe_taxo']);
        if ($r === null) {
            return null;
        }

        return [
            'nom' => self::textePur((string) $r->nom_cite),
            'vern' => $r->nom_vernaculaire !== null && $r->nom_vernaculaire !== '' ? (string) $r->nom_vernaculaire : null,
            'groupe' => self::groupe($r->groupe_taxo),
        ];
    }

    /**
     * Fiche TAXREF v18 d'un taxon (null si hors périmètre importé).
     *
     * @return array{cd_ref: int, nom_valide: ?string, groupe1: ?string, groupe2: ?string, habitat: ?string, presence_fr: ?string, present_om: bool, url_inpn: ?string}|null
     */
    public static function taxonRef(int $cdRef): ?array
    {
        $r = DB::table('taxons_ref')->where('cd_ref', $cdRef)->first();
        if ($r === null) {
            return null;
        }

        return [
            'cd_ref' => (int) $r->cd_ref,
            'nom_valide' => $r->nom_valide !== null && $r->nom_valide !== '' ? (string) $r->nom_valide : null,
            'groupe1' => $r->groupe1_inpn !== null && $r->groupe1_inpn !== '' ? (string) $r->groupe1_inpn : null,
            'groupe2' => $r->groupe2_inpn !== null && $r->groupe2_inpn !== '' ? (string) $r->groupe2_inpn : null,
            'habitat' => $r->habitat_libelle !== null && $r->habitat_libelle !== '' ? (string) $r->habitat_libelle : null,
            'presence_fr' => $r->presence_fr !== null && $r->presence_fr !== '' ? (string) $r->presence_fr : null,
            'present_om' => (bool) $r->present_om,
            'url_inpn' => $r->url_inpn !== null && $r->url_inpn !== '' ? (string) $r->url_inpn : null,
        ];
    }

    /**
     * Statuts de protection/réglementation (BDC v18) d'un taxon, groupés par
     * type de regroupement INPN (Protection, Liste rouge, Directives
     * européennes, Conventions…), triés par territoire puis type.
     *
     * @return array<int, array{regroupement: string, statuts: array<int, array{type: string, type_label: string, code: ?string, label: ?string, territoire: ?string, remarque: ?string, doc_url: ?string}>}>
     */
    public static function statutsEspece(int $cdRef): array
    {
        return DB::table('espece_statuts')
            ->where('cd_ref', $cdRef)
            ->orderBy('regroupement_type')
            ->orderBy('lb_adm_tr')
            ->orderBy('lb_type_statut')
            ->get(['cd_type_statut', 'lb_type_statut', 'regroupement_type', 'code_statut', 'label_statut', 'rq_statut', 'lb_adm_tr', 'doc_url'])
            ->groupBy('regroupement_type')
            ->map(fn ($rows, $regroupement) => [
                'regroupement' => (string) $regroupement,
                'statuts' => $rows->map(fn ($r) => [
                    'type' => (string) $r->cd_type_statut,
                    'type_label' => (string) $r->lb_type_statut,
                    'code' => $r->code_statut !== null && $r->code_statut !== '' ? (string) $r->code_statut : null,
                    'label' => $r->label_statut !== null && $r->label_statut !== '' ? (string) $r->label_statut : null,
                    'territoire' => $r->lb_adm_tr !== null && $r->lb_adm_tr !== '' ? (string) $r->lb_adm_tr : null,
                    'remarque' => $r->rq_statut !== null && $r->rq_statut !== '' ? self::textePur((string) $r->rq_statut) : null,
                    'doc_url' => $r->doc_url !== null && $r->doc_url !== '' ? (string) $r->doc_url : null,
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * ZNIEFF du périmètre où le taxon est déterminant ET visible (hors
     * confidentielles, hors sensibilité v18 des départements de la zone),
     * paginées côté serveur, triées par nom de zone.
     *
     * @return array{total: int, page: int, derniere: int, items: array<int, array{id: string, nom: string, famille: string}>}
     */
    public static function zonagesEspecePagine(int $cdRef, int $page, int $parPage): array
    {
        $query = DB::table('znieff_especes as e')
            ->join('zonages as z', 'z.id', '=', 'e.zonage_id')
            ->where('e.cd_ref', $cdRef)
            ->where('e.determinante', true)
            ->where('e.confidentielle', false)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('sensibilite_especes as s')
                    ->whereColumn('s.cd_ref', 'e.cd_ref')
                    ->where(function ($w) {
                        $w->whereNull('s.cd_dept') // pré-liste nationale
                            ->orWhereIn('s.cd_dept', function ($q2) {
                                $q2->select('c.dept')
                                    ->from('zonage_communes as p')
                                    ->join('communes as c', 'c.insee', '=', 'p.insee')
                                    ->whereColumn('p.zonage_id', 'e.zonage_id');
                            });
                    });
            });

        $total = (int) (clone $query)->distinct()->count('z.id');
        $derniere = max(1, (int) ceil($total / $parPage));
        $page = min(max(1, $page), $derniere);

        $items = (clone $query)
            ->distinct()
            ->orderBy('z.nom')
            ->forPage($page, $parPage)
            ->get(['z.id', 'z.nom', 'z.famille'])
            ->map(fn ($r) => ['id' => (string) $r->id, 'nom' => (string) $r->nom, 'famille' => (string) $r->famille])
            ->all();

        return [
            'total' => $total,
            'page' => $page,
            'derniere' => $derniere,
            'items' => $items,
        ];
    }

    // ------------------------------------------------------------------
    // Interne
    // ------------------------------------------------------------------

    /**
     * Requête de base sur les espèces ZNIEFF visibles : les filtres absolus
     * sont posés ici, une seule fois, et repris par toutes les méthodes
     * publiques (listes et compteurs) :
     *  - `confidentielle = false` (flag REF_ESPECE) ;
     *  - aucune ligne du référentiel de sensibilité SINP v18 pour ce taxon
     *    dans les départements concernés ($depts : cd_dept de la zone, ou
     *    pré-liste nationale cd_dept null). $depts = null → conservateur,
     *    toute sensibilité enregistrée masque l'espèce.
     *
     * @param  array<int, string>|null  $depts  départements de la zone affichée
     */
    private static function znieffVisibles(?array $depts = null): Builder
    {
        return DB::table('znieff_especes')
            ->where('confidentielle', false)
            ->whereNotExists(function ($q) use ($depts) {
                $q->select(DB::raw(1))
                    ->from('sensibilite_especes as s')
                    ->whereColumn('s.cd_ref', 'znieff_especes.cd_ref')
                    ->where(function ($w) use ($depts) {
                        $w->whereNull('s.cd_dept'); // pré-liste nationale
                        if ($depts !== null) {
                            $w->orWhereIn('s.cd_dept', $depts);
                        }
                    });
            });
    }

    /**
     * Départements des communes traversées par une ZNIEFF (pivot précalculé
     * zonage_communes → communes) : la sensibilité d'un taxon s'apprécie au
     * département de la zone affichée. Zone sans commune connue : null
     * (filtre conservateur, toute sensibilité s'applique).
     *
     * @return array<int, string>|null
     */
    private static function deptsZonage(string $zonageId): ?array
    {
        $depts = DB::table('zonage_communes as p')
            ->join('communes as c', 'c.insee', '=', 'p.insee')
            ->where('p.zonage_id', $zonageId)
            ->distinct()
            ->pluck('c.dept')
            ->all();

        return $depts === [] ? null : $depts;
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
