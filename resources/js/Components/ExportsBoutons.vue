<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

/**
 * Boutons d'export de la liste filtrée (itération d) : « Export CSV ·
 * … » et « Export GeoJSON · … » de la maquette, plus le rappel des
 * conditions de réutilisation INPN (jamais LO 2.0 pour ces données,
 * attribution MNHN).
 *
 * Le GeoJSON est volumineux : il exige un périmètre (département ou
 * recherche) ; le CSV complet de France reste toujours disponible.
 */
const props = defineProps({
    action: { type: String, required: true },
    filtres: { type: Object, required: true }, // { q, dept, type? }
    quoi: { type: String, required: true }, // « liste des ZNIEFF filtrée »…
});

const href = (format) => {
    const params = new URLSearchParams();
    if (props.filtres.q) params.set('q', props.filtres.q);
    if (props.filtres.dept) params.set('dept', props.filtres.dept);
    if (props.filtres?.type) params.set('type', props.filtres.type);
    params.set('export', format);

    return `${props.action}?${params.toString()}`;
};

const geojsonPossible = computed(() => Boolean(props.filtres.q || props.filtres.dept || props.filtres?.type));
</script>

<template>
    <div class="exports">
        <a class="btn ghost" :href="href('csv')">Export CSV · {{ quoi }}</a>
        <a v-if="geojsonPossible" class="btn ghost" :href="href('geojson')">Export GeoJSON · {{ quoi }}</a>
        <span v-else class="btn ghost est-desactive" title="Le GeoJSON exige un périmètre (département ou recherche) — le CSV complet reste disponible">
            Export GeoJSON · {{ quoi }}
        </span>
        <Link class="btn ghost" :href="route('donnees.index')">Conditions de réutilisation</Link>
    </div>
</template>
