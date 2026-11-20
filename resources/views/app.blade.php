<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        {{-- Rendu serveur d'abord : le <title>, la description et le canonical
             portés par la page viennent du SSR (@inertiaHead) — les moteurs
             lisent le premier <title>, le fallback « title inertia » ne
             paraît que si le SSR est éteint ou indisponible. --}}
        @inertiaHead
        <title inertia>{{ config('app.name') }}</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        {{-- Routes nommées Ziggy : plus de script @routes en inline — la
             configuration est partagée par le middleware (prop « ziggy »)
             et passée à ZiggyVue au client comme au rendu serveur (SSR),
             un seul exemplaire des routes, fonctionnement identique. --}}
        {{-- Attribution des données sur chaque page (INPN · MNHN, IGN, INSEE). --}}
        <meta name="author" content="Portail ZNIEFF — données INPN/MNHN, IGN, INSEE">
    </head>
    <body class="antialiased">
        @inertia
    </body>
</html>
