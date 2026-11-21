<script setup>
import { Head, Link } from '@inertiajs/vue3';
import Layout from '../../Components/Layout.vue';
import BadgeFamille from '../../Components/BadgeFamille.vue';
import ListeFiltres from '../../Components/ListeFiltres.vue';
import ListePager from '../../Components/ListePager.vue';
import ExportsBoutons from '../../Components/ExportsBoutons.vue';

/**
 * Liste des sites Natura 2000 (itération d) : gabarit « Sites Natura
 * 2000 » du prototype — recherche par code (FR…) ou nom, filtres
 * type (SIC/ZPS) et département.
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
        <p class="breadcrumb"><Link href="/">Accueil</Link> › Natura 2000</p>
        <h1>{{ deptNom ? `Sites Natura 2000 — ${deptNom} (${filtres.dept})` : 'Sites Natura 2000' }}</h1>
        <p class="lead">
            Sites d'importance communautaire (SIC) et zones de protection spéciale (ZPS),
            reliés aux communes qui les portent. Chaque fiche liste les habitats et les
            espèces d'intérêt communautaire du formulaire standard INPN.
        </p>

        <ListeFiltres
            action="/natura"
            :q="filtres.q"
            :dept="filtres.dept"
            :type="filtres.type"
            placeholder="Code (ex. FR2300128) ou nom du site…"
            :departements="departements"
            :types="{ SIC: 'SIC / ZSC', ZPS: 'ZPS' }"
            bouton="Filtrer"
        />

        <div class="plate">
            <table>
                <thead>
                    <tr><th>Type</th><th>Site</th><th>Superficie</th><th>Communes</th><th>Mise à jour</th></tr>
                </thead>
                <tbody>
                    <tr v-for="s in sites" :key="s.code">
                        <td><BadgeFamille classe="natura" :label="s.type" /></td>
                        <td>
                            <Link :href="route('natura.show', s.code)"><strong>{{ s.nom }}</strong></Link>
                            <span class="liste-note"> — {{ s.code }}</span>
                        </td>
                        <td>{{ s.superficie_ha ?? '—' }}</td>
                        <td>{{ s.nbCommunes }} commune{{ s.nbCommunes > 1 ? 's' : '' }}</td>
                        <td>{{ s.maj ?? '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <ListePager :pagination="pagination" mot="sites" />

        <ExportsBoutons action="/natura" :filtres="filtres" quoi="liste Natura 2000 filtrée" />

        <p class="attribution">
            Natura 2000 : référentiel INPN · MNHN (attribution, conditions de réutilisation INPN —
            jamais la Licence Ouverte 2.0 pour ces données). Croisements communaux précalculés
            à l'import (écart ≈ ± 1 %).
        </p>
    </Layout>
</template>
