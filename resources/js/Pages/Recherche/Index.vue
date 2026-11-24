<script setup>
import { Head, Link } from '@inertiajs/vue3';
import Layout from '../../Components/Layout.vue';
import BadgeFamille from '../../Components/BadgeFamille.vue';
import SearchBar from '../../Components/SearchBar.vue';

/**
 * Recherche transverse (itération d) : la searchbar de l'accueil mène
 * ici. Correspondance exacte = redirection directe vers la fiche
 * (commune prioritaire, puis ZNIEFF, Natura 2000, aire protégée,
 * espèce) ; sinon résultats groupés par section, 12
 * max, avec les liens vers les listes complètes filtrées.
 *
 * Page fonctionnelle : noindex, hors sitemap.
 */
defineProps({
    meta: { type: Object, required: true },
    q: { type: String, required: true },
    dept: { type: Object, default: null }, // { code, nom } si le terme désigne un département
    communes: { type: Array, required: true },
    zonages: { type: Array, required: true },
    natura: { type: Array, required: true },
    aires: { type: Array, required: true },
    especes: { type: Array, required: true }, // [{ cd_ref, nom, vern, groupe, nbZonages, nbSites }]
    especesSensibles: { type: Array, default: () => [] }, // [{ cd_ref, nom, vern, groupe, url_inpn }] — nom public uniquement, renvoi INPN, jamais de donnée de présence
    plus: { type: Object, required: true },
    total: { type: Number, required: true },
});

const zonagesTxt = (c) => {
    const parties = [];
    if (c.nbZonages > 0) parties.push(`${c.nbZonages} Z`);
    if (c.nbNatura > 0) parties.push(`${c.nbNatura} N2000`);
    if (c.nbAp > 0) parties.push(`${c.nbAp} AP`);

    return parties.join(' + ') || 'aucun';
};

// Fiche ABC de l'Observatoire : /commune/{code INSEE} (pas le slug — 404 sinon).
const abUrl = (insee) => `https://observatoire-abc.fr/commune/${insee}`;
</script>

<template>
    <Layout :titre="meta.title">
        <Head>
            <title>{{ meta.title }} · Portail ZNIEFF</title>
            <meta name="description" :content="meta.description" />
            <meta name="robots" content="noindex" />
        </Head>
        <h1>{{ q ? `Résultats de recherche « ${q} »` : 'Rechercher sur le portail' }}</h1>
        <p class="lead">
            Une commune par son nom (ou son code INSEE, son code postal), une ZNIEFF par son
            identifiant ou son nom, un site Natura 2000, une aire protégée, une espèce par
            son nom scientifique ou vernaculaire, ou un département.
            Une correspondance exacte mène directement à la fiche.
        </p>

        <SearchBar action="/recherche" :value="q" style="margin-top:1.1rem" />

        <div v-if="dept" class="plate">
            <h2 style="margin-top:0">Département</h2>
            <p>
                « {{ q }} » désigne le département <strong>{{ dept.nom }} ({{ dept.code }})</strong> :
                <Link :href="`/communes?dept=${dept.code}`">communes</Link> ·
                <Link :href="`/znieff?dept=${dept.code}`">ZNIEFF</Link> ·
                <Link :href="`/natura?dept=${dept.code}`">Natura 2000</Link> ·
                <Link :href="`/ap?dept=${dept.code}`">aires protégées</Link> ·
                <Link :href="`/carte?dept=${dept.code}`">carte</Link>.
            </p>
        </div>

        <template v-if="total === 0 && q !== ''">
            <div class="plate">
                <h2 style="margin-top:0">Aucun résultat pour « {{ q }} »</h2>
                <p>
                    Vérifiez l'orthographe, ou parcourez les
                    <Link :href="route('communes.index')">communes</Link> et les
                    <Link :href="route('znieff.index')">zonages ZNIEFF</Link> par département.
                    Les communes sans aucun zonage ont aussi leur fiche.
                </p>
            </div>
        </template>

        <template v-if="communes.length">
            <h2>Communes ({{ communes.length }}<template v-if="plus.communes">+</template>)</h2>
            <div class="plate">
                <table>
                    <thead><tr><th>Commune</th><th>Dept / Région</th><th>Population</th><th>Zonages</th><th>ABC</th></tr></thead>
                    <tbody>
                        <tr v-for="c in communes" :key="c.insee">
                            <td><Link :href="route('commune.show', c.slug)"><strong>{{ c.nom }}</strong></Link></td>
                            <td>{{ c.dept }}<span class="liste-note" v-if="c.reg_nom"> · {{ c.reg_nom }}</span></td>
                            <td>{{ c.population ?? '—' }}</td>
                            <td>{{ zonagesTxt(c) }}</td>
                            <td>
                                <a v-if="c.has_abc" :href="abUrl(c.insee)" target="_blank" rel="noopener" class="badge abc">ABC ↗</a>
                                <span v-else class="liste-note">pas d'ABC</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-if="plus.communes" class="liste-note">
                <Link :href="`/communes?q=${encodeURIComponent(q)}`">Toutes les communes correspondantes →</Link>
            </p>
        </template>

        <template v-if="zonages.length">
            <h2>Zonages ZNIEFF ({{ zonages.length }}<template v-if="plus.zonages">+</template>)</h2>
            <div class="plate">
                <table>
                    <thead><tr><th>Type</th><th>Zonage</th><th>Superficie</th><th>Communes</th></tr></thead>
                    <tbody>
                        <tr v-for="z in zonages" :key="z.id">
                            <td><BadgeFamille :classe="z.badge" :label="z.famille === 'z1' ? 'ZNIEFF I' : 'ZNIEFF II'" /></td>
                            <td>
                                <Link :href="route('znieff.show', z.id)"><strong>{{ z.nom }}</strong></Link>
                                <span class="liste-note"> — ID {{ z.id }}<template v-if="z.num_regional"> · ancien n° {{ z.num_regional }}</template></span>
                            </td>
                            <td>{{ z.superficie_ha ?? '—' }}</td>
                            <td>{{ z.nbCommunes }} commune{{ z.nbCommunes > 1 ? 's' : '' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-if="plus.zonages" class="liste-note">
                <Link :href="`/znieff?q=${encodeURIComponent(q)}`">Tous les zonages correspondants →</Link>
            </p>
        </template>

        <template v-if="natura.length">
            <h2>Sites Natura 2000 ({{ natura.length }}<template v-if="plus.natura">+</template>)</h2>
            <div class="plate">
                <table>
                    <thead><tr><th>Type</th><th>Site</th><th>Superficie</th><th>Communes</th></tr></thead>
                    <tbody>
                        <tr v-for="s in natura" :key="s.code">
                            <td><BadgeFamille classe="natura" :label="s.type" /></td>
                            <td>
                                <Link :href="route('natura.show', s.code)"><strong>{{ s.nom }}</strong></Link>
                                <span class="liste-note"> — {{ s.code }}</span>
                            </td>
                            <td>{{ s.superficie_ha ?? '—' }}</td>
                            <td>{{ s.nbCommunes }} commune{{ s.nbCommunes > 1 ? 's' : '' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-if="plus.natura" class="liste-note">
                <Link :href="`/natura?q=${encodeURIComponent(q)}`">Tous les sites correspondants →</Link>
            </p>
        </template>

        <template v-if="aires.length">
            <h2>Aires protégées ({{ aires.length }}<template v-if="plus.aires">+</template>)</h2>
            <div class="plate">
                <table>
                    <thead><tr><th>Type</th><th>Désignation</th><th>Superficie</th><th>Communes</th></tr></thead>
                    <tbody>
                        <tr v-for="a in aires" :key="a.id">
                            <td><BadgeFamille classe="ap" :label="a.type_code" /></td>
                            <td>
                                <Link :href="route('ap.show', a.id)"><strong>{{ a.nom }}</strong></Link>
                                <span class="liste-note"> — {{ a.id }}<template v-if="a.type_nom"> · {{ a.type_nom }}</template></span>
                            </td>
                            <td>{{ a.superficie_ha ?? '—' }}</td>
                            <td>{{ a.nbCommunes }} commune{{ a.nbCommunes > 1 ? 's' : '' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-if="plus.aires" class="liste-note">
                <Link :href="`/ap?q=${encodeURIComponent(q)}`">Toutes les aires protégées correspondantes →</Link>
            </p>
        </template>

        <template v-if="especes.length">
            <h2>Espèces ({{ especes.length }}<template v-if="plus.especes">+</template>)</h2>
            <div class="plate">
                <table>
                    <thead><tr><th>Espèce</th><th>Groupe</th><th>ZNIEFF déterminantes</th><th>Sites Natura 2000</th></tr></thead>
                    <tbody>
                        <tr v-for="e in especes" :key="e.cd_ref">
                            <td>
                                <Link :href="route('espece.show', e.cd_ref)"><em>{{ e.nom }}</em><template v-if="e.vern"> · {{ e.vern }}</template></Link>
                                <span class="liste-note"> — cd_ref {{ e.cd_ref }}</span>
                            </td>
                            <td>{{ e.groupe ?? '—' }}</td>
                            <td>{{ e.nbZonages }} ZNIEFF</td>
                            <td>{{ e.nbSites }} site{{ e.nbSites > 1 ? 's' : '' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="liste-note">
                Espèces du référentiel INPN (TAXREF) — chaque fiche liste les communes où le taxon est présent.
                Les données de présence des espèces sensibles ne sont jamais diffusées (voir ci-dessous) ;
                les espèces confidentielles ne sont jamais affichées.
                <Link :href="`/especes?q=${encodeURIComponent(q)}`">Toutes les espèces correspondantes →</Link>
            </p>
        </template>

        <template v-if="especesSensibles.length">
            <h2>Espèces sensibles ({{ especesSensibles.length }})</h2>
            <div class="plate">
                <p style="margin-top:0">
                    Ces espèces sont classées <strong>sensibles</strong> par le référentiel SINP de l'INPN :
                    leurs <strong>données de présence ne sont pas diffusées</strong> sur ce portail
                    (pas de zonage, pas de commune, pas de compteur). Consultez leur fiche INPN :
                </p>
                <ul>
                    <li v-for="e in especesSensibles" :key="e.cd_ref">
                        <em>{{ e.nom }}</em><template v-if="e.vern"> · {{ e.vern }}</template>
                        <span class="liste-note"> — {{ e.groupe ?? 'groupe non renseigné' }} · cd_ref {{ e.cd_ref }}</span>
                        — <a :href="e.url_inpn" target="_blank" rel="noopener">fiche INPN ↗</a>
                    </li>
                </ul>
            </div>
        </template>
    </Layout>
</template>
