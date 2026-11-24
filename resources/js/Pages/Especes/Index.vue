<script setup>
import { Head, Link } from '@inertiajs/vue3';
import Layout from '../../Components/Layout.vue';
import ListePager from '../../Components/ListePager.vue';

/**
 * Liste des espèces visibles /especes (?q= pour filtrer par nom) :
 * pagination serveur, formulaire GET natif (l'URL porte le filtre).
 * UNIQUEMENT les taxons avec fiche publique — jamais d'espèce sensible
 * (SINP v18) ni confidentielle (filtre posé côté requête, serveur).
 * SSR strict : toutes les props lues sont déclarées ici.
 */
defineProps({
    meta: { type: Object, required: true },
    q: { type: String, required: true },
    especes: { type: Array, required: true }, // [{ cd_ref, nom, vern, groupe, nbZonages, nbSites }]
    pagination: { type: Object, required: true }, // { total, page, derniere, url_precedente, url_suivante }
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

        <h1>{{ q ? `Espèces « ${q} »` : 'Espèces du portail' }}</h1>
        <p class="lead">
            Espèces du référentiel INPN (TAXREF) ayant une fiche publique sur le portail :
            nom scientifique ou vernaculaire, groupe taxonomique, ZNIEFF déterminantes et
            sites Natura 2000 qui les citent. Chaque fiche liste aussi les communes où le
            taxon est présent. Les espèces sensibles ou confidentielles ne sont jamais
            listées ni recherchables.
        </p>

        <form method="get" action="/especes" class="searchbar">
            <input type="search" name="q" :value="q" placeholder="Nom scientifique ou vernaculaire… (ex. « pluvier », « picus »…)" aria-label="Rechercher une espèce" />
            <button class="btn" type="submit">Rechercher</button>
        </form>

        <div class="plate" style="margin-top:1.2rem">
            <table>
                <thead><tr><th>Espèce</th><th>Groupe</th><th>ZNIEFF déterminantes</th><th>Sites Natura 2000</th></tr></thead>
                <tbody>
                    <tr v-for="e in especes" :key="e.cd_ref">
                        <td>
                            <Link :href="route('espece.show', e.cd_ref)"><em>{{ e.nom }}</em><template v-if="e.vern"> · {{ e.vern }}</template></Link>
                            <span class="liste-note"> — cd_ref {{ e.cd_ref }}</span>
                        </td>
                        <td>{{ e.groupe ?? '—' }}</td>
                        <td>{{ e.nbZonages }}</td>
                        <td>{{ e.nbSites }}</td>
                    </tr>
                    <tr v-if="especes.length === 0"><td colspan="4">Aucune espèce publique ne correspond à « {{ q }} ».</td></tr>
                </tbody>
            </table>
        </div>
        <ListePager :pagination="pagination" mot="espèces" />
    </Layout>
</template>
