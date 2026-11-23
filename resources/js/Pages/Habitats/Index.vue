<script setup>
import { Head, Link } from '@inertiajs/vue3';
import Layout from '../../Components/Layout.vue';
import ListePager from '../../Components/ListePager.vue';

/**
 * Liste des habitats HABREF v7.0 (MNHN) importés pour le périmètre :
 * recherche par libellé ou code, filtre par typologie. Pagination serveur.
 */
defineProps({
    meta: { type: Object, required: true },
    typologies: { type: Object, required: true }, // { 7: 'EUNIS 2012', 8: 'HIC…', 22: 'CORINE…' }
    filtres: { type: Object, required: true }, // { q, type }
    habitats: { type: Array, required: true }, // [{ cd_hab, cd_typo, typo, lb_code, nom_fr }]
    pagination: { type: Object, required: true },
});

const optionsTypo = Object.entries(typologies).map(([code, nom]) => ({ code, nom }));
</script>

<template>
    <Layout :titre="meta.title">
        <Head>
            <title>{{ meta.title }} · Portail ZNIEFF</title>
            <meta name="description" :content="meta.description" />
            <link rel="canonical" :href="meta.canonical" />
            <meta v-if="meta.noindex" name="robots" content="noindex" />
        </Head>
        <p class="breadcrumb"><Link href="/">Accueil</Link> › Habitats (HABREF)</p>
        <h1>Habitats — référentiel HABREF (MNHN)</h1>
        <p class="lead">
            Unités du référentiel national des habitats HABREF v7.0 (INPN · MNHN) importées pour le
            périmètre : habitats d'intérêt communautaire (HIC) cités par les sites Natura 2000,
            leurs correspondances EUNIS 2012 et CORINE Biotopes, et leurs parents hiérarchiques.
            Chaque fiche détaille l'unité, ses correspondances et ses espèces caractéristiques
            (hors espèces sensibles).
        </p>

        <form method="get" action="/habitats" class="searchbar" style="margin-top:1.1rem">
            <input type="search" name="q" :value="filtres.q" placeholder="Libellé ou code (ex. 2110, dunes)…" aria-label="Rechercher" />
            <select name="type" :value="filtres.type ?? ''" aria-label="Typologie">
                <option value="">— Toutes les typologies —</option>
                <option v-for="t in optionsTypo" :key="t.code" :value="t.code">{{ t.nom }}</option>
            </select>
            <button class="btn" type="submit">Filtrer</button>
        </form>

        <div class="plate">
            <table>
                <thead>
                    <tr><th>Typologie</th><th>Code</th><th>Habitat</th><th>cd_hab</th></tr>
                </thead>
                <tbody>
                    <tr v-for="h in habitats" :key="h.cd_hab">
                        <td>{{ h.typo }}</td>
                        <td>{{ h.lb_code ?? '—' }}</td>
                        <td><Link :href="`/habitat/${h.cd_hab}`">{{ h.nom_fr }}</Link></td>
                        <td class="hint">{{ h.cd_hab }}</td>
                    </tr>
                </tbody>
            </table>
            <ListePager :pagination="pagination" mot="habitats" />
        </div>

        <p class="attribution">
            Référentiel HABREF v7.0 : INPN · MNHN —
            <a href="https://inpn.mnhn.fr/legal" target="_blank" rel="noopener">licences de l'INPN ↗</a>,
            jamais présenté comme Licence Ouverte 2.0.
        </p>
    </Layout>
</template>
