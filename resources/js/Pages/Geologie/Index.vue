<script setup>
import { Head, Link } from '@inertiajs/vue3';
import Layout from '../../Components/Layout.vue';
import ListeFiltres from '../../Components/ListeFiltres.vue';
import ListePager from '../../Components/ListePager.vue';

/**
 * Liste des sites INPG (patrimoine géologique) : recherche par nom ou
 * identifiant métier (NAQ0004…), filtre département. Pagination serveur.
 */
defineProps({
    meta: { type: Object, required: true },
    departements: { type: Object, required: true },
    filtres: { type: Object, required: true },
    deptNom: { type: String, default: null },
    sites: { type: Array, required: true },
    pagination: { type: Object, required: true },
});
</script>

<template>
    <Layout :titre="meta.title">
        <Head>
            <title>{{ meta.title }} · Portail ZNIEFF</title>
            <meta name="description" :content="meta.description" />
            <link rel="canonical" :href="meta.canonical" />
            <meta v-if="meta.noindex" name="robots" content="noindex" />
        </Head>
        <p class="breadcrumb"><Link href="/">Accueil</Link> › Patrimoine géologique</p>
        <h1>{{ deptNom ? `Sites géologiques — ${deptNom} (${filtres.dept})` : 'Patrimoine géologique (INPG)' }}</h1>
        <p class="lead">
            Sites de l'inventaire national du patrimoine géologique (INPG, INPN · MNHN) :
            recherche par nom ou par identifiant métier. Chaque fiche affiche le périmètre,
            les communes croisées et les intérêts géologiques — la fiche INPN reste la
            référence pour le descriptif complet. Sites publics uniquement.
        </p>

        <ListeFiltres
            action="/geologie"
            :q="filtres.q"
            :dept="filtres.dept"
            placeholder="Identifiant (ex. NAQ0004) ou nom…"
            :departements="departements"
            bouton="Filtrer"
        />

        <div class="plate">
            <table>
                <thead>
                    <tr><th>Identifiant</th><th>Site</th><th>Superficie</th><th>Communes</th></tr>
                </thead>
                <tbody>
                    <tr v-for="s in sites" :key="s.id">
                        <td><small>{{ s.id }}</small></td>
                        <td><Link :href="route('geologie.show', s.id)">{{ s.nom }}</Link></td>
                        <td>{{ s.superficie_ha }}</td>
                        <td>{{ s.nb_communes }}</td>
                    </tr>
                </tbody>
            </table>
            <ListePager :pagination="pagination" mot="sites" />
        </div>

        <p class="attribution">
            Données : inventaire national du patrimoine géologique (INPG) — INPN · MNHN, réutilisées sous les
            <a href="https://inpn.mnhn.fr/legal" target="_blank" rel="noopener">licences de l'INPN ↗</a>,
            jamais présentées comme Licence Ouverte 2.0.
        </p>
    </Layout>
</template>
