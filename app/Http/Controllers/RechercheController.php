<?php

namespace App\Http\Controllers;

use App\Models\AireProtegee;
use App\Models\Commune;
use App\Models\NaturaSite;
use App\Models\Zonage;
use App\Support\Chapeaux;
use App\Support\Recherche;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Recherche transverse (searchbar de l'accueil, itération d) : un
 * terme peut désigner une commune (nom exact — redirection directe
 * vers sa fiche, la promesse « par commune » du portail), un zonage
 * ZNIEFF (identifiant national ou ancien numéro régional : 8526 →
 * 230009093), un site Natura 2000, une aire protégée ou un
 * département (« Eure », « 27 »). Sinon : page de résultats par
 * section, reprenant les mêmes clauses indexées que les listes.
 *
 * Page fonctionnelle, hors sitemap, noindex (pas de contenu dupliqué
 * pour les moteurs). Aucun calcul spatial : uniquement les pivots.
 */
class RechercheController extends Controller
{
    private const PAR_SECTION = 12;

    public function index(Request $request): Response|RedirectResponse
    {
        $q = Recherche::terme($request->query('q'));

        if ($q === '') {
            return $this->page('', null, [], [], [], [], 0);
        }

        // Correspondance exacte → la fiche (commune d'abord : c'est
        // l'entrée principale du portail).
        $commune = $this->communeExacte($q);
        if ($commune !== null) {
            return redirect()->route('commune.show', $commune->slug);
        }

        if (Recherche::estIdentifiantZnieff($q)) {
            /** @var Zonage|null $zonage */
            $zonage = Zonage::query()
                ->where(fn ($w) => $w->where('id', $q)->orWhere('num_regional', $q))
                ->first(['id']);

            if ($zonage !== null) {
                return redirect()->route('znieff.show', $zonage->id);
            }
        }

        if (Recherche::estCodeInpn($q)) {
            $code = strtoupper($q);

            /** @var NaturaSite|null $site */
            $site = NaturaSite::query()->where('code', $code)->first(['code']);
            if ($site !== null) {
                return redirect()->route('natura.show', $site->code);
            }

            /** @var AireProtegee|null $aire */
            $aire = AireProtegee::query()->where('id', $code)->first(['id']);
            if ($aire !== null) {
                return redirect()->route('ap.show', $aire->id);
            }
        }

        // Résultats par section (mêmes clauses que les listes).
        $dept = Recherche::deptDuTerme($q);

        $communes = $this->communes($q, self::PAR_SECTION + 1);
        $zonages = $this->zonages($q, self::PAR_SECTION + 1);
        $natura = $this->natura($q, self::PAR_SECTION + 1);
        $aires = $this->aires($q, self::PAR_SECTION + 1);

        $total = count($communes) + count($zonages) + count($natura) + count($aires) + ($dept !== null ? 1 : 0);

        return $this->page($q, $dept, $communes, $zonages, $natura, $aires, $total);
    }

    // ------------------------------------------------------------------
    // Sections
    // ------------------------------------------------------------------

    /** Commune dont le slug normalisé correspond exactement au terme. */
    private function communeExacte(string $q): ?Commune
    {
        $slug = Recherche::slug($q);
        if ($slug === '' || is_numeric($slug)) {
            return null;
        }

        /** @var Commune|null $commune */
        $commune = Commune::query()->where('slug', $slug)->first(['slug']);

        return $commune;
    }

    /**
     * Communes correspondantes (nom sans accents ni casse, code INSEE,
     * code postal), triées par nom. Colonnes de la liste complète.
     *
     * @return array<int, array{insee: string, slug: string, nom: string, dept: string, reg_nom: ?string, population: ?string, nbZonages: int, nbNatura: int, nbAp: int, has_abc: bool}>
     */
    private function communes(string $q, int $limite): array
    {
        $slug = Recherche::slug($q);
        $insee = str_replace(' ', '', $q);

        return Commune::query()
            ->withCount([
                'zonages as nb_zonages',
                'naturaSites as nb_natura',
                'airesProtegees as nb_ap',
            ])
            ->where(function ($w) use ($q, $slug, $insee) {
                $w->where('nom', 'like', '%'.$q.'%')
                    ->orWhere('slug', 'like', '%'.$slug.'%')
                    ->orWhere('insee', 'like', $insee.'%')
                    ->orWhere('codes_postaux', 'like', $insee.'%')
                    ->orWhere('codes_postaux', 'like', '%,'.$insee.'%');
            })
            ->orderBy('nom')
            ->limit($limite)
            ->get()
            ->map(fn (Commune $c) => [
                'insee' => $c->insee,
                'slug' => $c->slug,
                'nom' => $c->nom,
                'dept' => $c->dept,
                'reg_nom' => $c->reg_nom,
                'population' => $c->population !== null ? Chapeaux::nombre($c->population, 0) : null,
                'nbZonages' => (int) $c->nb_zonages,
                'nbNatura' => (int) $c->nb_natura,
                'nbAp' => (int) $c->nb_ap,
                'has_abc' => (bool) $c->has_abc,
            ])
            ->all();
    }

    /**
     * ZNIEFF correspondantes (identifiant exact ou nom), par superficie.
     *
     * @return array<int, array{id: string, famille: string, badge: string, nom: string, num_regional: ?string, superficie_ha: ?string, nbCommunes: int, maj: ?string}>
     */
    private function zonages(string $q, int $limite): array
    {
        return Zonage::query()
            ->withCount('communes as nb_communes')
            ->where(function ($w) use ($q) {
                if (Recherche::estIdentifiantZnieff($q)) {
                    $w->where('id', $q)->orWhere('num_regional', $q);
                }
                $w->orWhere('nom', 'like', '%'.$q.'%');
            })
            ->orderByDesc('superficie_ha')
            ->limit($limite)
            ->get()
            ->map(fn (Zonage $z) => [
                'id' => $z->id,
                'famille' => $z->famille,
                'badge' => $z->badgeClasse(),
                'nom' => $z->nom,
                'num_regional' => $z->num_regional,
                'superficie_ha' => $z->superficie_ha !== null ? Chapeaux::ha($z->superficie_ha) : null,
                'nbCommunes' => (int) $z->nb_communes,
                'maj' => Chapeaux::date($z->date_modif_geo?->toDateString()),
            ])
            ->all();
    }

    /**
     * Sites Natura 2000 correspondants (code exact ou nom).
     *
     * @return array<int, array{code: string, type: string, nom: string, superficie_ha: ?string, nbCommunes: int, maj: ?string}>
     */
    private function natura(string $q, int $limite): array
    {
        return NaturaSite::query()
            ->withCount('communes as nb_communes')
            ->where(function ($w) use ($q) {
                if (Recherche::estCodeInpn($q)) {
                    $w->where('code', strtoupper($q));
                }
                $w->orWhere('nom', 'like', '%'.$q.'%');
            })
            ->orderByDesc('superficie_ha')
            ->limit($limite)
            ->get()
            ->map(fn (NaturaSite $s) => [
                'code' => $s->code,
                'type' => strtoupper((string) $s->type),
                'nom' => $s->nom,
                'superficie_ha' => $s->superficie_ha !== null ? Chapeaux::ha($s->superficie_ha) : null,
                'nbCommunes' => (int) $s->nb_communes,
                'maj' => Chapeaux::date($s->date_modif_geo?->toDateString()),
            ])
            ->all();
    }

    /**
     * Aires protégées correspondantes (identifiant exact ou nom).
     *
     * @return array<int, array{id: string, type_code: string, type_nom: ?string, nom: string, superficie_ha: ?string, nbCommunes: int, maj: ?string}>
     */
    private function aires(string $q, int $limite): array
    {
        return AireProtegee::query()
            ->withCount('communes as nb_communes')
            ->where(function ($w) use ($q) {
                if (Recherche::estCodeInpn($q)) {
                    $w->where('id', strtoupper($q));
                }
                $w->orWhere('nom', 'like', '%'.$q.'%');
            })
            ->orderByDesc('superficie_ha')
            ->limit($limite)
            ->get()
            ->map(fn (AireProtegee $a) => [
                'id' => $a->id,
                'type_code' => (string) $a->type_code,
                'type_nom' => (string) $a->type_nom,
                'nom' => $a->nom,
                'superficie_ha' => $a->superficie_ha !== null ? Chapeaux::ha($a->superficie_ha) : null,
                'nbCommunes' => (int) $a->nb_communes,
                'maj' => Chapeaux::date($a->date_modif_geo?->toDateString()),
            ])
            ->all();
    }

    // ------------------------------------------------------------------
    // Rendu
    // ------------------------------------------------------------------

    /**
     * Page de résultats (ou formulaire si terme vide).
     *
     * @param  array<int, array{insee: string, slug: string, nom: string, dept: string, reg_nom: ?string, population: ?string, nbZonages: int, nbNatura: int, nbAp: int, has_abc: bool}>  $communes
     * @param  array<int, array{id: string, famille: string, badge: string, nom: string, num_regional: ?string, superficie_ha: ?string, nbCommunes: int, maj: ?string}>  $zonages
     * @param  array<int, array{code: string, type: string, nom: string, superficie_ha: ?string, nbCommunes: int, maj: ?string}>  $natura
     * @param  array<int, array{id: string, type_code: string, type_nom: string, nom: string, superficie_ha: ?string, nbCommunes: int, maj: ?string}>  $aires
     */
    private function page(
        string $q,
        ?array $dept,
        array $communes,
        array $zonages,
        array $natura,
        array $aires,
        int $total,
    ): Response {
        return Inertia::render('Recherche/Index', [
            'meta' => [
                'title' => $q !== '' ? sprintf('Recherche « %s »', $q) : 'Rechercher sur le portail',
                'description' => 'Rechercher une commune, une ZNIEFF, un site Natura 2000, une aire protégée ou un département — inventaire ZNIEFF et patrimoine naturel par commune.',
                'noindex' => true,
            ],
            'q' => $q,
            'dept' => $dept,
            'communes' => array_slice($communes, 0, self::PAR_SECTION),
            'zonages' => array_slice($zonages, 0, self::PAR_SECTION),
            'natura' => array_slice($natura, 0, self::PAR_SECTION),
            'aires' => array_slice($aires, 0, self::PAR_SECTION),
            // une section qui dépasse 12 résultats : lien vers la liste complète filtrée
            'plus' => [
                'communes' => count($communes) > self::PAR_SECTION,
                'zonages' => count($zonages) > self::PAR_SECTION,
                'natura' => count($natura) > self::PAR_SECTION,
                'aires' => count($aires) > self::PAR_SECTION,
            ],
            'total' => $total,
        ]);
    }
}
