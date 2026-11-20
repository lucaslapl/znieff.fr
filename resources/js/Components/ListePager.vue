<script setup>
/**
 * Pagination serveur (itération d) : 25 résultats par page, les liens
 * portent le filtre courant (appends) — simples allers-retours GET,
 * crawlables, aucun état client.
 */
defineProps({
    pagination: { type: Object, required: true }, // { total, page, derniere, url_precedente, url_suivante }
    mot: { type: String, default: 'résultats' }, // communes, zonages, sites, aires
});
</script>

<template>
    <nav v-if="pagination.total > 0" class="pager" aria-label="Pagination">
        <a v-if="pagination.url_precedente" class="btn ghost" :href="pagination.url_precedente">← Page précédente</a>
        <span>
            Page {{ pagination.page }} sur {{ pagination.derniere }} ·
            {{ pagination.total.toLocaleString('fr-FR') }} {{ mot }}
        </span>
        <a v-if="pagination.url_suivante" class="btn ghost" :href="pagination.url_suivante">Page suivante →</a>
    </nav>
</template>
