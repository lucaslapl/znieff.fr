<script setup>
import { Link } from '@inertiajs/vue3';
import Layout from '../Components/Layout.vue';

/**
 * Accueil (itération d) : hero + searchbar (recherche transverse),
 * compteurs factuels du référentiel courant, « Trois portes d'entrée »
 * et accès par département (102 départements du COG, métropole et
 * outre-mer) — fidèle au prototype validé.
 */
const props = defineProps({
    pageTitre: { type: String, required: true },
    departements: { type: Object, required: true },
    stats: { type: Object, required: true },
});

const depts = Object.entries(props.departements)
    .map(([code, nom]) => ({ code, nom }))
    .sort((a, b) => a.code.localeCompare(b.code));

const nombre = (n) => n.toLocaleString('fr-FR');
</script>

<template>
    <Layout :titre="null">
        <section class="hero">
            <h1>Toutes les ZNIEFF de votre commune, sur une seule fiche.</h1>
            <p>
                Le portail réorganise l'inventaire ZNIEFF et les sites Natura 2000
                <strong>par commune</strong>, pas seulement par zone : cherchez votre commune
                et retrouvez son patrimoine naturel, les périmètres qui la concernent et
                les documents de référence.
            </p>
            <form class="searchbar" method="get" action="/recherche">
                <input type="search" name="q" placeholder="Nom d'une commune, d'un département, ou n° de ZNIEFF…" aria-label="Recherche" />
                <button class="btn" type="submit">Rechercher</button>
            </form>
            <p class="hint">
                Exemples : « Louviers », « Eure », « 8526 ».
                <Link :href="route('communes.index')" style="color:#ecd9a8">Parcourir par commune →</Link>
            </p>
        </section>

        <div class="stat-grid">
            <div class="stat"><div class="num">{{ nombre(stats.communes) }}</div><div class="lab">communes couvertes par le référentiel courant</div></div>
            <div class="stat"><div class="num">{{ nombre(stats.znieff) }}</div><div class="lab">zonages ZNIEFF (types I et II)</div></div>
            <div class="stat"><div class="num">{{ nombre(stats.natura) }}</div><div class="lab">sites Natura 2000</div></div>
            <div class="stat"><div class="num">{{ nombre(stats.aires) }}</div><div class="lab">aires protégées</div></div>
            <div class="stat"><div class="num">{{ nombre(stats.types_aires) }}</div><div class="lab">types de désignation</div></div>
            <div class="stat"><div class="num">1×/an</div><div class="lab">rafraîchissement des référentiels</div></div>
        </div>

        <h2>Trois portes d'entrée</h2>
        <div class="cards">
            <div class="card">
                <Link class="stretched" :href="route('communes.index')">Par commune →</Link>
                <p>Le cœur du portail. Tapez un nom de commune, un code INSEE ou un code postal, et obtenez la fiche complète : ZNIEFF, Natura 2000, superficies, population, lien vers l'éventuel ABC de la commune.</p>
            </div>
            <div class="card">
                <Link class="stretched" :href="route('znieff.index')">Par zonage ZNIEFF →</Link>
                <p>Depuis un identifiant de ZNIEFF : le périmètre, les communes concernées avec les surfaces recouvertes — le point fort du portail.</p>
            </div>
            <div class="card">
                <Link class="stretched" :href="route('natura.index')">Par site Natura 2000 →</Link>
                <p>Chaque site SIC / ZPS avec ses communes et ses superficies (les espèces et habitats restent sur la fiche INPN, jamais rediffusés).</p>
            </div>
            <div class="card">
                <Link class="stretched" :href="route('ap.index')">Par aire protégée →</Link>
                <p>Réserves naturelles nationales et régionales, arrêtés préfectoraux de protection, réserves biologiques : le catalogue INPN, croisé aux communes. Une protection juridique, pas seulement un inventaire.</p>
            </div>
            <div class="card">
                <a class="stretched" href="https://observatoire-abc.fr" target="_blank" rel="noopener">Un portail de la même équipe que l'Observatoire des ABC ↗</a>
                <p>Le portail est édité par la même équipe que l'Observatoire des ABC et réutilise son pipeline de collecte INPN. Même charte graphique, mêmes principes : faits bruts, sources créditées, données réutilisables sous leurs conditions.</p>
            </div>
        </div>

        <h2>Par département</h2>
        <p class="lead">
            Toutes les communes de France métropolitaine et d'outre-mer ont une fiche,
            y compris celles sans zonage : leur fiche le dit explicitement,
            ce qui évite les pages vides.
        </p>
        <div class="dept-grid">
            <Link
                v-for="d in depts"
                :key="d.code"
                class="dept"
                :href="`/communes?dept=${d.code}`"
            >
                <b>{{ d.code }}</b><span>{{ d.nom }}</span>
            </Link>
        </div>

        <p class="attribution">
            Données : INPN · MNHN (conditions de réutilisation affichées sur chaque fiche). Référentiels : IGN, INSEE, geo.api.gouv.fr.
        </p>
    </Layout>
</template>
