<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
// route() dans un <script setup> : import explicite depuis le package Ziggy
// (ZiggyVue n'injecte route() que dans les templates) + config passée depuis
// la prop partagée « ziggy » — sinon « route is not defined » et crash SSR.
import { route as ziggyRoute } from '../../../../vendor/tightenco/ziggy';
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
    especes: { type: Array, required: true }, // espèces déterminantes (hors confidentielles)
    nbEspeces: { type: Number, required: true }, // total du filtre courant (groupe actif ou tous)
    nbEspecesTotal: { type: Number, required: true },
    groupes: { type: Array, required: true }, // sous-onglets : [{ groupe, n }]
    groupeActif: { type: String, default: null }, // ?groupe=Oiseaux
    paginationEspeces: { type: Object, required: true }, // pagination serveur des espèces (?page_especes=)
});

const type = props.zonage.famille === 'z1' ? 'ZNIEFF I' : 'ZNIEFF II';

// Version du référentiel INPN affichée avec l'attribution (prop partagée site.referentiels).
const page = usePage();
const versionZnieff = computed(() => page.props.site?.referentiels?.znieff?.version ?? '');

// Sous-onglets par groupe taxonomique : rechargement partiel Inertia, le
// filtre (?groupe=) et la pagination (?page_especes=) restent côté serveur.
const onlyEspeces = ['especes', 'nbEspeces', 'nbEspecesTotal', 'groupes', 'groupeActif', 'paginationEspeces'];
const urlGroupe = (g) => ziggyRoute(
    'znieff.show',
    { id: props.zonage.id, ...(g ? { groupe: g } : {}), page_especes: 1 },
    true,
    page.props.ziggy,
);
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

        <h2 id="especes">Espèces déterminantes</h2>
        <p class="lead">
            {{ nbEspecesTotal }} espèce{{ nbEspecesTotal > 1 ? 's' : '' }} déterminante{{ nbEspecesTotal > 1 ? 's' : '' }}
            citée{{ nbEspecesTotal > 1 ? 's' : '' }} pour cette zone dans le référentiel INPN
            (hors espèces confidentielles).
        </p>
        <div v-if="especes.length > 0">
            <div v-if="groupes.length > 1" class="onglets" role="tablist" aria-label="Groupes taxonomiques">
                <Link
                    :href="urlGroupe(null)"
                    class="onglet"
                    :class="{ actif: groupeActif === null }"
                    :only="onlyEspeces"
                    preserve-scroll
                    role="tab"
                    :aria-selected="groupeActif === null"
                >Tous ({{ nbEspecesTotal }})</Link>
                <Link
                    v-for="g in groupes"
                    :key="g.groupe"
                    :href="urlGroupe(g.groupe)"
                    class="onglet"
                    :class="{ actif: groupeActif === g.groupe }"
                    :only="onlyEspeces"
                    preserve-scroll
                    role="tab"
                    :aria-selected="groupeActif === g.groupe"
                >{{ g.groupe }} ({{ g.n }})</Link>
            </div>
            <div class="plate">
                <table>
                    <thead>
                        <tr><th v-if="groupeActif === null">Groupe</th><th>Espèce citée</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="e in especes" :key="e.cd_ref + '|' + e.nom">
                            <td v-if="groupeActif === null">{{ e.groupe }}</td>
                            <td>
                                <template v-if="e.vern">{{ e.vern }} <em>({{ e.nom }})</em></template>
                                <em v-else>{{ e.nom }}</em>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <ListePager :pagination="paginationEspeces" mot="espèces" :only="onlyEspeces" />
                <p class="hint">
                    Source : inventaire ZNIEFF INPN · MNHN<template v-if="versionZnieff"> — {{ versionZnieff }}</template>.
                    Noms cités tels quels dans le référentiel, hors espèces confidentielles ; noms français
                    officiels : TAXREF (MNHN). Habitats,
                    facteurs d'évolution et descriptif complet :
                    <a :href="zonage.url_inpn" target="_blank" rel="noopener">fiche INPN de la zone ↗</a>.
                </p>
            </div>
        </div>
        <p v-else class="lead">
            Aucune espèce déterminante (hors espèces confidentielles) n'est citée pour cette zone
            dans le référentiel INPN.
        </p>

        <p class="attribution">
            Données ZNIEFF : INPN · MNHN — réutilisées sous les
            <a href="https://inpn.mnhn.fr/legal" target="_blank" rel="noopener">licences de l'INPN ↗</a>,
            jamais présentées comme Licence Ouverte 2.0. Espèces déterminantes affichées hors espèces
            confidentielles (conditions affichées sur la page « Données &amp; méthodologie »).
        </p>
    </Layout>
</template>
