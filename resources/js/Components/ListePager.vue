<script setup>
/**
 * Pagination serveur (itération d) : 25 résultats par page, les liens
 * portent le filtre courant (appends) — simples allers-retours GET,
 * crawlables, aucun état client.
 *
 * Mode partiel (fiches) : quand `only` est fourni, les liens deviennent
 * des <Link> Inertia qui ne rechargent que les props listées, sans
 * perdre la position de scroll — dégradés en <a> simples sans JS.
 */
import { Link } from '@inertiajs/vue3';

defineProps({
    pagination: { type: Object, required: true }, // { total, page, derniere, url_precedente, url_suivante }
    mot: { type: String, default: 'résultats' }, // communes, zonages, sites, aires
    only: { type: Array, default: null }, // ex. ['communes', 'pagination'] : rechargement partiel Inertia
});
</script>

<template>
    <nav v-if="pagination.total > 0" class="pager" aria-label="Pagination">
        <component
            :is="only ? Link : 'a'"
            v-if="pagination.url_precedente"
            class="btn ghost"
            :href="pagination.url_precedente"
            :only="only || undefined"
            preserve-scroll
        >← Page précédente</component>
        <span>
            Page {{ pagination.page }} sur {{ pagination.derniere }} ·
            {{ pagination.total.toLocaleString('fr-FR') }} {{ mot }}
        </span>
        <component
            :is="only ? Link : 'a'"
            v-if="pagination.url_suivante"
            class="btn ghost"
            :href="pagination.url_suivante"
            :only="only || undefined"
            preserve-scroll
        >Page suivante →</component>
    </nav>
</template>
