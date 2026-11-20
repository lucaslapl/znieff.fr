<script setup>
import { Head, Link } from '@inertiajs/vue3';
import Layout from '../../Components/Layout.vue';
import CarteInteractive from '../../Components/CarteInteractive.vue';

const props = defineProps({
    meta: { type: Object, required: true },
    dept: { type: String, default: null },
    deptNom: { type: String, default: null },
    departements: { type: Object, required: true },
    focus: { type: Object, default: null },
});

/**
 * Sélecteur de département : formulaire GET simple (sobriété, la carte
 * recharge avec ?dept= ; pas de mutation JS superflue).
 */
const options = Object.entries(props.departements)
    .map(([code, nom]) => ({ code, nom }))
    .sort((a, b) => a.code.localeCompare(b.code));
</script>

<template>
    <Layout :titre="meta.title">
        <Head>
            <title>{{ meta.title }} · Portail ZNIEFF</title>
            <meta name="description" :content="meta.description" />
            <link rel="canonical" :href="meta.canonical" />
        </Head>

        <h1>{{ deptNom ? `Carte interactive du patrimoine naturel — ${deptNom} (${dept})` : 'Carte interactive du patrimoine naturel' }}</h1>
        <p class="lead">
            Surcouches précalculées par département : ZNIEFF de type I et II, sites Natura 2000,
            aires protégées et contours communaux. Aucune géométrie n'est calculée en ligne —
            les fichiers GeoJSON sont générés à l'import annuel du portail.
            <template v-if="focus?.famille === 'commune'">
                Cadrage sur la commune ; les périmètres qui la touchent sont visibles.
            </template>
        </p>

        <form method="get" action="/carte" class="searchbar" style="margin-top:1rem">
            <label for="dept-select" class="hint" style="margin-right:.6rem">Département :</label>
            <select id="dept-select" name="dept" :value="dept ?? ''" style="flex:0 1 auto">
                <option v-if="!dept" value="">— Choisir un département —</option>
                <option v-for="o in options" :key="o.code" :value="o.code">{{ o.code }} — {{ o.nom }}</option>
            </select>
            <button class="btn" type="submit">Afficher</button>
        </form>

        <template v-if="dept">
            <CarteInteractive :dept="dept" :focus="focus" />

            <p v-if="focus" class="hint">
                <Link v-if="focus.famille === 'commune'" :href="route('commune.show', focus.cle)">← Fiche de la commune</Link>
                <Link v-else :href="route('znieff.show', focus.cle)">← Fiche du zonage</Link>
            </p>
            <p class="attribution">
                Périmètres : INPN (MNHN) — conditions de réutilisation INPN, jamais LO 2.0.
                Contours communaux : AdminExpress (IGN). Petites zones (moins d'un hectare) :
                rendues par un point, surface affichée sur leur fiche.
            </p>
        </template>
        <p v-else class="lead" style="margin-top:1.5rem">
            Choisissez un département ci-dessus pour afficher les zonages : ZNIEFF I et II,
            Natura 2000, aires protégées. Chaque périmètre est cliquable et renvoie vers sa
            fiche ; chaque contour communal vers la fiche de la commune.
        </p>
    </Layout>
</template>
