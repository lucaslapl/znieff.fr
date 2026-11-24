<?php

namespace App\Http\Controllers;

use App\Support\Chapeaux;
use App\Support\Especes;
use App\Support\Habitats;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Fiche espèce /espece/{cd_ref} (itération P1) : référentiel TAXREF v18
 * (nom valide, groupes, habitat, présence, fiche INPN) + statuts de
 * protection/réglementation BDC v18 + ZNIEFF du périmètre où le taxon est
 * déterminant + communes où il est présent (ZNIEFF déterminantes et
 * sites Natura 2000, via les pivots précalculés, paginé serveur).
 *
 * Une espèce sensible (référentiel de sensibilité SINP v18) ou flaguée
 * `confidentielle` n'a JAMAIS de fiche : 404 (pas de localisation indirecte
 * d'espèce sensible — règle absolue, filtre posé côté requête dans
 * App\Support\Especes).
 */
class EspeceController extends Controller
{
    private const PAR_PAGE = 25;

    public function show(Request $request, string $cdRef): Response
    {
        $cdRef = (int) $cdRef;
        if ($cdRef <= 0 || ! Especes::especeVisible($cdRef)) {
            abort(404);
        }

        $taxon = Especes::taxonRef($cdRef);
        $nomZnieff = Especes::nomDepuisZnieff($cdRef);

        // Nom affiché : TAXREF v18 en priorité, repli sur la citation ZNIEFF.
        $nom = $taxon['nom_valide'] ?? null;
        if ($nom === null || $nom === '') {
            $nom = $nomZnieff['nom'] ?? null;
        }
        if ($nom === null) {
            abort(404); // aucun nom connu pour ce cd_ref
        }
        $vern = $nomZnieff['vern'] ?? null;
        $groupe = $taxon['groupe2'] ?? $taxon['groupe1'] ?? $nomZnieff['groupe'] ?? null;

        $page = max(1, (int) $request->query('page', '1'));
        $zonages = Especes::zonagesEspecePagine($cdRef, $page, self::PAR_PAGE);
        $statuts = Especes::statutsEspece($cdRef);
        // Communes où le taxon est présent (ZNIEFF déterminantes + sites
        // Natura 2000, pivots précalculés), paginées serveur.
        $pageCommunes = max(1, (int) $request->query('page_communes', '1'));
        $communes = Especes::communesEspecePagine($cdRef, $pageCommunes, self::PAR_PAGE);
        // Habitats HABREF où le taxon est espèce caractéristique (paginé serveur).
        $pageHabitats = max(1, (int) $request->query('page_habitats', '1'));
        $habitats = Habitats::habitatsEspecePagine($cdRef, $pageHabitats, self::PAR_PAGE);

        $titre = $vern !== null ? sprintf('%s (%s)', $vern, $nom) : $nom;
        $meta = sprintf(
            '%s%s : fiche espèce du portail — %d ZNIEFF du référentiel INPN la citent comme déterminante, présentes dans %d communes, %d statut(s) de protection ou de réglementation (BDC INPN).',
            $titre,
            $groupe !== null ? ', '.$groupe : '',
            $zonages['total'],
            $communes['total'],
            array_sum(array_map(fn (array $g) => count($g['statuts']), $statuts)),
        );

        return Inertia::render('Especes/Show', [
            'meta' => [
                'title' => $titre.' — fiche espèce',
                'description' => Chapeaux::meta($meta),
                'canonical' => route('espece.show', $cdRef),
            ],
            'espece' => [
                'cd_ref' => $cdRef,
                'nom' => $nom,
                'vern' => $vern,
                'groupe' => $groupe,
                'habitat' => $taxon['habitat'] ?? null,
                'presence_fr' => $taxon['presence_fr'] ?? null,
                'present_om' => $taxon['present_om'] ?? false,
                'url_inpn' => $taxon['url_inpn'] ?? null,
            ],
            'statuts' => $statuts,
            'zonages' => $zonages['items'],
            'nbZonages' => $zonages['total'],
            'pagination' => $this->paginationFiche('espece.show', ['cd_ref' => $cdRef], $zonages['page'], self::PAR_PAGE, $zonages['total']),
            'communes' => $communes['items'],
            'nbCommunes' => $communes['total'],
            'paginationCommunes' => $this->paginationFiche('espece.show', ['cd_ref' => $cdRef], $communes['page'], self::PAR_PAGE, $communes['total'], 'page_communes'),
            'habitats' => $habitats['items'],
            'nbHabitats' => $habitats['total'],
            'paginationHabitats' => $this->paginationFiche('espece.show', ['cd_ref' => $cdRef], $habitats['page'], self::PAR_PAGE, $habitats['total'], 'page_habitats'),
        ]);
    }
}
