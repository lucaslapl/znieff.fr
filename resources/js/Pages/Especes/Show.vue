<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Layout from '../../Components/Layout.vue';
import ListePager from '../../Components/ListePager.vue';
import { classeLr, estStatutListeRouge, libelleLr } from '../../especes/listeRouge.js';

// Page fiche espèce (itération P1) : TAXREF v18 + statuts BDC v18 + ZNIEFF
// déterminantes. SSR strict : toutes les props lues sont déclarées ici.
const props = defineProps({
    meta: { type: Object, required: true },
    espece: { type: Object, required: true }, // { cd_ref, nom, vern, groupe, habitat, presence_fr, present_om, url_inpn }
    statuts: { type: Array, required: true }, // [{ regroupement, statuts: [{ type, type_label, code, label, territoire, remarque, doc_url }] }]
    zonages: { type: Array, required: true }, // [{ id, nom, famille }]
    nbZonages: { type: Number, required: true },
    pagination: { type: Object, required: true },
    communes: { type: Array, required: true }, // [{ insee, slug, nom, dept, reg_nom }]
    nbCommunes: { type: Number, required: true },
    paginationCommunes: { type: Object, required: true }, // ?page_communes=
    habitats: { type: Array, required: true }, // [{ cd_hab, cd_typo, typo, lb_code, nom_fr, type }] — HABREF
    nbHabitats: { type: Number, required: true },
    paginationHabitats: { type: Object, required: true }, // ?page_habitats=
});

const page = usePage();
const versionBdc = computed(() => page.props.site?.referentiels?.bdc?.version ?? '');
const versionTaxref = computed(() => page.props.site?.referentiels?.taxref?.version ?? '');
const onlyZonages = ['zonages', 'nbZonages', 'pagination'];
const onlyCommunes = ['communes', 'nbCommunes', 'paginationCommunes'];
const onlyHabitats = ['habitats', 'nbHabitats', 'paginationHabitats'];

const presenceFr = computed(() => {
    const p = props.espece.presence_fr;
    const libelles = { P: 'présent', E: 'endémique', S: 'subendémique', I: 'introduit', B: 'occasionnel', D: 'douteux', A: 'absent', W: 'disparu', X: 'éteint' };
    return p ? (libelles[p] ?? p) : null;
});

// Ligne de statut = Liste rouge UICN (BDC : LRN/LRE/LRM/LRR…) avec un code
// → badge colore (classe lr-cr, lr-en…) au lieu du simple code en hint.
const estListeRouge = (s, g) => s.code !== null && estStatutListeRouge(s.type, g.regroupement);
</script>

<template>
    <Layout :titre="meta.title">
        <Head>
            <title>{{ meta.title }} · Portail ZNIEFF</title>
            <meta name="description" :content="meta.description" />
            <link rel="canonical" :href="meta.canonical" />
        </Head>

        <div class="fiche-info">
            <h1>
                <template v-if="espece.vern">{{ espece.vern }} <em>({{ espece.nom }})</em></template>
                <em v-else>{{ espece.nom }}</em>
            </h1>
            <p class="hint">
                cd_ref TAXREF {{ espece.cd_ref }}<template v-if="espece.groupe"> · {{ espece.groupe }}</template>
            </p>

            <div class="plate">
                <h2 style="border:none; margin-top:0">Caractéristiques</h2>
                <dl class="kv">
                    <dt>Nom scientifique</dt><dd><em>{{ espece.nom }}</em></dd>
                    <dt v-if="espece.vern">Nom vernaculaire</dt><dd v-if="espece.vern">{{ espece.vern }}</dd>
                    <dt v-if="espece.groupe">Groupe taxonomique</dt><dd v-if="espece.groupe">{{ espece.groupe }}</dd>
                    <dt v-if="espece.habitat">Milieu de vie</dt><dd v-if="espece.habitat">{{ espece.habitat }}<template v-if="espece.present_om"> · présent aussi en outre-mer</template></dd>
                    <dt v-if="presenceFr">Statut en France métropolitaine</dt><dd v-if="presenceFr">{{ presenceFr }}</dd>
                    <dt v-if="espece.url_inpn">Fiche INPN du taxon</dt>
                    <dd v-if="espece.url_inpn"><a :href="espece.url_inpn" target="_blank" rel="noopener">Consulter la fiche INPN ↗</a></dd>
                </dl>
            </div>

            <h2>Statuts de protection et de réglementation ({{ statuts.reduce((n, g) => n + g.statuts.length, 0) }})</h2>
            <div v-if="statuts.length > 0">
                <div v-for="g in statuts" :key="g.regroupement" class="plate">
                    <h3 style="border:none; margin-top:0">{{ g.regroupement }}</h3>
                    <table>
                        <tbody>
                            <tr v-for="s in g.statuts" :key="s.type + '|' + (s.territoire ?? '')">
                                <td>
                                    <span v-if="estListeRouge(s, g)" class="badge" :class="classeLr(s.code)" :title="libelleLr(s.code)">{{ s.code }}</span>
                                    <strong>{{ s.type_label }}</strong>
                                    <span v-if="s.code && !estListeRouge(s, g)" class="hint"> · {{ s.code }}</span>
                                    <template v-if="s.label"><br /><small>{{ s.label }}</small></template>
                                    <template v-if="s.remarque"><br /><small class="hint">{{ s.remarque }}</small></template>
                                </td>
                                <td class="hint">{{ s.territoire }}<template v-if="s.doc_url"> · <a :href="s.doc_url" target="_blank" rel="noopener">texte ↗</a></template></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="hint">
                    Statuts issus de la Base de Connaissance (BDC) de l'INPN · MNHN<template v-if="versionBdc"> — {{ versionBdc }}</template>.
                </p>
            </div>
            <p v-else class="lead">Aucun statut de protection ou de réglementation recensé pour ce taxon dans la BDC INPN.</p>

            <h2 id="znieff">ZNIEFF déterminantes ({{ nbZonages }})</h2>
            <div v-if="zonages.length > 0" class="plate">
                <ul class="communes-liste">
                    <li v-for="z in zonages" :key="z.id">
                        <Link :href="route('znieff.show', z.id)">{{ z.nom }}</Link>
                        <small class="hint"> · ZNIEFF {{ z.famille === 'z1' ? 'I' : 'II' }} · {{ z.id }}</small>
                    </li>
                </ul>
                <ListePager :pagination="pagination" mot="ZNIEFF" :only="onlyZonages" />
                <p class="hint">
                    ZNIEFF du référentiel INPN citant ce taxon comme déterminant, hors espèces
                    confidentielles et hors sensibilité SINP du département de chaque zone.
                </p>
            </div>
            <p v-else class="lead">Aucune ZNIEFF du référentiel importé ne cite ce taxon comme déterminant.</p>

            <h2 id="communes">Communes où le taxon est présent ({{ nbCommunes }})</h2>
            <div v-if="communes.length > 0" class="plate">
                <ul class="communes-liste">
                    <li v-for="c in communes" :key="c.insee">
                        <Link :href="route('commune.show', c.slug)">{{ c.nom }}</Link>
                        <small class="hint"> · {{ c.dept }}<template v-if="c.reg_nom"> · {{ c.reg_nom }}</template> · INSEE {{ c.insee }}</small>
                    </li>
                </ul>
                <ListePager :pagination="paginationCommunes" mot="communes" :only="onlyCommunes" />
                <p class="hint">
                    Communes des ZNIEFF où le taxon est déterminant et des sites Natura 2000 qui le citent
                    (pivots précalculés à l'import — hors espèces confidentielles et hors sensibilité SINP).
                </p>
            </div>
            <p v-else class="lead">Aucune commune du référentiel importé ne porte ce taxon (ZNIEFF déterminante ou site Natura 2000).</p>

            <h2>Habitats caractéristiques (HABREF, {{ nbHabitats }})</h2>
            <div v-if="habitats.length > 0" class="plate">
                <ul class="communes-liste">
                    <li v-for="h in habitats" :key="h.cd_hab">
                        <Link :href="`/habitat/${h.cd_hab}`">{{ h.nom_fr }}</Link>
                        <small>({{ h.typo }}<template v-if="h.lb_code"> · {{ h.lb_code }}</template> · {{ h.type }})</small>
                    </li>
                </ul>
                <ListePager :pagination="paginationHabitats" mot="habitats" :only="onlyHabitats" />
            </div>
            <p v-else class="lead">Aucun habitat HABREF du référentiel importé n'a ce taxon comme espèce caractéristique.</p>

            <p class="attribution">
                Données : INPN · MNHN (TAXREF, inventaire ZNIEFF, Base de Connaissance des statuts) — réutilisées
                sous les <a href="https://inpn.mnhn.fr/legal" target="_blank" rel="noopener">licences de l'INPN ↗</a>,
                jamais présentées comme Licence Ouverte 2.0.
                Noms vernaculaires et groupes : TAXREF (MNHN)<template v-if="versionTaxref"> — {{ versionTaxref }}</template>.
            </p>
        </div>
    </Layout>
</template>
