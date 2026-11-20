<script setup>
/**
 * Filtrage d'une liste (itération d) : formulaire GET natif, la
 * sobriété du prototype — pas d'état JS, l'URL porte le filtre
 * (partageable, crawlable, sans calcul côté client).
 *
 * Champs : recherche libre (q), département (référentiel COG du
 * portail), type optionnel (ZNIEFF I/II, SIC/ZPS, désignation d'aire
 * protégée).
 */
const props = defineProps({
    action: { type: String, required: true }, // /communes, /znieff, /natura, /ap
    q: { type: String, default: '' },
    dept: { type: String, default: null },
    type: { type: String, default: null },
    placeholder: { type: String, required: true },
    departements: { type: Object, required: true },
    types: { type: Object, default: null }, // { code: libellé } — masqué si absent
    bouton: { type: String, default: 'Filtrer' },
});

const depts = Object.entries(props.departements)
    .map(([code, nom]) => ({ code, nom }))
    .sort((a, b) => a.code.localeCompare(b.code));

const optionsType = props.types ? Object.entries(props.types).map(([code, nom]) => ({ code, nom })) : [];
</script>

<template>
    <form method="get" :action="action" class="searchbar" style="margin-top:1.1rem">
        <input type="search" name="q" :value="q" :placeholder="placeholder" aria-label="Rechercher" />
        <select name="dept" :value="dept ?? ''" aria-label="Département">
            <option value="">— Tous les départements —</option>
            <option v-for="d in depts" :key="d.code" :value="d.code">{{ d.code }} — {{ d.nom }}</option>
        </select>
        <select v-if="optionsType.length" name="type" :value="type ?? ''" aria-label="Type">
            <option value="">— Tous les types —</option>
            <option v-for="t in optionsType" :key="t.code" :value="t.code">{{ t.code }} — {{ t.nom }}</option>
        </select>
        <button class="btn" type="submit">{{ bouton }}</button>
    </form>
</template>
