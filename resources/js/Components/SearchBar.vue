<script setup>
import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, ref, watch } from 'vue';

/**
 * Searchbar du portail avec autocomplétion (liste déroulante dynamique) :
 * dès 3 lettres tapées, interroge /recherche/suggestions (debounce 250 ms,
 * requête annulable, cache 5 min côté serveur) et propose les
 * correspondances (communes, espèces, ZNIEFF, Natura 2000, aires
 * protégées, département). Navigation clavier (↑/↓/Entrée/Échap),
 * clic ou « Tous les résultats » mènent à la page de recherche complète.
 *
 * Sans JS (ou SSR), le formulaire GET natif vers `action` fonctionne
 * tel quel : la searchbar reste utilisable, seule la liste dynamique
 * est absente. Les espèces suggérées sont déjà filtrées côté serveur
 * (jamais de confidentielle) ; une espèce sensible SINP n'est suggérée
 * que par son nom, en lien externe vers la fiche INPN (item « externe »,
 * ouvert dans un nouvel onglet — jamais de donnée de présence).
 */
const props = defineProps({
    action: { type: String, default: '/recherche' },
    placeholder: { type: String, default: "Nom d'une commune, d'un département, n° de ZNIEFF ou nom d'espèce…" },
    value: { type: String, default: '' },
    label: { type: String, default: 'Recherche' },
});

const q = ref(props.value);
const ouvert = ref(false);
const chargement = ref(false);
const suggestions = ref([]); // [{ type, label, sous, url, externe? }]
const actif = ref(-1);

const typeLabel = {
    dept: 'Département',
    commune: 'Commune',
    espece: 'Espèce',
    espece_sensible: 'Espèce sensible',
    znieff: 'ZNIEFF',
    natura: 'Natura 2000',
    ap: 'Aire protégée',
};

let minuteur = null;
let requete = null; // AbortController

watch(q, (valeur) => {
    if (minuteur) clearTimeout(minuteur);
    if (requete) requete.abort();
    if (import.meta.env.SSR) return;

    const terme = valeur.trim();
    if (terme.length < 3) {
        suggestions.value = [];
        ouvert.value = false;
        actif.value = -1;
        return;
    }

    minuteur = setTimeout(async () => {
        requete = new AbortController();
        chargement.value = true;
        try {
            const reponse = await fetch(`/recherche/suggestions?q=${encodeURIComponent(terme)}`, {
                signal: requete.signal,
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!reponse.ok) throw new Error(`HTTP ${reponse.status}`);
            const data = await reponse.json();
            // on ne rouvre que si le terme tapé est toujours le même
            if (terme !== q.value.trim()) return;
            suggestions.value = Array.isArray(data.sections) ? data.sections : [];
            ouvert.value = suggestions.value.length > 0;
            actif.value = ouvert.value ? 0 : -1;
        } catch (e) {
            if (e.name !== 'AbortError') {
                suggestions.value = [];
                ouvert.value = false;
            }
        } finally {
            chargement.value = false;
        }
    }, 250);
});

const ferme = () => {
    ouvert.value = false;
    actif.value = -1;
};

const choisir = (item) => {
    if (!item || !item.url) return;
    ferme();
    // item « externe » (ex. espèce sensible → fiche INPN) : nouvel onglet,
    // jamais de navigation interne (pas de fiche /espece pour un sensible)
    if (item.externe) {
        window.open(item.url, '_blank', 'noopener');
    } else {
        router.visit(item.url);
    }
};

const tousLesResultats = () => {
    ferme();
    // &tous=1 : jamais de redirection vers la fiche d'une correspondance
    // exacte — on veut bien la page de TOUS les résultats.
    router.visit(`${props.action}?q=${encodeURIComponent(q.value.trim())}&tous=1`);
};

// Nombre d'items navigables : les suggestions + le pied « Tous les résultats ».
const totalItems = () => suggestions.value.length + 1;

const auClavier = (e) => {
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        if (!ouvert.value || totalItems() === 0) return;
        e.preventDefault();
        const n = totalItems();
        actif.value = e.key === 'ArrowDown' ? (actif.value + 1) % n : (actif.value - 1 + n) % n;
    } else if (e.key === 'Enter') {
        if (ouvert.value && actif.value >= 0 && actif.value < totalItems()) {
            e.preventDefault();
            if (actif.value === suggestions.value.length) {
                tousLesResultats(); // pied de liste « Tous les résultats »
            } else {
                choisir(suggestions.value[actif.value]);
            }
        }
        // sinon : submit natif du formulaire (GET action?q=…)
    } else if (e.key === 'Escape') {
        ferme();
    }
};

onBeforeUnmount(() => {
    if (minuteur) clearTimeout(minuteur);
    if (requete) requete.abort();
});
</script>

<template>
    <form class="searchbar searchbar-wrap" method="get" :action="action" @submit="ferme">
        <div class="champ">
            <input
                type="search"
                name="q"
                v-model="q"
                @keydown="auClavier"
                @blur="ferme"
                :placeholder="placeholder"
                :aria-label="label"
                role="combobox"
                aria-autocomplete="list"
                aria-controls="liste-suggestions"
                :aria-expanded="ouvert"
                autocomplete="off"
            />
            <ul v-if="ouvert && suggestions.length" id="liste-suggestions" class="suggestions" role="listbox" :aria-label="`Suggestions pour « ${q.trim()} »`">
                <li
                    v-for="(s, i) in suggestions"
                    :key="s.type + '|' + s.url"
                    role="option"
                    :aria-selected="i === actif"
                    :class="{ actif: i === actif }"
                    @mousedown.prevent="choisir(s)"
                    @mousemove="actif = i"
                >
                    <span class="s-type">{{ typeLabel[s.type] ?? s.type }}</span>
                    <span class="s-label">
                        <em v-if="s.type === 'espece' || s.type === 'espece_sensible'">{{ s.label }}</em>
                        <template v-else>{{ s.label }}</template>
                        <small v-if="s.sous"> · {{ s.sous }}</small>
                    </span>
                </li>
                <li
                    class="s-tout"
                    role="option"
                    :aria-selected="actif === suggestions.length"
                    :class="{ actif: actif === suggestions.length }"
                    @mousedown.prevent="tousLesResultats"
                    @mousemove="actif = suggestions.length"
                >
                    Tous les résultats pour « {{ q.trim() }} » →
                </li>
            </ul>
        </div>
        <button class="btn" type="submit">Rechercher</button>
    </form>
</template>
