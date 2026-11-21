<script setup>
import { Head, Link } from '@inertiajs/vue3';
import Layout from '../../Components/Layout.vue';
import BadgeFamille from '../../Components/BadgeFamille.vue';
import ListeFiltres from '../../Components/ListeFiltres.vue';
import ListePager from '../../Components/ListePager.vue';
import ExportsBoutons from '../../Components/ExportsBoutons.vue';

/**
 * Liste des zonages ZNIEFF (itération d) : gabaret « Zonages ZNIEFF »
 * du prototype — recherche par identifiant (national ou ancien numéro
 * régional), nom, type I/II, département. Pagination serveur,
 * exports de la liste filtrée.
 */
defineProps({
    meta: { type: Object, required: true },
    departements: { type: Object, required: true },
    filtres: { type: Object, required: true },
    deptNom: { type: String, default: null },
    zonages: { type: Array, required: true },
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
        <p class="breadcrumb"><Link href="/">Accueil</Link> › ZNIEFF</p>
        <h1>{{ deptNom ? `Zonages ZNIEFF — ${deptNom} (${filtres.dept})` : 'Zonages ZNIEFF' }}</h1>
        <p class="lead">
            Recherche par identifiant, par nom ou par département. Chaque fiche affiche le
            périmètre, la table de croisement des communes (surfaces et parts précalculées)
            et les espèces déterminantes (hors espèces confidentielles) — la fiche INPN
            reste la référence pour le descriptif complet.
        </p>

        <ListeFiltres
            action="/znieff"
            :q="filtres.q"
            :dept="filtres.dept"
            :type="filtres.type"
            placeholder="Identifiant (ex. 8526, 230009093) ou nom…"
            :departements="departements"
            :types="{ z1: 'ZNIEFF de type I', z2: 'ZNIEFF de type II' }"
            bouton="Filtrer"
        />

        <div class="plate">
            <table>
                <thead>
                    <tr><th>Type</th><th>Zonage</th><th>Superficie</th><th>Communes</th><th>Mise à jour</th></tr>
                </thead>
                <tbody>
                    <tr v-for="z in zonages" :key="z.id">
                        <td><BadgeFamille :classe="z.badge" :label="z.famille === 'z1' ? 'ZNIEFF I' : 'ZNIEFF II'" /></td>
                        <td>
                            <Link :href="route('znieff.show', z.id)"><strong>{{ z.nom }}</strong></Link>
                            <span class="liste-note"> — ID {{ z.id }}<template v-if="z.num_regional"> · ancien n° {{ z.num_regional }}</template></span>
                        </td>
                        <td>{{ z.superficie_ha ?? '—' }}</td>
                        <td>{{ z.nbCommunes }} commune{{ z.nbCommunes > 1 ? 's' : '' }}</td>
                        <td>{{ z.maj ?? '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <ListePager :pagination="pagination" mot="zonages" />

        <ExportsBoutons action="/znieff" :filtres="filtres" quoi="liste des ZNIEFF filtrée" />

        <p class="attribution">
            ZNIEFF : inventaire du patrimoine naturel, INPN · MNHN (attribution, conditions de
            réutilisation INPN — jamais la Licence Ouverte 2.0 pour ces données). Superficies :
            aire géométrique précalculée à l'import (écart ≈ ± 1 % avec la référence INPN).
        </p>
    </Layout>
</template>
