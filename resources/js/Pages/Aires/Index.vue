<script setup>
import { Head, Link } from '@inertiajs/vue3';
import Layout from '../../Components/Layout.vue';
import BadgeFamille from '../../Components/BadgeFamille.vue';
import ListeFiltres from '../../Components/ListeFiltres.vue';
import ListePager from '../../Components/ListePager.vue';
import ExportsBoutons from '../../Components/ExportsBoutons.vue';

/**
 * Liste des aires protégées (itération d) : gabarit « Aires protégées »
 * du prototype — catalogue APA-INPN, recherche par identifiant ou nom,
 * filtres type de désignation (APPB, RNR, RNN…) et département.
 */
const props = defineProps({
    meta: { type: Object, required: true },
    departements: { type: Object, required: true },
    filtres: { type: Object, required: true },
    deptNom: { type: String, default: null },
    apTypes: { type: Array, required: true }, // [{ code, nom }] présents dans le référentiel
    aires: { type: Array, required: true },
    pagination: { type: Object, required: true },
});

// Le filtre type de ListeFiltres attend un objet { code: nom }.
const types = Object.fromEntries(props.apTypes.map((t) => [t.code, t.nom]));
</script>

<template>
    <Layout :titre="meta.title">
        <Head>
            <title>{{ meta.title }} · Portail ZNIEFF</title>
            <meta name="description" :content="meta.description" />
            <link rel="canonical" :href="meta.canonical" />
            <meta v-if="meta.noindex" name="robots" content="noindex" />
        </Head>
        <p class="breadcrumb"><Link href="/">Accueil</Link> › Aires protégées</p>
        <h1>{{ deptNom ? `Aires protégées — ${deptNom} (${filtres.dept})` : 'Aires protégées' }}</h1>
        <p class="lead">
            Réserves naturelles nationales et régionales, arrêtés préfectoraux de protection,
            réserves biologiques : le catalogue INPN des espaces protégés, organisé par commune.
            À la différence des ZNIEFF (inventaire scientifique), une aire protégée est un zonage
            juridique qui interdit ou encadre des usages.
        </p>

        <ListeFiltres
            action="/ap"
            :q="filtres.q"
            :dept="filtres.dept"
            :type="filtres.type"
            placeholder="Identifiant (ex. FR3800583) ou nom…"
            :departements="departements"
            :types="types"
            bouton="Filtrer"
        />

        <div class="plate">
            <table>
                <thead>
                    <tr><th>Type</th><th>Désignation</th><th>Superficie</th><th>Communes</th><th>Mise à jour</th></tr>
                </thead>
                <tbody>
                    <tr v-for="a in aires" :key="a.id">
                        <td><BadgeFamille classe="ap" :label="a.type_code" /></td>
                        <td>
                            <Link :href="route('ap.show', a.id)"><strong>{{ a.nom }}</strong></Link>
                            <span class="liste-note"> — {{ a.id }}<template v-if="a.type_nom"> · {{ a.type_nom }}</template></span>
                        </td>
                        <td>{{ a.superficie_ha ?? '—' }}</td>
                        <td>{{ a.nbCommunes }} commune{{ a.nbCommunes > 1 ? 's' : '' }}</td>
                        <td>{{ a.maj ?? '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <ListePager :pagination="pagination" mot="aires" />

        <ExportsBoutons action="/ap" :filtres="filtres" quoi="liste des aires protégées filtrée" />

        <p class="attribution">
            Aires protégées : catalogue APA-INPN · MNHN (attribution, conditions de réutilisation
            INPN — jamais la Licence Ouverte 2.0 pour ces données). Habitats et enjeux :
            renvoi fiche INPN, jamais rediffusés.
        </p>
    </Layout>
</template>
