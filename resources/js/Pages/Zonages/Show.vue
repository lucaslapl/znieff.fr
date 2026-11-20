<script setup>
import { Head, Link } from '@inertiajs/vue3';
import Layout from '../../Components/Layout.vue';
import BadgeFamille from '../../Components/BadgeFamille.vue';
import ListePager from '../../Components/ListePager.vue';
import MiniCarte from '../../Components/MiniCarte.vue';

const props = defineProps({
    meta: { type: Object, required: true },
    zonage: { type: Object, required: true },
    chapeau: { type: String, required: true },
    parent: { type: Object, default: null },
    enfants: { type: Array, required: true },
    communes: { type: Array, required: true },
    nbCommunes: { type: Number, required: true },
    pagination: { type: Object, required: true }, // pagination serveur de la liste des communes
});

const type = props.zonage.famille === 'z1' ? 'ZNIEFF I' : 'ZNIEFF II';
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
                <h1>{{ zonage.nom }}</h1>
                <p>
                    <BadgeFamille :classe="zonage.badge" :label="type" />
                    <span class="hint">ID {{ zonage.id }}<template v-if="zonage.num_regional"> · ancien n° {{ zonage.num_regional }}</template></span>
                </p>
                <div class="chapeau">{{ chapeau }}</div>
                <div class="plate">
                    <h2 style="border:none; margin-top:0">Caractéristiques</h2>
                    <dl class="kv">
                        <dt>Type de zone</dt><dd>{{ type }}</dd>
                        <dt>Superficie totale</dt>
                        <dd>
                            {{ zonage.superficie_ha }}
                            <small v-if="zonage.superficie_ref_ha">(superficie INPN : {{ zonage.superficie_ref_ha }} — écart ≈ ± 1 %)</small>
                        </dd>
                        <dt>Précision de saisie</dt><dd>{{ zonage.precision_saisie }}</dd>
                        <dt>Création du zonage</dt><dd>{{ zonage.date_crea }}</dd>
                        <dt>Dernière modification géométrique</dt><dd>{{ zonage.date_modif_geo }}</dd>
                        <dt v-if="parent">ZNIEFF II de rattachement</dt>
                        <dd v-if="parent">
                            <Link :href="route('znieff.show', parent.id)">{{ parent.nom }}</Link>
                        </dd>
                        <dt>Fiche INPN</dt>
                        <dd><a :href="zonage.url_inpn" target="_blank" rel="noopener">Consulter la fiche INPN ↗</a></dd>
                    </dl>
                    <div v-if="enfants.length > 0">
                        <h3>ZNIEFF I rattachées ({{ enfants.length }})</h3>
                        <ul class="communes-liste">
                            <li v-for="e in enfants" :key="e.id">
                                <Link :href="route('znieff.show', e.id)">{{ e.nom }}</Link>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
            <aside class="fiche-carte">
                <MiniCarte :couches="[{ url: zonage.geojson, famille: zonage.famille, href: route('znieff.show', zonage.id), label: zonage.nom }]" :hauteur="320" />
                <p class="hint">Périmètre simplifié pour l'affichage (INPN, MNHN).</p>
            </aside>
        </div>

        <h2>Communes concernées</h2>
        <p class="lead">{{ nbCommunes }} commune{{ nbCommunes > 1 ? 's' : '' }} intersectée{{ nbCommunes > 1 ? 's' : '' }} par le périmètre.</p>
        <div v-if="communes.length > 0" class="plate">
            <table>
                <thead>
                    <tr><th>Commune</th><th>Superficie concernée</th><th>Part du zonage</th><th>Territoire communal couvert</th></tr>
                </thead>
                <tbody>
                    <tr v-for="c in communes" :key="c.slug">
                        <td><Link :href="route('commune.show', c.slug)">{{ c.nom }}</Link> <small>({{ c.dept }})</small></td>
                        <td>{{ c.surface_ha }}</td>
                        <td>{{ c.part_zonage }}</td>
                        <td><strong>{{ c.part_commune }}</strong></td>
                    </tr>
                </tbody>
            </table>
            <ListePager :pagination="pagination" :only="['communes', 'pagination']" />
            <p class="hint">
                Surfaces d'intersection calculées par le pipeline du portail (géométrie INPN × contours
                communaux IGN AdminExpress, en Lambert-93), écart de mesure ≈ ± 1 %.
            </p>
        </div>

        <h2>Espèces déterminantes, habitats, facteurs d'évolution</h2>
        <p class="lead">
            Le portail ne rediffuse pas les données naturalistes des ZNIEFF : ces informations sont
            mises à jour par les scientifiques du réseau et restent consultables sur la
            <a :href="zonage.url_inpn" target="_blank" rel="noopener">fiche INPN de la zone ↗</a>.
        </p>

        <p class="attribution">
            Données ZNIEFF : INPN · MNHN (conditions de réutilisation affichées sur la page
            « Données &amp; méthodologie »). Aucune donnée naturaliste rediffusée ici.
        </p>
    </Layout>
</template>
