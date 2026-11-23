<script setup>
import { Head, Link } from '@inertiajs/vue3';
import Layout from '../../Components/Layout.vue';
import ListeFiltres from '../../Components/ListeFiltres.vue';
import ListePager from '../../Components/ListePager.vue';
import ExportsBoutons from '../../Components/ExportsBoutons.vue';

/**
 * Liste / recherche des communes (itération d) : gabarit « Rechercher
 * une commune » du prototype. Filtres GET, pagination serveur 25/page,
 * exports CSV/GeoJSON de la liste filtrée.
 */
const props = defineProps({
    meta: { type: Object, required: true },
    departements: { type: Object, required: true },
    filtres: { type: Object, required: true },
    deptNom: { type: String, default: null },
    communes: { type: Array, required: true },
    pagination: { type: Object, required: true },
});

// Colonne « Zonages » : « 2 Z + 1 N2000 + 1 AP » ou « aucun » (prototype).
const zonagesTxt = (c) => {
    const parties = [];
    if (c.nbZonages > 0) parties.push(`${c.nbZonages} Z`);
    if (c.nbNatura > 0) parties.push(`${c.nbNatura} N2000`);
    if (c.nbAp > 0) parties.push(`${c.nbAp} AP`);

    return parties.join(' + ') || 'aucun';
};

// Fiche ABC de l'Observatoire : /commune/{code INSEE} (pas le slug — 404 sinon).
const abUrl = (insee) => `https://observatoire-abc.fr/commune/${insee}`;
</script>

<template>
    <Layout :titre="meta.title">
        <Head>
            <title>{{ meta.title }} · Portail ZNIEFF</title>
            <meta name="description" :content="meta.description" />
            <link rel="canonical" :href="meta.canonical" />
            <meta v-if="meta.noindex" name="robots" content="noindex" />
        </Head>
        <p class="breadcrumb"><Link href="/">Accueil</Link> › Communes</p>
        <h1>{{ deptNom ? `Communes de ${deptNom} (${filtres.dept})` : 'Rechercher une commune' }}</h1>
        <p class="lead">
            Recherche par nom, code INSEE ou code postal. Filtres possibles : département, région,
            présence d'un zonage. Chaque commune de France a une fiche, y compris celles sans
            zonage : leur fiche le dit explicitement.
        </p>

        <ListeFiltres
            action="/communes"
            :q="filtres.q"
            :dept="filtres.dept"
            placeholder="Filtrer les communes (ex. : Louviers, Pont-Audemer…)"
            :departements="departements"
            bouton="Filtrer"
        />

        <h2>Résultats ({{ pagination.total.toLocaleString('fr-FR') }} communes)</h2>
        <div class="plate">
            <table>
                <thead>
                    <tr><th>Commune</th><th>Dept / Région</th><th>Population</th><th>Zonages</th><th>ABC</th></tr>
                </thead>
                <tbody>
                    <tr v-for="c in communes" :key="c.insee">
                        <td>
                            <Link :href="route('commune.show', c.slug)"><strong>{{ c.nom }}</strong></Link>
                            <span class="liste-note"> — fiche complète</span>
                        </td>
                        <td>
                            {{ c.dept }}
                            <span class="liste-note" v-if="c.reg_nom"> · {{ c.reg_nom }}</span>
                        </td>
                        <td>{{ c.population ?? '—' }}</td>
                        <td>{{ zonagesTxt(c) }}</td>
                        <td>
                            <a v-if="c.has_abc" :href="abUrl(c.insee)" target="_blank" rel="noopener" class="badge abc">ABC ↗</a>
                            <span v-else class="liste-note">pas d'ABC</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <ListePager :pagination="pagination" mot="communes" />

        <ExportsBoutons action="/communes" :filtres="filtres" quoi="liste des communes filtrée" />

        <p class="attribution">
            Données : INPN · MNHN (attribution sur chaque fiche et chaque export, conditions de
            réutilisation INPN — jamais la Licence Ouverte 2.0 pour ces données).
            Référentiels : IGN AdminExpress, INSEE.
        </p>
    </Layout>
</template>
