<script setup>
import { Head, Link } from '@inertiajs/vue3';
import Layout from '../../Components/Layout.vue';
import BadgeFamille from '../../Components/BadgeFamille.vue';
import MiniCarte from '../../Components/MiniCarte.vue';

const props = defineProps({
    meta: { type: Object, required: true },
    site: { type: Object, required: true },
    chapeau: { type: String, required: true },
    communes: { type: Array, required: true },
    nbCommunes: { type: Number, required: true },
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
                <h1>{{ site.nom }}</h1>
                <p>
                    <BadgeFamille classe="natura" :label="site.type" />
                    <span class="hint">{{ site.code }}</span>
                </p>
                <div class="chapeau">{{ chapeau }}</div>
                <div class="plate">
                    <h2 style="border:none; margin-top:0">Caractéristiques</h2>
                    <dl class="kv">
                        <dt>Désignation</dt><dd>{{ site.type }}</dd>
                        <dt>Superficie</dt>
                        <dd>
                            {{ site.superficie_ha }}
                            <small v-if="site.superficie_ref_ha">(référence INPN : {{ site.superficie_ref_ha }} — écart ≈ ± 1 %)</small>
                        </dd>
                        <dt>Précision de saisie</dt><dd>{{ site.precision_saisie }}</dd>
                        <dt>Désignation du site</dt><dd>{{ site.date_designation }}</dd>
                        <dt>Dernière modification géométrique</dt><dd>{{ site.date_modif_geo }}</dd>
                        <dt>Fiche INPN</dt>
                        <dd><a :href="site.url_inpn" target="_blank" rel="noopener">Consulter la fiche INPN ↗</a></dd>
                    </dl>
                </div>
            </div>
            <aside class="fiche-carte">
                <MiniCarte :couches="[{ url: site.geojson, famille: 'nat', href: route('natura.show', site.code), label: site.nom }]" :hauteur="320" />
                <p class="hint">Périmètre INPN (MNHN), simplifié pour l'affichage.</p>
            </aside>
        </div>

        <h2>Communes concernées</h2>
        <p class="lead">{{ nbCommunes }} commune{{ nbCommunes > 1 ? 's' : '' }} intersectée{{ nbCommunes > 1 ? 's' : '' }} par le périmètre du site.</p>
        <div v-if="communes.length > 0" class="plate">
            <table>
                <thead>
                    <tr><th>Commune</th><th>Superficie concernée</th><th>Part du site</th><th>Territoire communal couvert</th></tr>
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
            <p class="hint">
                Surfaces d'intersection précalculées (géométrie INPN × contours communaux IGN,
                Lambert-93), écart de mesure ≈ ± 1 %.
            </p>
        </div>

        <h2>Espèces et habitats d'intérêt communautaire</h2>
        <p class="lead">
            Le portail ne rediffuse ni les espèces, ni les habitats du formulaire standard des
            données, ni le contenu du document d'objectifs (DOCOB) : consultez la
            <a :href="site.url_inpn" target="_blank" rel="noopener">fiche INPN du site ↗</a>.
        </p>

        <p class="attribution">
            Données Natura 2000 : INPN · MNHN (nomenclature Natura 2000, directive Habitats et
            directive Oiseaux) — conditions de réutilisation INPN, jamais LO 2.0.
        </p>
    </Layout>
</template>
