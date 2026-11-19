<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        {{-- Titre : chaque page Inertia fournit le sien via <Head><title>. --}}
        <title inertia>{{ config('app.name') }}</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @inertiaHead
        {{-- injection des routes nommées pour Ziggy (route('...') côté JS) --}}
        @routes
        {{-- Attribution des données sur chaque page (INPN · MNHN, IGN, INSEE). --}}
        <meta name="author" content="Portail ZNIEFF — données INPN/MNHN, IGN, INSEE">
    </head>
    <body class="antialiased">
        @inertia
    </body>
</html>
