<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Filtres de recherche et de listes (itération d) : les requêtes sont
 * servies par des clauses SQL simples, indexées — aucun calcul spatial,
 * aucune rediffusion.
 *
 * La recherche par nom marche indépendamment de la casse et des
 * accents : en production MySQL est en collation insensible, et
 * SQLite (tests) ne l'est que pour l'ASCII ; on cherche donc à la
 * fois sur le nom (LIKE) et sur le slug du référentiel (Str::slug,
 * accents retirés à l'import). « pont audemer », « Pont-Audemer »
 * et « Pont Audemer » retrouvent le même « pont-audemer ».
 */
final class Recherche
{
    /**
     * Terme de recherche nettoyé : trim + espaces multiples réduites.
     * Terme non significatif (vide ou une seule lettre) → '' : la
     * liste complète est servie sans filtre de recherche.
     */
    public static function terme(mixed $q): string
    {
        if (! is_string($q)) {
            return '';
        }

        $net = trim(preg_replace('/\s+/u', ' ', $q) ?? '');

        return $net === '' || mb_strlen($net) < 2 ? '' : $net;
    }

    /**
     * Département demandé (?dept=27) validé contre le référentiel COG
     * du portail (casse insensible : 2a ≡ 2A, outre-mer compris).
     * Absent ou vide → null (liste complète). Fourni mais inconnu →
     * 404, comme la carte (pas de liste silencieusement complète).
     */
    public static function dept(mixed $dept): ?string
    {
        if (! is_string($dept)) {
            return null;
        }

        $code = mb_strtoupper(trim($dept));
        if ($code === '' || $code === '0') {
            return null;
        }

        if (! isset(config('portail.departements')[$code])) {
            abort(404, sprintf('Département inconnu : « %s » — le référentiel du portail couvre la métropole et l\'outre-mer (ex. 27, 2A, 971).', $code));
        }

        return $code;
    }

    /**
     * Le terme désigne-t-il un département (« 27 », « le 2A », « Eure ») ?
     * Recherche par code exact puis par nom (slug, insensible aux accents).
     *
     * @return array{code: string, nom: string}|null
     */
    public static function deptDuTerme(string $q): ?array
    {
        $depts = (array) config('portail.departements');

        $code = mb_strtoupper(trim($q));
        if (isset($depts[$code]) && is_string($depts[$code])) {
            return ['code' => $code, 'nom' => $depts[$code]];
        }

        $slug = Str::slug($q);
        if ($slug === '' || is_numeric($slug)) {
            return null;
        }

        foreach ($depts as $c => $nom) {
            if (is_string($nom) && Str::slug($nom) === $slug) {
                return ['code' => (string) $c, 'nom' => $nom];
            }
        }

        return null;
    }

    /**
     * Identifiant ZNIEFF plausible (national 9-12 chiffres ou ancien
     * numéro régional 4-8 chiffres) ?
     */
    public static function estIdentifiantZnieff(string $q): bool
    {
        return (bool) preg_match('/^\d{4,12}$/', $q);
    }

    /**
     * Code Natura 2000 / identifiant d'aire protégée plausible (FR…).
     */
    public static function estCodeInpn(string $q): bool
    {
        return (bool) preg_match('/^fr\d[0-9a-z]{5,11}$/i', $q);
    }

    /**
     * Slug du terme (Str::slug, identique à l'import des communes).
     */
    public static function slug(string $q): string
    {
        return Str::slug($q);
    }
}
