<script setup>
import { Head, Link } from '@inertiajs/vue3';
import Layout from '../../Components/Layout.vue';
import BadgeFamille from '../../Components/BadgeFamille.vue';
import MiniCarte from '../../Components/MiniCarte.vue';

const props = defineProps({
    meta: { type: Object, required: true },
    commune: { type: Object, required: true },
    zonages: { type: Array, required: true },
    natura: { type: Array, required: true },
    aires: { type: Array, required: true },
    voisines: { type: Array, required: true },
    nbZonages: { type: Number, required: true },
    nbNatura: { type: Number, required: true },
    nbAires: { type: Number, required: true },
});

const abUrl = (slug) => `https://observatoire-abc.fr/commune/${slug}`;
</script>

<template>
    <Layout :titre="meta.title">
        <Head>
            <title>{{ meta.title }} · Portail ZNIEFF</title>
            <meta name="description" :content="meta.description" />
            <link rel="canonical" :href="meta.canonical" />
        </Head>

        <div class="fiche-avec-carte">
            <div class="fiche-info">
                <h1>Patrimoine naturel à {{ commune.nom }} ({{ commune.dept }}, {{ commune.dept_nom }})</h1>
                <p class="lead">
                    Vu <strong>par commune</strong>, l'inventaire du patrimoine naturel officiel : ZNIEFF,
                    sites Natura 2000 et aires protégées qui touchent le territoire de
                    {{ commune.nom }}. Surfaces d'intersection précalculées par le portail (écart ≈ ± 1 %).
                </p>
                <p v-if="commune.has_abc">
                    <BadgeFamille classe="abc" label="ABC" />
                    <a :href="abUrl(commune.slug)" target="_blank" rel="noopener">
                        Voir l'ABC de {{ commune.nom }} sur l'Observatoire des ABC ↗
                    </a>
                </p>
                <div class="plate">
                    <h2 style="border:none; margin-top:0">Informations générales</h2>
                    <dl class="kv">
                        <dt>Code INSEE</dt><dd>{{ commune.insee }}</dd>
                        <dt>Population (INSEE)</dt><dd>{{ commune.population ?? '—' }} habitants</dd>
                        <dt>Superficie (IGN)</dt><dd>{{ commune.superficie_ha }}</dd>
                        <dt>Région</dt><dd>{{ commune.reg_nom ?? '—' }}</dd>
                        <dt>Codes postaux</dt><dd>{{ commune.codes_postaux ?? '—' }}</dd>
                        <dt>Zonages ZNIEFF</dt><dd>{{ nbZonages }}</dd>
                        <dt>Sites Natura 2000</dt><dd>{{ nbNatura }}</dd>
                        <dt>Aires protégées</dt><dd>{{ nbAires }}</dd>
                    </dl>
                </div>
            </div>
            <aside class="fiche-carte">
                <MiniCarte :couches="[{ url: commune.geojson, famille: 'commune' }]" :hauteur="320" />
                <p class="hint">Contours : AdminExpress (IGN) · Périmètres : INPN (MNHN).</p>
            </aside>
        </div>


        <h2>Zonages ZNIEFF sur le territoire</h2>
        <div v-if="zonages.length > 0" class="plate">
            <table>
                <thead>
                    <tr><th>Type</th><th>Zonage</th><th>Superficie du zonage</th><th>Sur {{ commune.nom }}</th><th>Territoire communal couvert</th><th>Espèces déterminantes</th></tr>
                </thead>
                <tbody>
                    <tr v-for="z in zonages" :key="z.id">
                        <td><BadgeFamille :classe="z.badge" :label="z.famille === 'z1' ? 'ZNIEFF I' : 'ZNIEFF II'" /></td>
                        <td>
                            <Link :href="route('znieff.show', z.id)">{{ z.nom }}</Link>
                            <br /><small>n° {{ z.id }}<template v-if="z.num_regional"> ({{ z.num_regional }})</template></small>
                        </td>
                        <td>{{ z.superficie_ha }}</td>
                        <td>{{ z.surface_ha }}</td>
                        <td><strong>{{ z.part_commune }}</strong></td>
                        <td>
                            <Link :href="route('znieff.show', z.id) + '#especes'">{{ z.nb_especes }} espèce{{ z.nb_especes > 1 ? 's' : '' }}</Link>
                            <br /><small class="hint">hors confidentielles</small>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p v-else class="lead">
            Aucune ZNIEFF n'est recensée sur le territoire de {{ commune.nom }}. Le portail l'affiche
            clairement plutôt que de laisser une page vide — une commune sans zonage inscrit est une
            information en soi.
        </p>

        <h2>Sites Natura 2000</h2>
        <div v-if="natura.length > 0" class="plate">
            <table>
                <thead>
                    <tr><th>Type</th><th>Site</th><th>Superficie du site</th><th>Sur {{ commune.nom }}</th><th>Territoire communal couvert</th><th>Espèces · Habitats</th></tr>
                </thead>
                <tbody>
                    <tr v-for="s in natura" :key="s.code">
                        <td><BadgeFamille classe="natura" :label="s.type" /></td>
                        <td>
                            <Link :href="route('natura.show', s.code)">{{ s.nom }}</Link>
                            <br /><small>{{ s.code }}</small>
                        </td>
                        <td>{{ s.superficie_ha }}</td>
                        <td>{{ s.surface_ha }}</td>
                        <td><strong>{{ s.part_commune }}</strong></td>
                        <td>
                            <Link :href="route('natura.show', s.code) + '#especes'">{{ s.nb_especes }} espèce{{ s.nb_especes > 1 ? 's' : '' }}</Link>
                            · {{ s.nb_habitats }} habitat{{ s.nb_habitats > 1 ? 's' : '' }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p v-else class="lead">Aucun site Natura 2000 sur la commune.</p>

        <h2>Aires protégées sur le territoire</h2>
        <div v-if="aires.length > 0" class="plate">
            <table>
                <thead>
                    <tr><th>Type</th><th>Désignation</th><th>Superficie</th><th>Sur {{ commune.nom }}</th><th>Territoire communal couvert</th></tr>
                </thead>
                <tbody>
                    <tr v-for="a in aires" :key="a.id">
                        <td><BadgeFamille classe="ap" :label="a.type_nom" /></td>
                        <td>
                            <Link :href="route('ap.show', a.id)">{{ a.nom }}</Link>
                            <br /><small>{{ a.id }}</small>
                        </td>
                        <td>{{ a.superficie_ha }}</td>
                        <td>{{ a.surface_ha }}</td>
                        <td><strong>{{ a.part_commune }}</strong></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p v-else class="lead">Aucune aire protégée recensée sur la commune.</p>

        <h2>Communes voisines</h2>
        <p class="lead">Dans un rayon de 15 km autour du centre de {{ commune.nom }} (distance à vol d'oiseau, entre centroïdes).</p>
        <ul class="communes-liste">
            <li v-for="v in voisines" :key="v.slug">
                <Link :href="route('commune.show', v.slug)">{{ v.nom }} ({{ Math.round(v.distance_m / 100) / 10 }} km)</Link>
            </li>
        </ul>

        <p class="attribution">
            Données ZNIEFF, Natura 2000 et aires protégées : INPN · MNHN — conditions de réutilisation
            affichées sur la page « Données &amp; méthodologie ». Contours communaux : AdminExpress (IGN).
        </p>
    </Layout>
</template>
