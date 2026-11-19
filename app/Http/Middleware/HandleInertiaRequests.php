<?php

namespace App\Http\Middleware;

use App\Models\ReferentielImport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'site' => [
                'name' => config('app.name'),
                // Versions des référentiels, affichées en pied de page
                // (raîche annuelle — cache 1 heure).
                'referentiels' => $this->referentiels(),
            ],
        ];
    }

    /**
     * Versions datées des référentiels importés (repli : versions
     * configurées, affichées même sans base peuplée).
     *
     * @return array<string, array{version: string, importe_le: ?string, nb_entites: ?int}>
     */
    private function referentiels(): array
    {
        $configuees = (array) config('portail.inpn.versions');

        return Cache::remember('portail:referentiels', 3600, function () use ($configuees) {
            try {
                $lignes = ReferentielImport::query()->get()->keyBy('source');
            } catch (\Throwable) {
                $lignes = collect();
            }

            $versions = [];
            foreach ($configuees as $source => $libelle) {
                $ligne = $lignes->get($source);
                $versions[$source] = [
                    'version' => (string) ($ligne?->version_source ?: $libelle),
                    'importe_le' => $ligne?->importe_le?->toDateString(),
                    'nb_entites' => $ligne?->nb_entites,
                ];
            }

            return $versions;
        });
    }
}
