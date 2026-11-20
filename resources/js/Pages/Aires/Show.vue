<script setup>
import { Head, Link } from '@inertiajs/vue3';
import Layout from '../../Components/Layout.vue';
import BadgeFamille from '../../Components/BadgeFamille.vue';
import ListePager from '../../Components/ListePager.vue';
import MiniCarte from '../../Components/MiniCarte.vue';

const props = defineProps({
    meta: { type: Object, required: true },
    aire: { type: Object, required: true },
    chapeau: { type: String, required: true },
    communes: { type: Array, required: true },
    nbCommunes: { type: Number, required: true },
    pagination: { type: Object, required: true }, // pagination serveur de la liste des communes
});
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
                <h1>{{ aire.nom }}</h1>
                <p>
                    <BadgeFamille classe="ap" :label="aire.type_nom" />
                    <span class="hint">{{ aire.id }}</span>
                </p>
                <div class="chapeau">{{ chapeau }}</div>
                <div class="plate">
                    <h2 style="border:none; margin-top:0">Caractéristiques</h2>
                    <dl class="kv">
                        <dt>Type de désignation</dt><dd>{{ aire.type_nom }}</dd>
                        <dt>Acte de classement</dt><dd>{{ aire.acte ?? '—' }}</dd>
                        <dt>Gestionnaire</dt><dd>{{ aire.gestionnaire ?? '—' }}</dd>
                        <dt>Superficie</dt>
                        <dd>
                            {{ aire.superficie_ha }}
                            <small v-if="aire.superficie_ref_ha">(référence INPN : {{ aire.superficie_ref_ha }} — écart ≈ ± 1 %)</small>
                        </dd>
                        <dt>Précision</dt><dd>saisie fine — périmètre précalculé à ~5 m à l'import</dd>
                        <dt>Création / signature</dt><dd>{{ aire.date_crea_sign }}</dd>
                        <dt>Dernière modification géométrique</dt><dd>{{ aire.date_modif_geo }}</dd>
                        <dt>Document de gestion</dt><dd>{{ aire.doc_gestion ? 'Oui (voir fiche INPN)' : '—' }}</dd>
                        <dt>Fiche INPN</dt>
                        <dd><a :href="aire.url_inpn" target="_blank" rel="noopener">Consulter la fiche INPN ↗</a></dd>
                    </dl>
                </div>
            </div>
            <aside class="fiche-carte">
                <MiniCarte :couches="[{ url: aire.geojson, famille: 'ap', href: route('ap.show', aire.id), label: aire.nom }]" :hauteur="320" />
                <p class="hint">Géométrie de saisie fine (périmètre précalculé à ~5 m à l'import).</p>
            </aside>
        </div>


        <h2>Communes concernées</h2>
        <p class="lead">{{ nbCommunes }} commune{{ nbCommunes > 1 ? 's' : '' }} intersectée{{ nbCommunes > 1 ? 's' : '' }} par le périmètre de l'aire protégée.</p>
        <div v-if="communes.length > 0" class="plate">
            <table>
                <thead>
                    <tr><th>Commune</th><th>Superficie concernée</th><th>Part de l'aire protégée</th><th>Territoire communal couvert</th></tr>
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
                Surfaces d'intersection précalculées (géométrie INPN × contours communaux IGN,
                Lambert-93), écart de mesure ≈ ± 1 %.
            </p>
        </div>

        <h2>Habitats et enjeux</h2>
        <p class="lead">
            À la différence des ZNIEFF (inventaire scientifique), une aire protégée est un zonage
            juridique. Les habitats, enjeux économiques et documents d'objectifs restent consultables
            sur la <a :href="aire.url_inpn" target="_blank" rel="noopener">fiche INPN ↗</a>,
            jamais rediffusés ici.
        </p>

        <p class="attribution">
            Données aires protégées : INPN · MNHN (inventaire APA) — conditions de réutilisation
            INPN, jamais LO 2.0.
        </p>
    </Layout>
</template>
