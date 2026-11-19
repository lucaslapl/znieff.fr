<?php

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Derrière un reverse proxy TLS (nginx, Plesk…) : TRUSTED_PROXIES
        // liste les adresses du frontal (IPs ou CIDRs) ou « * » quand l'IP
        // du frontal n'est pas publiée (hébergement mutualisé : compromis
        // assumé). Sans confiance accordée, request()->getScheme() reste
        // « http » et tout ce qui dérive de url('/') — sitemap, canonicals —
        // est généré en http://.
        $proxies = trim((string) env('TRUSTED_PROXIES', ''));
        if ($proxies !== '') {
            $middleware->trustProxies(at: $proxies);
        }

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Pages d'erreur personnalisées (Inertia Error.vue) pour les
        // visites directes ; les navigations Inertia (XHR) rendent Error.vue
        // côté client.
        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->expectsJson()) {
                return null;
            }

            $status = $e instanceof HttpExceptionInterface
                ? $e->getStatusCode()
                : 500;

            if (! in_array($status, [403, 404, 419, 429, 500], true)) {
                return null;
            }

            if ($status === 500 && config('app.debug')) {
                return null;
            }

            return Inertia::render('Error', [
                'status' => $status,
                'url' => $request->url(),
            ])
                ->toResponse($request)
                ->setStatusCode($status);
        });
    })->create();
