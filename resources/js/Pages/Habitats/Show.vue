<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Layout from '../../Components/Layout.vue';
import ListePager from '../../Components/ListePager.vue';

/**
 * Fiche d'une unité HABREF v7.0 (MNHN) : libellés FR/EN, hiérarchie,
 * correspondances (EUNIS / CORINE / HIC), espèces caractéristiques
 * (hors espèces sensibles — filtre posé côté serveur), sites Natura 2000
 * du périmètre la citant. Listes paginées serveur.
 */
const props = defineProps({
    meta: { type: Object, required: true },
    habitat: { type: Object, required: true },
    taxons: { type: Array, required: true }, // [{ cd_ref, nom, vern, type }] — espèces visibles uniquement
    nbTaxons: { type: Number, required: true },
    paginationTaxons: { type: Object, required: true }, // ?page_taxons=
    sites: { type: Array, required: true }, // [{ code, nom, type }]
    nbSites: { type: Number, required: true },
    paginationSites: { type: Object, required: true }, // ?page_sites=
});

// Version du référentiel HABREF affichée avec l'attribution (prop partagée site.referentiels).
const page = usePage();
const versionHabref = computed(() => page.props.site?.referentiels?.habref?.version ?? '');

const onlyTaxons = ['taxons', 'nbTaxons', 'paginationTaxons'];
const onlySites = ['sites', 'nbSites', 'paginationSites'];
</script>

<template>
    <Layout :titre="meta.title">
        <Head>
            <title>{{ meta.title }} · Portail ZNIEFF</title>
            <meta name="description" :content="meta.description" />
            <link rel="canonical" :href="meta.canonical" />
        </Head>
        <p class="breadcrumb"><Link href="/">Accueil</Link> › <Link href="/habitats">Habitats (HABREF)</Link> › {{ habitat.nom_fr }}</p>
        <h1>{{ habitat.nom_fr }}</h1>
        <p class="lead">
            {{ habitat.typo }}<template v-if="habitat.lb_code"> — code <strong>{{ habitat.lb_code }}</strong></template>
            <span class="hint"> · cd_hab {{ habitat.cd_hab }}<template v-if="habitat.niveau"> · niveau {{ habitat.niveau }}</template></span>
        </p>
        <p v-if="habitat.nom_fr_complet && habitat.nom_fr_complet !== habitat.nom_fr" class="hint">{{ habitat.nom_fr_complet }}</p>
        <p v-if="habitat.nom_en" class="hint">Nom anglais : {{ habitat.nom_en }}</p>

        <div class="plate">
            <dl class="kv">
                <template v-if="habitat.parent">
                    <dt>Habitat parent</dt>
                    <dd><Link :href="`/habitat/${habitat.parent.cd_hab}`">{{ habitat.parent.nom_fr }}</Link></dd>
                </template>
                <dt>Fiche INPN</dt>
                <dd><a :href="habitat.url_inpn" target="_blank" rel="noopener">Consulter la fiche INPN ↗</a></dd>
                <dt v-if="habitat.territoires">Territoires de présence</dt>
                <dd v-if="habitat.territoires" class="hint">{{ habitat.territoires }}</dd>
            </dl>
            <div v-if="habitat.description" class="habitat-description">{{ habitat.description }}</div>
        </div>

        <div v-if="habitat.corresp.length > 0" class="plate">
            <h2 style="border:none; margin-top:0">Correspondances</h2>
            <ul class="communes-liste">
                <li v-for="c in habitat.corresp" :key="c.cd_hab">
                    <Link :href="`/habitat/${c.cd_hab}`">{{ c.nom_fr }}</Link>
                    <small>({{ c.typo }}<template v-if="c.lb_code"> · {{ c.lb_code }}</template>)</small>
                </li>
            </ul>
        </div>

        <div v-if="habitat.enfants.length > 0" class="plate">
            <h2 style="border:none; margin-top:0">Sous-habitats ({{ habitat.enfants.length }})</h2>
            <ul class="communes-liste">
                <li v-for="e in habitat.enfants" :key="e.cd_hab">
                    <Link :href="`/habitat/${e.cd_hab}`">{{ e.nom_fr }}</Link>
                </li>
            </ul>
        </div>

        <h2>Espèces caractéristiques</h2>
        <p class="lead">
            {{ nbTaxons }} espèce{{ nbTaxons > 1 ? 's' : '' }} caractéristique{{ nbTaxons > 1 ? 's' : '' }}
            (HABREF v7.0) — hors espèces sensibles (référentiel SINP), jamais affichées.
        </p>
        <div v-if="taxons.length > 0" class="plate">
            <table>
                <thead><tr><th>Espèce</th><th>Type de relation</th></tr></thead>
                <tbody>
                    <tr v-for="t in taxons" :key="t.cd_ref">
                        <td>
                            <Link :href="`/espece/${t.cd_ref}`">
                                <template v-if="t.vern">{{ t.vern }} <em>({{ t.nom }})</em></template>
                                <em v-else>{{ t.nom }}</em>
                            </Link>
                        </td>
                        <td class="hint">{{ t.type }}</td>
                    </tr>
                </tbody>
            </table>
            <ListePager :pagination="paginationTaxons" mot="espèces" :only="onlyTaxons" />
        </div>
        <p v-else class="lead">Aucune espèce caractéristique visible (hors espèces sensibles) n'est associée à cette unité dans HABREF.</p>

        <h2>Sites Natura 2000 du périmètre</h2>
        <p class="lead">{{ nbSites }} site{{ nbSites > 1 ? 's' : '' }} citant cet habitat (ou sa correspondance directe).</p>
        <div v-if="sites.length > 0" class="plate">
            <ul class="communes-liste">
                <li v-for="s in sites" :key="s.code">
                    <Link :href="`/natura/${s.code}`">{{ s.nom }}</Link> <small>({{ s.code }}, {{ s.type }})</small>
                </li>
            </ul>
            <ListePager :pagination="paginationSites" mot="sites" :only="onlySites" />
        </div>

        <p class="attribution">
            Référentiel HABREF v7.0 : INPN · MNHN<template v-if="versionHabref"> — {{ versionHabref }}</template> —
            réutilisé sous les
            <a href="https://inpn.mnhn.fr/legal" target="_blank" rel="noopener">licences de l'INPN ↗</a>,
            jamais présenté comme Licence Ouverte 2.0. Espèces caractéristiques affichées hors espèces
            sensibles (référentiel de sensibilité SINP v18, filtre posé côté serveur).
        </p>
    </Layout>
</template>

<style scoped>
.habitat-description {
    white-space: pre-line;
    margin-top: 0.8rem;
}
</style>
