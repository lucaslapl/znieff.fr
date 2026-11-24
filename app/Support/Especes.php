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
     * `fiche` : le taxon a-t-il une page publique /espece/{cd_ref} ? Non
     * quand il est sensible dans un AUTRE département (masqué globalement,
     * pas de localisation indirecte) ou absent de taxons_ref : la liste ne
     * rend pas de lien mort.
     *
     * @return array{total: int, page: int, derniere: int, items: array<int, array{cd_ref: int, nom: string, vern: ?string, groupe: string, fiche: bool}>}
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
            ->map(fn ($r) => (object) [
                'cd_ref' => (int) $r->cd_ref,
                'nom' => self::textePur((string) $r->nom_cite),
                'vern' => $r->nom_vernaculaire !== null && $r->nom_vernaculaire !== '' ? (string) $r->nom_vernaculaire : null,
                'groupe' => self::groupe($r->groupe_taxo),
            ])
            ->all();

        // Fiches publiques : une seule requête pour la page (25 cd_ref) —
        // hors taxons sensibles (référentiel SINP, tous départements) et
        // confidentiels ; ces espèces restent listées mais non cliquables.
        $fiches = $items === [] ? [] : array_flip(
            self::requeteVisibles()
                ->whereIn('cd_ref', array_map(fn ($r) => $r->cd_ref, $items))
                ->pluck('cd_ref')
                ->map(fn ($v) => (int) $v)
                ->all(),
        );
        $items = array_map(fn ($r) => (array) $r + ['fiche' => isset($fiches[$r->cd_ref])], $items);

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

    /**
     * Recherche d'espèces visibles par nom (searchbar de l'accueil) :
     * nom valide TAXREF, nom cité ou vernaculaire ZNIEFF, nom du FSD
     * Natura 2000. UNIQUEMENT les taxons de requeteVisibles() — jamais
     * une espèce sensible (SINP v18) ni confidentielle : le filtre absolu
     * est posé ici, côté requête, comme pour toutes les lectures.
     *
     * @return array<int, array{cd_ref: int, nom: string, vern: ?string, groupe: ?string, nbZonages: int, nbSites: int}>
     */
    public static function rechercheEspeces(string $q, int $limite): array
    {
        $terme = '%'.$q.'%';
        $termeMin = '%'.mb_strtolower($q).'%';

        $cdRefs = self::requeteVisibles()
            ->where(function ($w) use ($terme, $termeMin) {
                self::likeInsensible($w, 't.nom_valide', $terme, $termeMin);
                $w->orWhereExists(function ($q2) use ($terme, $termeMin) {
                    $q2->select(DB::raw(1))
                        ->from('znieff_especes as e')
                        ->whereColumn('e.cd_ref', 't.cd_ref')
                        ->where('e.confidentielle', false)
                        ->where(function ($w2) use ($terme, $termeMin) {
                            self::likeInsensible($w2, 'e.nom_cite', $terme, $termeMin);
                            self::likeInsensible($w2, 'e.nom_vernaculaire', $terme, $termeMin);
                        });
                });
                $w->orWhereExists(function ($q2) use ($terme, $termeMin) {
                    $q2->select(DB::raw(1))
                        ->from('natura_especes as n')
                        ->whereColumn('n.cd_ref', 't.cd_ref')
                        ->where(function ($w2) use ($terme, $termeMin) {
                            self::likeInsensible($w2, 'n.nom_scientifique', $terme, $termeMin);
                            self::likeInsensible($w2, 'n.nom_vernaculaire', $terme, $termeMin);
                        });
                });
            })
            ->orderBy('t.nom_valide')
            ->limit($limite)
            ->pluck('t.cd_ref')
            ->map(fn ($cd) => (int) $cd)
            ->all();

        if ($cdRefs === []) {
            return [];
        }

        // Noms vernaculaires (ZNIEFF d'abord, puis FSD Natura) et compteurs
        // (ZNIEFF déterminantes non confidentielles + sites Natura) des taxons trouvés.
        // DISTINCT + ORDER BY : ne trier que sur des colonnes sélectionnées
        // (Postgres l'exige, contrairement à SQLite).
        $verns = DB::table('znieff_especes')
            ->whereIn('cd_ref', $cdRefs)
            ->where('confidentielle', false)
            ->whereNotNull('nom_vernaculaire')
            ->where('nom_vernaculaire', '!=', '')
            ->distinct()
            ->orderBy('cd_ref')
            ->orderBy('nom_vernaculaire')
            ->pluck('nom_vernaculaire', 'cd_ref');
        $vernsNatura = DB::table('natura_especes')
            ->whereIn('cd_ref', $cdRefs)
            ->whereNotNull('nom_vernaculaire')
            ->where('nom_vernaculaire', '!=', '')
            ->distinct()
            ->orderBy('cd_ref')
            ->orderBy('nom_vernaculaire')
            ->pluck('nom_vernaculaire', 'cd_ref');
        $nbZonages = DB::table('znieff_especes')
            ->whereIn('cd_ref', $cdRefs)
            ->where('determinante', true)
            ->where('confidentielle', false)
            ->groupBy('cd_ref')
            ->select('cd_ref', DB::raw('count(distinct zonage_id) as n'))
            ->pluck('n', 'cd_ref');
        $nbSites = DB::table('natura_especes')
            ->whereIn('cd_ref', $cdRefs)
            ->groupBy('cd_ref')
            ->select('cd_ref', DB::raw('count(distinct site_code) as n'))
            ->pluck('n', 'cd_ref');

        return self::requeteVisibles()
            ->whereIn('t.cd_ref', $cdRefs)
            ->orderBy('t.nom_valide')
            ->get(['t.cd_ref', 't.nom_valide', 't.groupe1_inpn', 't.groupe2_inpn'])
            ->map(function ($r) use ($verns, $vernsNatura, $nbZonages, $nbSites) {
                $vern = $verns[$r->cd_ref] ?? $vernsNatura[$r->cd_ref] ?? null;
                $groupe = $r->groupe2_inpn !== null && $r->groupe2_inpn !== ''
                    ? (string) $r->groupe2_inpn
                    : ($r->groupe1_inpn !== null && $r->groupe1_inpn !== '' ? (string) $r->groupe1_inpn : null);

                return [
                    'cd_ref' => (int) $r->cd_ref,
                    'nom' => self::textePur((string) $r->nom_valide),
                    'vern' => $vern !== null ? (string) $vern : null,
                    'groupe' => $groupe,
                    'nbZonages' => (int) ($nbZonages[$r->cd_ref] ?? 0),
                    'nbSites' => (int) ($nbSites[$r->cd_ref] ?? 0),
                ];
            })
            ->all();
    }

    /**
     * Correspondance exacte d'un terme avec une espèce visible → son cd_ref
     * (redirection de la recherche transverse vers /espece/{cd_ref}) :
     * cd_ref numérique d'abord, puis nom valide TAXREF, puis nom
     * vernaculaire/scientifique exact (ZNIEFF puis FSD Natura). Retourne
     * null pour toute espèce sensible (SINP v18) ou confidentielle —
     * jamais de fiche, jamais de redirection vers une espèce non diffusable.
     */
    public static function especeExacte(string $q): ?int
    {
        if (ctype_digit($q)) {
            $cd = (int) $q;

            return self::especeVisible($cd) ? $cd : null;
        }

        $min = mb_strtolower($q);

        // NB : les correspondances nominales sont toujours dans un groupe
        // where() — jamais un orWhere nu sur la requête racine, qui
        // court-circuiterait les whereNotExists de visibilité (précédence
        // AND/OR) et pourrait exposer un taxon sensible.
        $r = self::requeteVisibles()
            ->where(function ($w) use ($q, $min) {
                $w->where('t.nom_valide', $q)->orWhereRaw('LOWER(t.nom_valide) = ?', [$min]);
            })
            ->first(['t.cd_ref']);
        if ($r !== null) {
            return (int) $r->cd_ref;
        }

        $visibles = self::requeteVisibles()->select('t.cd_ref');

        $cd = DB::table('znieff_especes')
            ->where('confidentielle', false)
            ->whereIn('cd_ref', $visibles)
            ->where(function ($w) use ($q, $min) {
                $w->where('nom_vernaculaire', $q)->orWhereRaw('LOWER(nom_vernaculaire) = ?', [$min]);
            })
            ->value('cd_ref');
        if ($cd !== null) {
            return (int) $cd;
        }

        $cd = DB::table('natura_especes')
            ->whereIn('cd_ref', $visibles)
            ->where(function ($w) use ($q, $min) {
                $w->where('nom_vernaculaire', $q)->orWhereRaw('LOWER(nom_vernaculaire) = ?', [$min])
                    ->orWhere('nom_scientifique', $q)->orWhereRaw('LOWER(nom_scientifique) = ?', [$min]);
            })
            ->value('cd_ref');

        return $cd !== null ? (int) $cd : null;
    }

    /**
     * Communes où le taxon est présent (fiche espèce) : communes des ZNIEFF
     * où il est déterminant + communes des sites Natura 2000 qui le citent,
     * dédoublonnées, paginées côté serveur, triées par département puis nom.
     *
     * Filtres absolus posés ici, côté requête : `confidentielle = false`
     * sur znieff_especes ; la sensibilité SINP v18 est déjà exclue en amont
     * par especeVisible() (appelant) — un taxon ayant une fiche publique
     * n'a aucune ligne dans sensibilite_especes, donc aucune commune à
     * masquer départementalement.
     *
     * @return array{total: int, page: int, derniere: int, items: array<int, array{insee: string, slug: string, nom: string, dept: string, reg_nom: ?string}>}
     */
    public static function communesEspecePagine(int $cdRef, int $page, int $parPage): array
    {
        $query = DB::table('communes as c')
            ->where(function ($w) use ($cdRef) {
                $w->whereExists(function ($q) use ($cdRef) {
                    $q->select(DB::raw(1))
                        ->from('znieff_especes as e')
                        ->join('zonage_communes as p', 'p.zonage_id', '=', 'e.zonage_id')
                        ->whereColumn('p.insee', 'c.insee')
                        ->where('e.cd_ref', $cdRef)
                        ->where('e.determinante', true)
                        ->where('e.confidentielle', false);
                })->orWhereExists(function ($q) use ($cdRef) {
                    $q->select(DB::raw(1))
                        ->from('natura_especes as n')
                        ->join('natura_communes as p', 'p.site_code', '=', 'n.site_code')
                        ->whereColumn('p.insee', 'c.insee')
                        ->where('n.cd_ref', $cdRef);
                });
            });

        return self::paginer(
            $query,
            $page,
            $parPage,
            ['c.insee', 'c.slug', 'c.nom', 'c.dept', 'c.reg_nom'],
            fn ($r) => [
                'insee' => (string) $r->insee,
                'slug' => (string) $r->slug,
                'nom' => (string) $r->nom,
                'dept' => (string) $r->dept,
                'reg_nom' => $r->reg_nom !== null ? (string) $r->reg_nom : null,
            ],
            ['c.dept', 'c.nom']
        );
    }

    // ------------------------------------------------------------------
    // Interne
    // ------------------------------------------------------------------

    /**
     * Condition LIKE insensible à la casse quel que soit le moteur :
     * LIKE brut (collation insensible en production MySQL) + LOWER côté
     * SQL (Postgres local ; SQLite des tests ne baisse que l'ASCII).
     * $colonne est un nom de colonne interne, jamais une saisie utilisateur.
     */
    private static function likeInsensible(Builder $query, string $colonne, string $terme, string $termeMin): void
    {
        $query->where($colonne, 'like', $terme)
            ->orWhereRaw('LOWER('.$colonne.') like ?', [$termeMin]);
    }

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
     * La ZNIEFF contient-elle au moins un taxon qu'on ne doit JAMAIS diffuser
     * en intégralité (fiche PDF comprise) ? Filtre posé CÔTÉ REQUÊTE, plus
     * strict que znieffVisibles : sont comptés les taxons `confidentielle`
     * (flag REF_ESPECE) OU présents dans le référentiel de sensibilité SINP
     * v18 (pré-liste nationale cd_dept null + cd_dept des départements de la
     * zone). Sert à la route /znieff/{id}/fiche.pdf : une zone « sensible »
     * ne reçoit jamais sa fiche PDF (les fiches INPN peuvent contenir des
     * localisations précises d'espèces sensibles) — la mention affichée
     * renvoie au SINP (DREAL), seul habilité à délivrer ces données.
     */
    public static function zoneSensible(string $zonageId): bool
    {
        $depts = self::deptsZonage($zonageId);

        return DB::table('znieff_especes as e')
            ->where('e.zonage_id', $zonageId)
            ->where(function ($w) use ($depts) {
                $w->where('e.confidentielle', true)
                    ->orWhereExists(function ($q) use ($depts) {
                        $q->select(DB::raw(1))
                            ->from('sensibilite_especes as s')
                            ->whereColumn('s.cd_ref', 'e.cd_ref')
                            ->where(function ($w2) use ($depts) {
                                $w2->whereNull('s.cd_dept'); // pré-liste nationale
                                if ($depts !== null) {
                                    $w2->orWhereIn('s.cd_dept', $depts);
                                }
                            });
                    });
            })
            ->exists();
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
