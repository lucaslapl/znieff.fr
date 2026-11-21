<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Layout from '../../Components/Layout.vue';

/**
 * Données & méthodologie (itération e) — gabarit du prototype, avec
 * les versions réelles des référentiels (props partagées
 * site.referentiels : version, date d'import, nombre d'entités).
 */
const page = usePage();

const props = defineProps({
    meta: { type: Object, required: true },
});

const versions = computed(() => page.props.site?.referentiels ?? {});

// Libellés des sources (clés de config portail.inpn.versions).
const libelles = {
    znieff: 'Inventaire ZNIEFF (types I et II), INPN · MNHN',
    natura: 'Sites Natura 2000, INPN · MNHN',
    aires: "Catalogue « Espaces protégés » (APA-INPN), MNHN",
    adminexpress: 'Contours communaux et superficie, IGN (AdminExpress)',
};

const sources = computed(() => Object.entries(versions.value).map(([cle, v]) => ({
    cle,
    libelle: libelles[cle] ?? cle,
    version: v.version,
    importeLe: v.importe_le,
    nbEntites: v.nb_entites,
})));
</script>

<template>
    <Layout :titre="meta.title">
        <Head>
            <title>{{ meta.title }} · Portail ZNIEFF</title>
            <meta name="description" :content="meta.description" />
            <link rel="canonical" :href="meta.canonical" />
        </Head>
        <p class="breadcrumb"><Link href="/">Accueil</Link> › Données &amp; méthodologie</p>
        <h1>Données &amp; méthodologie</h1>
        <p class="lead">
            Le portail applique le même principe que l'Observatoire des ABC : « la machine collecte,
            elle ne touche jamais au travail humain ». Faits bruts, jamais d'indicateur composite.
        </p>

        <h2>Sources</h2>
        <div class="plate">
            <dl class="kv">
                <template v-for="s in sources" :key="s.cle">
                    <dt>{{ s.libelle }}</dt>
                    <dd>
                        {{ s.version }}<template v-if="s.importeLe"> — importé le {{ s.importeLe }}</template
                        ><template v-if="s.nbEntites"> · {{ s.nbEntites.toLocaleString('fr-FR') }} entités</template>
                    </dd>
                </template>
                <dt>Communes, population, codes postaux</dt>
                <dd>Code officiel géographique (INSEE), geo.api.gouv.fr (Codes postaux), référentiel AdminExpress (IGN)</dd>
                <dt>Atlas de la biodiversité communale</dt>
                <dd>Observatoire des ABC — lien d'une commune ABC vers sa fiche sur observatoire-abc.fr</dd>
            </dl>
        </div>

        <h2>Ce que le portail ne fait pas</h2>
        <div class="plate">
            <ul style="margin-left:1.2rem">
                <li>
                    Aucune localisation précise d'espèce n'est diffusée. Les espèces déterminantes ZNIEFF
                    (hors <strong>espèces confidentielles</strong>) et les
                    espèces/habitats Natura 2000 du formulaire standard sont rediffusés tels quels, sous les
                    licences de l'INPN, avec attribution MNHN — comme sur l'Observatoire des ABC.
                </li>
                <li>Aucun indicateur composite, aucun « score » environnemental de commune — uniquement des faits bruts.</li>
                <li>
                    Aucune géométrie en production : les croisements se font par code INSEE avec des surfaces
                    précalculées à l'import. Écart de mesure constaté entre les surfaces croisées (Lambert-93) et les
                    superficies officielles : −0,3 % à −0,7 % sur le secteur test de Louviers / Acquigny —
                    l'écart affiché est « ≈ ± 1 % ». Les ZNIEFF sont saisies au 1/25 000 (simplification d'affichage
                    20 m pour le type I, 55 m pour le type II), les aires protégées à la saisie fine se précalculent
                    à ~5 m.
                </li>
                <li>Aucune donnée rediffusée hors des conditions de réutilisation attachées au jeu de données INPN.</li>
            </ul>
        </div>

        <h2>Réutilisation des données</h2>
        <div class="plate">
            <p>
                Les données ZNIEFF, Natura 2000 et aires protégées ne sont <strong>pas</strong> soumises à la Licence
                Ouverte Etalab : elles sont réutilisées sous les
                <a href="https://inpn.mnhn.fr/legal" target="_blank" rel="noopener">licences de l'INPN ↗</a>,
                avec attribution MNHN et version de la base affichées sur chaque fiche et chaque export.
                Les exports CSV et GeoJSON du portail sont fournis sous ces conditions, non sous LO 2.0.
                Toutes les listes d'espèces sont affichées hors espèces confidentielles.
            </p>
            <p style="margin-top:.6rem">
                Une API publique versionnée (sur le modèle de l'API v1 de l'Observatoire des ABC) est prévue dans une
                seconde phase.
            </p>
        </div>

        <h2>Fonctionnement technique</h2>
        <div class="plate">
            <ul style="margin-left:1.2rem">
                <li>
                    Pipeline de collecte : réutilisation du pipeline INPN de l'Observatoire des ABC —
                    commandes <code>portail:import-inpn</code> (shapefiles → PostGIS Docker en local → précalcul des
                    intersections Lambert-93), <code>portail:refresh-communes</code> (COG / INSEE / geo.api.gouv.fr)
                    et <code>portail:export-sql</code> (SQL portable de déploiement).
                </li>
                <li>Croisement commune ↔ zonages par code INSEE, rafraîchissement annuel des référentiels, idempotent.</li>
                <li>
                    Hébergement mutualisé (PulseHéberg, Plesk/PHP) sans base spatiale : aucune requête spatiale en
                    production, les cartes affichent des périmètres précalculés et simplifiés (public/geo).
                </li>
                <li>
                    Affichage cartographique : Leaflet avec fonds au choix OpenStreetMap ou Plan IGN v2 (WMTS libres
                    de la Géoplateforme, sans clé), surcouches GeoJSON précalculées par le pipeline.
                </li>
                <li>
                    Sitemap exhaustif : toutes les fiches communes et zonages y figurent, listes par département
                    incluses. Audience anonyme révocable ; pas de traceur publicitaire.
                </li>
            </ul>
        </div>

        <p class="attribution">
            Données : INPN · MNHN (conditions de réutilisation INPN, jamais LO 2.0 pour ces données).
            Référentiels : IGN, INSEE, geo.api.gouv.fr.
        </p>
    </Layout>
</template>
