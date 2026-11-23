<script setup>
import { Head, Link } from '@inertiajs/vue3';
import Layout from '../../Components/Layout.vue';
import ListePager from '../../Components/ListePager.vue';

/**
 * Liste des organismes adhérents au SINP (référentiel INPN des
 * producteurs de données) : recherche par libellé, filtre par domaine.
 * Pagination serveur. Pas de coordonnées personnelles (mail, tél., adresse).
 */
defineProps({
    meta: { type: Object, required: true },
    domaines: { type: Array, required: true }, // ['Faune', 'Flore', …]
    filtres: { type: Object, required: true }, // { q, domaine }
    organismes: { type: Array, required: true },
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
        <p class="breadcrumb"><Link href="/">Accueil</Link> › <Link :href="route('donnees.index')">Données &amp; méthodologie</Link> › Organismes SINP</p>
        <h1>Organismes adhérents au SINP</h1>
        <p class="lead">
            Producteurs de données du Système d'Information sur la Nature et les Paysages (SINP) :
            associations, conservatoires, parcs, services de l'État… qui alimentent les inventaires
            rediffusés ici (ZNIEFF, Natura 2000, INPG). Référentiel public INPN.
        </p>

        <form method="get" action="/organismes" class="searchbar" style="margin-top:1.1rem">
            <input type="search" name="q" :value="filtres.q" placeholder="Libellé de l'organisme…" aria-label="Rechercher" />
            <select name="domaine" :value="filtres.domaine ?? ''" aria-label="Domaine">
                <option value="">— Tous les domaines —</option>
                <option v-for="d in domaines" :key="d" :value="d">{{ d }}</option>
            </select>
            <button class="btn" type="submit">Filtrer</button>
        </form>

        <div class="plate">
            <table>
                <thead>
                    <tr><th>Organisme</th><th>Domaines</th><th>Zones</th><th>Niveau d'adhésion</th><th>Site</th></tr>
                </thead>
                <tbody>
                    <tr v-for="o in organismes" :key="o.id">
                        <td>
                            <strong>{{ o.libelle_court }}</strong>
                            <div v-if="o.libelle_long && o.libelle_long !== o.libelle_court" class="hint">{{ o.libelle_long }}</div>
                            <div class="hint">{{ o.type_organisme ?? '' }}<template v-if="o.statut_organisme"> · {{ o.statut_organisme }}</template><template v-if="o.date_adhesion"> · adhésion {{ o.date_adhesion }}</template></div>
                        </td>
                        <td>{{ o.domaines ?? '—' }}</td>
                        <td class="hint">{{ o.zones ?? '—' }}</td>
                        <td class="hint">{{ o.niveau_adhesion ?? '—' }}</td>
                        <td><a v-if="o.url" :href="o.url" target="_blank" rel="noopener">↗</a><span v-else class="hint">—</span></td>
                    </tr>
                </tbody>
            </table>
            <ListePager :pagination="pagination" mot="organismes" />
        </div>

        <p class="attribution">
            Référentiel des organismes adhérents au SINP : INPN —
            <a href="https://sinp.naturefrance.fr/" target="_blank" rel="noopener">sinp.naturefrance.fr ↗</a>.
            Coordonnées personnelles non rediffusées.
        </p>
    </Layout>
</template>
