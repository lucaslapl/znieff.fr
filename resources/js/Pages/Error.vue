<script setup>
import { Link } from '@inertiajs/vue3';
import Layout from '../Components/Layout.vue';

defineProps({
    status: { type: Number, default: 404 },
    url: { type: String, default: '' },
});
</script>

<template>
    <Layout :titre="'Fiche introuvable'">
        <p class="breadcrumb"><a href="/">Accueil</a> › Introuvable</p>
        <h1>Fiche introuvable</h1>
        <p class="lead">
            {{ status === 404
                ? `Aucune page ne correspond à « ${url} » sur le Portail ZNIEFF.`
                : `Une erreur (${status}) est survenue.` }}
            <Link href="/">Retour à l'accueil</Link>.
        </p>
    </Layout>
</template>
