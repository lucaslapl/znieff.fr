<script setup>
/**
 * Badge arrondi (charte herbier) : ZNIEFF I ocre, ZNIEFF II vert,
 * Natura 2000 bleu, aire protégée violette, ABC mauve.
 */
const props = defineProps({
    classe: { type: String, required: true }, // znieff1 | znieff2 | natura | ap | abc
    label: { type: String, required: true },
});
</script>

<template>
    <span class="badge" :class="classe">{{ label }}</span>
</template>
