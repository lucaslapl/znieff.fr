<?php

namespace App\Http\Controllers;

use App\Models\AireProtegee;
use App\Models\Commune;
use App\Models\NaturaSite;
use App\Models\ReferentielImport;
use App\Models\Zonage;
use Illuminate\Http\Response;

/**
 * Sitemap exhaustif (itération e) : toutes les fiches du portail y
 * figurent — communes, zonages ZNIEFF, sites Natura 2000 et aires
 * protégées — plus les pages de navigation (accueil, méthodologie,
 * carte, listes et listes par département). Les pages de recherche
 * fonctionnelles (/recherche, listes avec ?q=) sont noindex et
 * volontairement absentes.
 *
 * Le contrat « sitemap exhaustif soumis à Search Console »
 * (l'Espace de vie de l'Observatoire des ABC avait connu un blocage
 * de fiches villes : corrigé dès le départ ici) impose de ne jamais
 * dépendre d'un référencement de fortune : c'est le plan du site.
 *
 * Format : index (/sitemap.xml) + un lot par famille de 10 000 URLs
 * (/sitemaps/communes-1.xml…). Les requêtes page par page sur les
 * clés primaires indexées, la réponse en flux — un hébergement
 * mutualisé sert le plan complet de France (~60 000 URLs) sans
 * saturer sa mémoire. lastmod = date d'import du référentiel
 * (table referentiel_imports), identique au pied de page.
 */
class SitemapController extends Controller
{
    private const PAR_LOT = 10000;

    /** @var array<string, string> familles de fiches → dernier jour d'import connu */
    private array $lastmod;

    public function __construct()
    {
        // Colonnes du référentiel datées (table referentiel_imports,
        // déjà lue par le pied de page) ; null = première importation pas encore tracée.
        $this->lastmod = [];
        foreach (['znieff', 'natura', 'aires', 'adminexpress'] as $source) {
            $ligne = ReferentielImport::query()->where('source', $source)->first(['importe_le']);
            $this->lastmod[$source] = $ligne?->importe_le?->toDateString();
        }
    }

    /**
     * Index des sitemaps : un lot par famille de fiches + le lot
     * « pages » (accueil, listes, méthodologie, carte). Une famille
     * vide (référentiel fraîchement installé) n'est pas référencée —
     * aucun lien mort dans le plan du site.
     */
    public function index(): Response
    {
        $familles = [
            'pages' => 1,
            'communes' => (int) ceil(Commune::query()->count() / self::PAR_LOT),
            'znieff' => (int) ceil(Zonage::query()->count() / self::PAR_LOT),
            'natura' => (int) ceil(NaturaSite::query()->count() / self::PAR_LOT),
            'ap' => (int) ceil(AireProtegee::query()->count() / self::PAR_LOT),
        ];

        $lignes = '';
        foreach ($familles as $famille => $lots) {
            for ($lot = 1; $lot <= $lots; $lot++) {
                $loc = url("/sitemaps/{$famille}-{$lot}.xml");
                $lignes .= "<sitemap><loc>{$loc}</loc></sitemap>";
            }
        }

        return $this->xml('<?xml version="1.0" encoding="UTF-8"?>'
            .'<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.$lignes.'</sitemapindex>');
    }

    /**
     * Un lot d'une famille : /sitemaps/{famille}-{page}.xml. lastmod =
     * date d'import du référentiel source.
     */
    public function lot(string $famille, int $lot): Response
    {
        if ($lot < 1) {
            abort(404);
        }

        $urls = $this->urls($famille, $lot);
        if ($urls === null) {
            abort(404);
        }

        // Le référentiel daté dont la famille dérive (lastmod du lot).
        $source = match ($famille) {
            'communes' => 'adminexpress',
            'znieff' => 'znieff',
            'natura' => 'natura',
            'ap' => 'aires',
            default => null,
        };
        $lastmod = $source !== null ? ($this->lastmod[$source] ?? null) : null;

        $lignes = '';
        foreach ($urls as $url) {
            $lignes .= '<url><loc>'.$url.'</loc>'
                .($lastmod !== null ? "<lastmod>{$lastmod}</lastmod>" : '')
                .'</url>';
        }

        return $this->xml('<?xml version="1.0" encoding="UTF-8"?>'
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.$lignes.'</urlset>');
    }

    /**
     * robots.txt : indexation de tout le portail sauf la page de
     * recherche fonctionnelle (noindex), et le plan du site déclaré.
     */
    public function robots(): Response
    {
        $contenu = "User-agent: *\n"
            ."Disallow: /recherche\n"
            .'Sitemap: '.url('/sitemap.xml')."\n";

        return response($contenu, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    // ------------------------------------------------------------------
    // Interne
    // ------------------------------------------------------------------

    /**
     * URLs d'un lot : les fiches de la famille (lot de 10 000 par clé
     * primaire indexée), ou les pages de navigation pour « pages ».
     * Lot vide = famille inconnue ou numéro hors bornes : null → 404.
     *
     * @return array<int, string>|null
     */
    private function urls(string $famille, int $lot): ?array
    {
        if ($famille === 'pages') {
            return array_map(
                fn (array $p): string => $this->loc((string) $p['url']),
                $this->pages(),
            );
        }

        /** @var array<int, array{url: string}>|null $lignes */
        $lignes = match ($famille) {
            'communes' => Commune::query()->orderBy('insee')
                ->forPage($lot, self::PAR_LOT)->get(['slug'])
                ->map(fn (Commune $c): array => ['url' => route('commune.show', $c->slug)])
                ->all(),
            'znieff' => Zonage::query()->orderBy('id')
                ->forPage($lot, self::PAR_LOT)->get(['id'])
                ->map(fn (Zonage $z): array => ['url' => route('znieff.show', $z->id)])
                ->all(),
            'natura' => NaturaSite::query()->orderBy('code')
                ->forPage($lot, self::PAR_LOT)->get(['code'])
                ->map(fn (NaturaSite $s): array => ['url' => route('natura.show', $s->code)])
                ->all(),
            'ap' => AireProtegee::query()->orderBy('id')
                ->forPage($lot, self::PAR_LOT)->get(['id'])
                ->map(fn (AireProtegee $a): array => ['url' => route('ap.show', $a->id)])
                ->all(),
            default => null,
        };

        if ($lignes === null || $lignes === []) {
            return null;
        }

        return array_map(fn (array $l): string => $this->loc((string) $l['url']), $lignes);
    }

    /**
     * Pages de navigation : accueil, méthodologie, carte (+ par
     * département), listes (+ par département, 102 du COG).
     *
     * @return array<int, array{url: string}>
     */
    private function pages(): array
    {
        $departements = array_keys((array) config('portail.departements'));

        $urls = [
            route('home'),
            route('donnees.index'),
            route('carte.index'),
            route('communes.index'),
            route('znieff.index'),
            route('natura.index'),
            route('ap.index'),
        ];

        foreach ($departements as $dept) {
            $urls[] = route('carte.index').'?dept='.$dept;
            $urls[] = route('communes.index').'?dept='.$dept;
            $urls[] = route('znieff.index').'?dept='.$dept;
            $urls[] = route('natura.index').'?dept='.$dept;
            $urls[] = route('ap.index').'?dept='.$dept;
        }

        return array_map(fn (string $u): array => ['url' => $u], $urls);
    }

    /** Échappe une URL pour le XML (les slugs INPN sont sûrs ; prudence pour les noms). */
    private function loc(string $url): string
    {
        return htmlspecialchars($url, ENT_XML1);
    }

    private function xml(string $contenu): Response
    {
        return response($contenu, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
