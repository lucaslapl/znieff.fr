<script setup>
import { Link } from '@inertiajs/vue3';
import Layout from '../Components/Layout.vue';

defineProps({
    pageTitre: { type: String, required: true },
});
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
            <form class="searchbar" onsubmit="event.preventDefault();">
                <input type="search" placeholder="Nom d'une commune, d'un département, ou n° de ZNIEFF…" aria-label="Recherche" />
                <button class="btn" type="submit">Rechercher</button>
            </form>
            <p class="hint">
                Exemples : « Louviers », « Eure », « 8526 ».
                <Link :href="route('communes.index')" style="color:#ecd9a8">Parcourir par commune →</Link>
            </p>
        </section>

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
        </div>

        <p class="attribution">
            Données : INPN · MNHN (conditions de réutilisation affichées sur chaque fiche). Référentiels : IGN, INSEE, geo.api.gouv.fr.
        </p>
    </Layout>
</template>
