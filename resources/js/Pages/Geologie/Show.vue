<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Layout from '../../Components/Layout.vue';
import ListePager from '../../Components/ListePager.vue';
import MiniCarte from '../../Components/MiniCarte.vue';

// Fiche d'un site INPG (patrimoine géologique). SSR strict : toutes les
// props lues sont déclarées ici.
const props = defineProps({
    meta: { type: Object, required: true },
    site: { type: Object, required: true },
    chapeau: { type: String, required: true },
    interets: { type: Array, required: true },
    zonageRefs: { type: Array, required: true },
    strati: { type: Array, required: true },
    documents: { type: Array, required: true },
    lieudits: { type: Array, required: true },
    notes: { type: Array, required: true },
    biblio: { type: Array, required: true },
    accessibilites: { type: Array, required: true },
    typologies: { type: Array, required: true },
    cartes: { type: Array, required: true },
    communes: { type: Array, required: true },
    nbCommunes: { type: Number, required: true },
    pagination: { type: Object, required: true },
});

const page = usePage();
const versionInpg = computed(() => page.props.site?.referentiels?.inpg?.version ?? '');
const onlyCommunes = ['communes', 'nbCommunes', 'pagination'];

const lienZonage = (lien) => (lien ? (lien.type === 'znieff' ? route('znieff.show', lien.id) : route('natura.show', lien.id)) : null);
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
                <p><span class="hint">Site INPG {{ site.identifiant_metier }}</span></p>
                <div class="chapeau">{{ chapeau }}</div>
                <div class="plate">
                    <h2 style="border:none; margin-top:0">Caractéristiques</h2>
                    <dl class="kv">
                        <dt>Superficie</dt><dd>{{ site.superficie_ha }}<template v-if="site.unite_superficie"> ({{ site.unite_superficie }})</template><small v-if="site.justif_superficie"> — {{ site.justif_superficie }}</small></dd>
                        <dt v-if="site.etat">État général</dt><dd v-if="site.etat">{{ site.etat }}</dd>
                        <dt v-if="site.rarete">Rareté</dt><dd v-if="site.rarete">{{ site.rarete }}</dd>
                        <dt v-if="site.phenom_geol">Phénomène géologique</dt><dd v-if="site.phenom_geol">{{ site.phenom_geol }}</dd>
                        <dt v-if="accessibilites.length > 0">Accessibilité</dt><dd v-if="accessibilites.length > 0">{{ accessibilites.join(', ') }}</dd>
                        <dt v-if="typologies.length > 0">Typologie</dt><dd v-if="typologies.length > 0">{{ typologies.join(', ') }}</dd>
                        <dt v-if="site.gilges">Gîtes</dt><dd v-if="site.gilges">{{ site.gilges }}</dd>
                        <dt v-if="lieudits.length > 0">Lieux-dits</dt><dd v-if="lieudits.length > 0">{{ lieudits.join(', ') }}</dd>
                        <dt v-if="site.date_crea_site">Première visite</dt><dd v-if="site.date_crea_site">{{ site.date_crea_site }}</dd>
                        <dt v-if="site.date_der_visite">Dernière visite</dt><dd v-if="site.date_der_visite">{{ site.date_der_visite }}</dd>
                        <dt v-if="site.date_modif">Dernière modification</dt><dd v-if="site.date_modif">{{ site.date_modif }}</dd>
                        <dt v-if="site.ref_carto">Carte géologique</dt><dd v-if="site.ref_carto">{{ site.ref_carto }}</dd>
                        <dt v-if="cartes.length > 0">Cartes de référence</dt>
                        <dd v-if="cartes.length > 0">
                            <span v-for="c in cartes" :key="c.type_carte + c.carte" class="hint" style="margin-right:.6em">{{ c.carte }}</span>
                        </dd>
                        <dt>Fiche INPN</dt>
                        <dd><a :href="site.url_inpn" target="_blank" rel="noopener">Consulter la fiche INPN ↗</a></dd>
                    </dl>
                </div>

                <div v-if="site.present_succincte" class="plate">
                    <h2 style="border:none; margin-top:0">Présentation</h2>
                    <p>{{ site.present_succincte }}</p>
                </div>
                <div v-if="site.descr_geol" class="plate">
                    <h2 style="border:none; margin-top:0">Description géologique</h2>
                    <div v-html="site.descr_geol"></div>
                </div>
                <div v-if="site.descr_phys" class="plate">
                    <h2 style="border:none; margin-top:0">Description physique</h2>
                    <div v-html="site.descr_phys"></div>
                </div>
                <div v-if="site.itineraire" class="plate">
                    <h2 style="border:none; margin-top:0">Itinéraire d'accès</h2>
                    <p>{{ site.itineraire }}</p>
                </div>
            </div>
            <aside class="fiche-carte">
                <MiniCarte :couches="[{ url: site.geojson, famille: 'inpg', href: route('geologie.show', site.identifiant_metier), label: site.nom }]" :hauteur="320" />
                <p class="hint">Périmètre simplifié pour l'affichage (INPG, INPN · MNHN).</p>
            </aside>
        </div>

        <h2 id="interets">Intérêts géologiques ({{ interets.length }})</h2>
        <div v-if="interets.length > 0" class="plate">
            <table>
                <tbody>
                    <tr v-for="(i, idx) in interets" :key="idx">
                        <td>
                            <strong>{{ i.interet }}</strong><template v-if="i.interet_sup"> <span class="hint">({{ i.interet_sup }})</span></template>
                            <template v-if="i.justif"><br /><small>{{ i.justif }}</small></template>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p v-else class="lead">Aucun intérêt géologique recensé pour ce site dans l'INPG.</p>

        <h2 id="strati">Stratigraphie</h2>
        <div v-if="strati.length > 0" class="plate">
            <table>
                <thead><tr><th>Étage</th><th>Âge inférieur (Ma)</th><th>Âge supérieur (Ma)</th></tr></thead>
                <tbody>
                    <tr v-for="(s, idx) in strati" :key="idx">
                        <td>{{ s.strati }}</td>
                        <td>{{ s.age_inf ?? '—' }}</td>
                        <td>{{ s.age_sup ?? '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <h2 id="zonages">Zonages réglementaires liés</h2>
        <div v-if="zonageRefs.length > 0" class="plate">
            <ul class="communes-liste">
                <li v-for="(z, idx) in zonageRefs" :key="idx">
                    {{ z.zonage_ref }}<template v-if="z.reference"> — {{ z.reference }}</template>
                    <template v-if="z.lien"> · <Link :href="lienZonage(z.lien)">voir sur le portail →</Link></template>
                    <template v-if="z.commentaire"><br /><small class="hint">{{ z.commentaire }}</small></template>
                </li>
            </ul>
        </div>
        <p v-else class="lead">Aucun zonage réglementaire lié recensé pour ce site.</p>

        <div v-if="site.vuln_nat || site.menace_anthrop || site.suivi_protec || site.comment_gnrl_menace || site.comment_eval" class="plate">
            <h2>Évaluation, vulnérabilité et menaces</h2>
            <dl class="kv">
                <dt v-if="site.comment_eval">Commentaire d'évaluation</dt><dd v-if="site.comment_eval">{{ site.comment_eval }}</dd>
                <dt v-if="site.vuln_nat">Vulnérabilité naturelle</dt><dd v-if="site.vuln_nat">{{ site.vuln_nat }}</dd>
                <dt v-if="site.menace_anthrop">Menaces anthropiques</dt><dd v-if="site.menace_anthrop">{{ site.menace_anthrop }}</dd>
                <dt v-if="site.suivi_protec">Suivi / protection</dt><dd v-if="site.suivi_protec">{{ site.suivi_protec }}</dd>
                <dt v-if="site.comment_gnrl_menace">Commentaire général</dt><dd v-if="site.comment_gnrl_menace">{{ site.comment_gnrl_menace }}</dd>
            </dl>
        </div>

        <div v-if="notes.length > 0" class="plate">
            <h2 style="border:none; margin-top:0">Notes</h2>
            <dl class="kv">
                <template v-for="(n, idx) in notes" :key="idx">
                    <dt>{{ n.type_note }}<template v-if="n.note_pond !== null"> ({{ n.note_pond }})</template></dt>
                    <dd>{{ n.note }}</dd>
                </template>
            </dl>
        </div>

        <div v-if="documents.length > 0" class="plate">
            <h2 style="border:none; margin-top:0">Documents ({{ documents.length }})</h2>
            <ul class="communes-liste">
                <li v-for="(d, idx) in documents" :key="idx">
                    <strong v-if="d.lb_doc">{{ d.lb_doc }}</strong><span v-if="d.type_doc" class="hint"> — {{ d.type_doc }}</span>
                    <template v-if="d.legende"><br /><small>{{ d.legende }}</small></template>
                </li>
            </ul>
        </div>

        <div v-if="biblio.length > 0" class="plate">
            <h2 style="border:none; margin-top:0">Bibliographie ({{ biblio.length }})</h2>
            <ul class="communes-liste">
                <li v-for="(b, idx) in biblio" :key="idx"><small>{{ b }}</small></li>
            </ul>
        </div>

        <h2>Communes concernées</h2>
        <p class="lead">{{ nbCommunes }} commune{{ nbCommunes > 1 ? 's' : '' }} intersectée{{ nbCommunes > 1 ? 's' : '' }} par le site.</p>
        <div v-if="communes.length > 0" class="plate">
            <table>
                <thead>
                    <tr><th>Commune</th><th>Superficie concernée</th><th>Part du site</th><th>Territoire communal couvert</th></tr>
                </thead>
                <tbody>
                    <tr v-for="c in communes" :key="c.slug">
                        <td><Link :href="route('commune.show', c.slug)">{{ c.nom }}</Link> <small>({{ c.dept }})</small></td>
                        <td>{{ c.surface_ha !== null ? c.surface_ha.toLocaleString('fr-FR') + ' ha' : '—' }}</td>
                        <td>{{ c.part_site !== null ? c.part_site.toLocaleString('fr-FR') + ' %' : '—' }}</td>
                        <td><strong>{{ c.part_commune !== null ? c.part_commune.toLocaleString('fr-FR') + ' %' : '—' }}</strong></td>
                    </tr>
                </tbody>
            </table>
            <ListePager :pagination="pagination" mot="communes" :only="onlyCommunes" />
            <p class="hint">
                Surfaces d'intersection calculées par le pipeline du portail (géométrie INPG × contours
                communaux IGN AdminExpress, en Lambert-93).
            </p>
        </div>

        <p class="attribution">
            Données : inventaire national du patrimoine géologique (INPG) — INPN · MNHN<template v-if="versionInpg"> — {{ versionInpg }}</template>,
            réutilisées sous les
            <a href="https://inpn.mnhn.fr/legal" target="_blank" rel="noopener">licences de l'INPN ↗</a>,
            jamais présentées comme Licence Ouverte 2.0. Sites publics uniquement (niveau de diffusion INPG).
        </p>
    </Layout>
</template>
