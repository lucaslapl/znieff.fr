<script setup>
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    titre: { type: String, default: null },
});
</script>

<template>
    <div>
        <Head>
            <title>{{ titre ? `${titre} · Portail ZNIEFF` : 'Portail ZNIEFF — patrimoine naturel des communes de France' }}</title>
        </Head>

        <header class="site">
            <div class="brand">
                <svg width="30" height="30" viewBox="0 0 30 30" aria-hidden="true">
                    <rect width="30" height="30" rx="7" fill="#3d6b4a" />
                    <path d="M15 6c-3 2-5 5-5 8 0 3 2 6 5 8 3-2 5-5 5-8 0-3-2-6-5-8z" fill="#eaf2ea" />
                    <path d="M15 8v12M15 12l-2.5-2M15 14l2.5-2" stroke="#3d6b4a" stroke-width="1.1" fill="none" />
                </svg>
                <Link href="/">
                    Portail ZNIEFF
                    <small>Patrimoine naturel des communes de France</small>
                </Link>
            </div>
            <nav class="main">
                <Link href="/">Accueil</Link>
                <Link :href="route('communes.index')">Communes</Link>
                <Link :href="route('znieff.index')">ZNIEFF</Link>
                <Link :href="route('natura.index')">Natura 2000</Link>
                <Link :href="route('ap.index')">Aires protégées</Link>
                <Link :href="route('especes.index')">Espèces</Link>
                <Link :href="route('carte.index')">Carte</Link>
                <Link :href="route('donnees.index')">Données &amp; méthodologie</Link>
            </nav>
        </header>

        <main>
            <slot />
        </main>

        <footer class="site">
            <div class="inner">
                <div>
                    <strong>Portail ZNIEFF</strong> · znieff.fr<br />
                    Proposé par le <a :href="siteUrls.abc" target="_blank" rel="noopener">Observatoire des ABC</a>.
                    Mêmes principes : faits bruts, sources créditées, jamais d'indicateur composite.
                </div>
                <div>
                    Données ZNIEFF, Natura 2000 et aires protégées : INPN · MNHN (conditions de réutilisation affichées
                    sur chaque fiche et chaque export).<br />
                    Référentiels : IGN, INSEE, geo.api.gouv.fr.<br />
                    Versions : <template v-for="(ref, source, i) in referentiels" :key="source">{{ ref.version }}<template v-if="ref.importe_le"> ({{ ref.importe_le }})</template>{{ i + 1 < Object.keys(referentiels).length ? ' · ' : '' }}</template>
                </div>
                <div>
                    Fait avec sobriété : pas de traceur publicitaire.<br />
                    Audience anonyme, révocable à tout moment.
                </div>
            </div>
        </footer>
    </div>
</template>

<script>
export default {
    computed: {
        siteUrls() {
            return { abc: 'https://observatoire-abc.fr' };
        },
        referentiels() {
            // Prop `site` partagée par HandleInertiaRequests.
            return (this.$page.props.site?.referentiels ?? {});
        },
    },
};
</script>
