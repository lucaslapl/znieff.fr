<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Layout from '../../Components/Layout.vue';
import BadgeFamille from '../../Components/BadgeFamille.vue';
import ListePager from '../../Components/ListePager.vue';
import MiniCarte from '../../Components/MiniCarte.vue';

const props = defineProps({
    meta: { type: Object, required: true },
    site: { type: Object, required: true },
    chapeau: { type: String, required: true },
    communes: { type: Array, required: true },
    nbCommunes: { type: Number, required: true },
    pagination: { type: Object, required: true }, // pagination serveur de la liste des communes
    habitats: { type: Array, required: true }, // habitats d'intérêt communautaire (FSD)
    nbHabitats: { type: Number, required: true },
    paginationHabitats: { type: Object, required: true }, // ?page_habitats=
    especes: { type: Array, required: true }, // espèces des directives (FSD)
    nbEspeces: { type: Number, required: true },
    paginationEspeces: { type: Object, required: true }, // ?page_especes=
});

// Version du référentiel INPN affichée avec l'attribution (prop partagée site.referentiels).
const page = usePage();
const versionNatura = computed(() => page.props.site?.referentiels?.natura?.version ?? '');

/* Statuts biologiques du formulaire standard (FSD) : r reproduction,
   c concentration, w hivernage, p passage. */
const presenceLabel = (p) =>
    p
        ? p
              .split(',')
              .map((x) => ({ r: 'nicheuse', c: 'concentration', w: 'hivernante', p: 'de passage' })[x] || x)
              .join(', ')
        : null;

/* Notations du FSD pour les habitats : A/B/C/D. */
const repLabel = (l) => ({ A: 'excellente', B: 'bonne', C: 'significative', D: 'non significative' })[l] || null;
const conLabel = (l) => ({ A: 'excellente', B: 'bonne', C: 'moyenne' })[l] || null;
const evalLabel = (l) => ({ A: 'excellente', B: 'bonne', C: 'significative', D: 'non significative' })[l] || null;
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
            <ListePager :pagination="pagination" :only="['communes', 'pagination']" />
            <p class="hint">
                Surfaces d'intersection précalculées (géométrie INPN × contours communaux IGN,
                Lambert-93), écart de mesure ≈ ± 1 %.
            </p>
        </div>

        <h2>Habitats d'intérêt communautaire</h2>
        <p class="lead">
            {{ nbHabitats }} habitat{{ nbHabitats > 1 ? 's' : '' }} d'intérêt communautaire
            recensé{{ nbHabitats > 1 ? 's' : '' }} sur le site (formulaire standard de données).
        </p>
        <div v-if="habitats.length > 0" class="plate">
            <table>
                <thead>
                    <tr>
                        <th>Code EUR</th><th>Habitat (annexe I)</th><th>Prioritaire</th><th>Corine</th>
                        <th>Représentativité</th><th>Conservation</th><th>Éval. globale</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="h in habitats" :key="h.cd_ue + '|' + (h.cd_hab ?? '')">
                        <td>{{ h.cd_ue }}</td>
                        <td>{{ h.nom ?? '—' }}</td>
                        <td><span v-if="h.prioritaire" class="badge prio">prioritaire</span><span v-else class="hint">—</span></td>
                        <td>{{ h.cd_hab ?? '—' }}</td>
                        <td :title="repLabel(h.representativite) ?? ''">{{ h.representativite ?? '—' }}</td>
                        <td :title="conLabel(h.conservation) ?? ''">{{ h.conservation ?? '—' }}</td>
                        <td :title="evalLabel(h.evaluation_globale) ?? ''">{{ h.evaluation_globale ?? '—' }}</td>
                    </tr>
                </tbody>
            </table>
            <ListePager :pagination="paginationHabitats" mot="habitats" :only="['habitats', 'nbHabitats', 'paginationHabitats']" />
            <p class="hint">
                Notations du formulaire standard : représentativité A excellente à D non significative ;
                conservation (structure et fonctions) A excellente à C moyenne ; évaluation globale du
                site A à D.
            </p>
        </div>
        <p v-else class="lead">Aucun habitat d'intérêt communautaire recensé sur ce site.</p>

        <h2 id="especes">Espèces des directives</h2>
        <p class="lead">
            {{ nbEspeces }} espèce{{ nbEspeces > 1 ? 's' : '' }} recensée{{ nbEspeces > 1 ? 's' : '' }} sur le site :
            oiseaux (directive « Oiseaux », annexe I) et autres espèces (directive « Habitats »,
            annexe II, éventuellement prioritaires).
        </p>
        <div v-if="especes.length > 0" class="plate">
            <table>
                <thead>
                    <tr><th>Espèce</th><th>Nom vernaculaire</th><th>Directive</th><th>Annexe</th></tr>
                </thead>
                <tbody>
                    <tr v-for="e in especes" :key="e.code_n2000">
                        <td>
                            <em>{{ e.nom_scientifique || '—' }}</em>
                            <span v-if="presenceLabel(e.presences)" class="hint"> ({{ presenceLabel(e.presences) }})</span>
                        </td>
                        <td>{{ e.nom_vernaculaire || '—' }}</td>
                        <td>{{ e.code_n2000.startsWith('A') ? 'Oiseaux' : 'Habitats' }}</td>
                        <td>
                            <template v-if="e.code_n2000.startsWith('A')">
                                <span v-if="e.annexe_1" class="badge natura">I</span>
                                <span v-else class="hint">—</span>
                            </template>
                            <template v-else>
                                <span v-if="e.annexe_ii" class="badge natura">II</span>
                                <span v-if="e.prioritaire" class="badge prio">prioritaire</span>
                                <span v-if="!e.annexe_ii && !e.prioritaire" class="hint">—</span>
                            </template>
                        </td>
                    </tr>
                </tbody>
            </table>
            <ListePager :pagination="paginationEspeces" mot="espèces" :only="['especes', 'nbEspeces', 'paginationEspeces']" />
            <p class="hint">
                Source : base Natura 2000 INPN · MNHN (formulaire standard de données)<template v-if="versionNatura"> — {{ versionNatura }}</template>.
                Le document d'objectifs (DOCOB) du site reste consultable sur la
                <a :href="site.url_inpn" target="_blank" rel="noopener">fiche INPN du site ↗</a>.
            </p>
        </div>
        <p v-else class="lead">Aucune espèce des directives recensée sur ce site.</p>

        <p class="attribution">
            Données Natura 2000 : INPN · MNHN (nomenclature Natura 2000, directive Habitats et
            directive Oiseaux) — réutilisées sous les
            <a href="https://inpn.mnhn.fr/legal" target="_blank" rel="noopener">licences de l'INPN ↗</a>,
            jamais présentées comme Licence Ouverte 2.0 (conditions affichées sur la page
            « Données &amp; méthodologie »).
        </p>
    </Layout>
</template>
