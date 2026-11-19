<?php

namespace App\Support;

/**
 * Formatage français + chapeaux factuels des fiches.
 *
 * Tout est déterministe et construit sur les seuls attributs précalculés :
 * jamais de calcul spatial en ligne, jamais de rediffusion des données
 * naturalistes (renvoi INPN). Chapeau = anti « thin content » : résumé
 * factuel unique par fiche (maquette : encadré à filet vert).
 */
final class Chapeaux
{
    /** Nombre à la française : 6557.68 → « 6 557,68 » */
    public static function nombre(float|int|null $v, int $decimales = 2): string
    {
        if ($v === null) {
            return '—';
        }

        return number_format((float) $v, $decimales, ',', ' ');
    }

    /** Hectares : 6557.68 → « 6 557,68 ha », 728.5 → « 728,5 ha », 2710 → « 2 710 ha ». */
    public static function ha(?float $v, ?int $decimalesForcées = null): string
    {
        if ($v === null) {
            return '—';
        }
        $d = $decimalesForcées ?? (fmod((float) $v, 1.0) === 0.0 ? 0 : 2);
        $texte = self::nombre($v, $d);
        if ($d > 0) {
            // pas de zéro de queue : 728,50 → 728,5 — les superficies
            // officielles restent lisibles, les vraies décimales non.
            $texte = rtrim(rtrim($texte, '0'), ',');
        }

        return $texte.' ha';
    }

    /** Pourcentage : 49.54 → « 49,5 % » (1 décimale, jamais si entier). */
    public static function pct(?float $v): string
    {
        if ($v === null) {
            return '—';
        }

        return self::nombre((float) $v, fmod((float) $v, 1.0) === 0.0 ? 0 : 1).' %';
    }

    /**
     * Date INPN lisible : 1982-01-01 → « 1er janvier 1982 ».
     */
    public static function date(?string $iso): string
    {
        if ($iso === null || $iso === '') {
            return '—';
        }
        $jours = [1 => '1ᵉʳ'];
        $mois = [
            '', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
            'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre',
        ];
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $iso, $m)) {
            return '—';
        }
        $jour = (int) $m[3];
        $prefixe = $jours[$jour] ?? (string) $jour;

        return $prefixe.' '.$mois[(int) $m[2]].' '.$m[1];
    }

    /**
     * Meta description (SEO) : chapeau borné à ~158 caractères, sans
     * couper un mot.
     */
    public static function meta(string $chapeau, int $max = 158): string
    {
        $chapeau = trim(strip_tags($chapeau));
        if (mb_strlen($chapeau) <= $max) {
            return $chapeau;
        }
        $coupe = mb_strcut($chapeau, 0, $max + 30);
        $dernier = mb_strrpos($coupe, ' ');
        if ($dernier === false || $dernier < 60) {
            $dernier = $max;
        }

        return rtrim(mb_substr($chapeau, 0, $dernier), " \t\n\r\0\x0B.,;:…").'…';
    }

    /**
     * Commune la plus couverte d'un zonage (« {nom} ({x} % de son
     * territoire) »), pour les chapeaux. Retour null si aucune.
     *
     * @param  array<int, array{nom: string, part_commune: ?float}>  $communes
     * @return array{nom: string, part_commune: float}|null
     */
    public static function communePrincipale(array $communes): ?array
    {
        $meilleure = null;
        foreach ($communes as $c) {
            $part = (float) ($c['part_commune'] ?? 0);
            if ($meilleure === null || $part > (float) $meilleure['part_commune']) {
                $meilleure = ['nom' => (string) $c['nom'], 'part_commune' => $part];
            }
        }

        return $meilleure;
    }

    /**
     * Chapeau d'une fiche ZNIEFF — faits uniquement : inventaire, dates
     * INPN, superficie mesurée, communes, commune la plus couverte.
     *
     * @param  array{id: string, famille: string, nom: string, superficie_ha: ?float, superficie_ref_ha: ?float, date_crea: ?string, date_modif_geo: ?string}  $z
     * @param  array<int, array{nom: string, part_commune: ?float}>  $communes
     */
    public static function znieff(array $z, array $communes): string
    {
        $type = $z['famille'] === 'z1' ? 'de type I' : 'de type II';
        $phrase = 'ZNIEFF '.$type.' « '.$z['nom'].' » ('.$z['id'].'), recensée dans l\'inventaire national du patrimoine naturel';
        if (! empty($z['date_crea'])) {
            $phrase .= ' depuis le '.self::date($z['date_crea']);
        }
        if (! empty($z['date_modif_geo'])) {
            $phrase .= ', dernière mise à jour géométrique INPN le '.self::date($z['date_modif_geo']);
        }
        $phrase .= '. ';
        if ($z['superficie_ha'] !== null) {
            $phrase .= 'Le zonage s\'étend sur '.self::ha($z['superficie_ha'])
                .' — aire géométrique du périmètre officiel saisi au 1/25 000, écart de mesure ≈ ± 1 %';
            if ($z['superficie_ref_ha'] !== null) {
                $phrase .= ' (superficie INPN de référence : '.self::ha($z['superficie_ref_ha']).')';
            }
            $phrase .= '. ';
        }
        $n = count($communes);
        if ($n === 0) {
            $phrase .= 'Le périmètre ne traverse aucune commune du référentiel communal. ';
        } else {
            $phrase .= 'Il concerne '.$n.' commune'.($n > 1 ? 's' : '');
            $principale = self::communePrincipale($communes);
            if ($principale !== null && $principale['part_commune'] > 0) {
                $phrase .= ', dont '.$principale['nom'].' couverte à '.self::pct($principale['part_commune']).' de son territoire';
            }
            $phrase .= '. ';
        }
        $phrase .= 'Les espèces et habitats déterminants restent consultables sur la fiche INPN, jamais rediffusés ici.';

        return $phrase;
    }

    /**
     * Chapeau d'une fiche Natura 2000.
     *
     * @param  array{code: string, type: ?string, nom: string, superficie_ha: ?float, superficie_ref_ha: ?float, date_designation: ?string, date_modif_geo: ?string}  $s
     * @param  array<int, array{nom: string, part_commune: ?float}>  $communes
     */
    public static function natura(array $s, array $communes): string
    {
        $type = strtoupper((string) ($s['type'] ?? 'SIC'));
        $phrase = 'Site Natura 2000 ('.$type.') « '.$s['nom'].' » ('.$s['code'].')';
        if (! empty($s['date_designation'])) {
            $phrase .= ', désigné le '.self::date($s['date_designation']);
        }
        if (! empty($s['date_modif_geo'])) {
            $phrase .= ', dernière mise à jour géométrique INPN le '.self::date($s['date_modif_geo']);
        }
        $phrase .= '. ';
        if ($s['superficie_ha'] !== null) {
            $phrase .= 'Le site s\'étend sur '.self::ha($s['superficie_ha'])
                .' — aire géométrique du périmètre officiel, écart de mesure ≈ ± 1 %. ';
        }
        $n = count($communes);
        $phrase .= 'Il concerne '.$n.' commune'.($n > 1 ? 's' : '').', ';
        $principale = self::communePrincipale($communes);
        if ($principale !== null && $principale['part_commune'] > 0) {
            $phrase .= 'dont '.$principale['nom'].' pour '.self::pct($principale['part_commune']).' de son territoire. ';
        } else {
            $phrase .= 'selon le croisement précalculé du portail. ';
        }
        $phrase .= 'Les espèces et habitats d\'intérêt communautaire ne sont pas rediffusés : consultez la fiche INPN et le document d\'objectifs du site.';

        return $phrase;
    }

    /**
     * Chapeau d'une fiche d'aire protégée.
     *
     * @param  array{id: string, type_nom: ?string, nom: string, superficie_ha: ?float, superficie_ref_ha: ?float, acte: ?string, gestionnaire: ?string, date_crea_sign: ?string, date_modif_geo: ?string}  $a
     * @param  array<int, array{nom: string, part_commune: ?float}>  $communes
     */
    public static function aire(array $a, array $communes): string
    {
        $phrase = 'Aire protégée'.($a['type_nom'] !== null && $a['type_nom'] !== '' ? ' — '.$a['type_nom'] : '')
            .' « '.$a['nom'].' » ('.$a['id'].')';
        if (! empty($a['date_crea_sign'])) {
            $phrase .= ', créée ou signée le '.self::date($a['date_crea_sign']);
        }
        if (! empty($a['date_modif_geo'])) {
            $phrase .= ', dernière mise à jour géométrique INPN le '.self::date($a['date_modif_geo']);
        }
        $phrase .= '. ';
        if (! empty($a['acte'])) {
            $phrase .= 'Acte de classement : '.$a['acte'].'. ';
        }
        if (! empty($a['gestionnaire'])) {
            $phrase .= 'Gestionnaire : '.$a['gestionnaire'].'. ';
        }
        if ($a['superficie_ha'] !== null) {
            $phrase .= 'Périmètre de '.self::ha($a['superficie_ha'])
                .' — géométrie fine précalculée à ~5 m à l\'import, écart de mesure ≈ ± 1 %. ';
        }
        $n = count($communes);
        $phrase .= 'L\'aire concerne '.$n.' commune'.($n > 1 ? 's' : '').' du référentiel communal';
        $principale = self::communePrincipale($communes);
        if ($principale !== null && $principale['part_commune'] > 0) {
            $phrase .= ', dont '.$principale['nom'].' couverte à '.self::pct($principale['part_commune']).' de son territoire';
        }
        $phrase .= '. Enjeux et habitats : consultez la fiche INPN, jamais rediffusés ici.';

        return $phrase;
    }
}
