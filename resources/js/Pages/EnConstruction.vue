<script setup>
import { Head } from '@inertiajs/vue3';
import Layout from '../Components/Layout.vue';

// Page squelette : section annoncée mais pas encore livrée (itérations suivantes).
// noindex jusqu'à ce que la vraie page existe (jamais dans le sitemap).

defineProps({
    titre: { type: String, required: true },
    description: { type: String, required: true },
});
</script>

<template>
    <Layout :titre="titre">
        <Head>
            <meta name="robots" content="noindex" />
        </Head>
        <p class="breadcrumb"><a href="/">Accueil</a> › {{ titre }}</p>
        <h1>{{ titre }}</h1>
        <p class="lead">{{ description }}</p>
        <div class="plate">
            <p>Cette section arrive dans une itération prochaine du portail. Le socle de données (référentiels INPN, croisements par commune) est en cours de construction — les versions des référentiels importés s'affichent en pied de page.</p>
        </div>
    </Layout>
</template>
