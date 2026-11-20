<?php

namespace App\Http\Controllers;

abstract class Controller
{
    /**
     * Pagination d'une liste interne à une fiche (communes d'un zonage,
     * etc.) : paginée côté serveur, liens GET vers la même fiche avec
     * ?page=N (crawlables, et réutilisés par les rechargements partiels
     * Inertia). Zéro si la liste est vide — le pager ne s'affiche pas.
     *
     * @param  array<string, mixed>  $params  paramètres de route de la fiche
     * @return array{total: int, page: int, derniere: int, url_precedente: ?string, url_suivante: ?string}
     */
    protected function paginationFiche(string $route, array $params, int $page, int $parPage, int $total): array
    {
        $derniere = max(1, (int) ceil($total / $parPage));
        $page = min(max(1, $page), $derniere);

        $url = fn (int $p): ?string => $p >= 1 && $p <= $derniere
            ? route($route, array_merge($params, ['page' => $p]))
            : null;

        return [
            'total' => $total,
            'page' => $page,
            'derniere' => $derniere,
            'url_precedente' => $page > 1 ? $url($page - 1) : null,
            'url_suivante' => $page < $derniere ? $url($page + 1) : null,
        ];
    }
}
