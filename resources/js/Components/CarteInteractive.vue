<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { COMMUNE_STYLE, FAMSTYLE, deptSurcouches } from '../map/constants';

/**
 * Carte interactive complète (page /carte et cadrages /carte/znieff/{id},
 * /carte/commune/{slug}) — itération c.
 *
 * - surcouches GeoJSON PRÉCALCULÉES d'un département (générées à
 *   l'import, jamais de calcul en ligne) : contours communaux, ZNIEFF I
 *   et II, Natura 2000, aires protégées ;
 * - fonds au choix : OpenStreetMap ou Plan IGN v2 (WMTS libres, sans clé) ;
 * - popups vers les fiches du portail (HTML brut : navigation complète) ;
 * - cadrage par identifiant de zone ou slug de commune : fitBounds
 *   (marge 0.35) + popup ouvert (maquette).
 *
 * constants.js est un module pur (importable en SSR) ; Leaflet, lui,
 * n'est importé QUE côté client (fenêtre / navigateur).
 */
const props = defineProps({
    dept: { type: String, required: true },
    focus: { type: Object, default: null }, // { famille: 'z1'|'z2'|'commune', cle: string }
    hauteur: { type: Number, default: 540 },
});

const el = ref(null);
const chargement = ref(true);
const couches = ref([]); // [{ nom, famille, legende: {fond, trait} }]

onMounted(async () => {
    const L = (await import('leaflet')).default;
    await import('leaflet/dist/leaflet.css');
    const {
        basemaps, styleOf, zonePopup, communePopup, pointStyle,
    } = await import('../map/constants');

    map = L.map(el.value, { scrollWheelZoom: true });
    const couchesFond = {};
    for (const [nom, fond] of Object.entries(basemaps())) {
        couchesFond[nom] = L.tileLayer(fond.url, fond.options);
    }
    couchesFond[Object.keys(couchesFond)[0]].addTo(map); // OpenStreetMap par défaut

    let bornesDept = null;
    const indexZones = new Map(); // id zone → couche Leaflet
    const indexCommunes = new Map(); // slug → couche Leaflet

    const layerControl = L.control.layers(couchesFond, null, { collapsed: false }).addTo(map);

    for (const s of deptSurcouches(props.dept)) {
        let donnees;
        try {
            const reponse = await fetch(s.url, { headers: { Accept: 'application/geo+json,application/json' } });
            if (!reponse.ok) continue;
            donnees = await reponse.json();
        } catch {
            continue; // surcouche absente : la carte reste utilisable
        }

        const estCommune = s.famille === 'commune';
        const layer = L.geoJSON(donnees, {
            style: feature => (estCommune ? COMMUNE_STYLE : styleOf(feature.properties?.famille ?? s.famille)),
            pointToLayer: (feature, latlng) => L.circleMarker(latlng, pointStyle(feature.properties?.famille ?? s.famille)),
            onEachFeature: (feature, flayer) => {
                const p = feature.properties ?? {};
                if (estCommune) {
                    flayer.bindPopup(communePopup({ nom: p.nom, slug: p.slug }));
                    if (p.slug) indexCommunes.set(String(p.slug), flayer);
                } else {
                    flayer.bindPopup(zonePopup({ famille: p.famille ?? s.famille, id: p.id, nom: p.nom }));
                    if (p.id) indexZones.set(String(p.id), flayer);
                }
            },
        });

        couches.value.push({ nom: s.label, famille: s.famille });
        layerControl.addOverlay(layer, s.label);
        layer.addTo(map);

        const bornes = layer.getBounds();
        if (bornes.isValid()) {
            bornesDept = bornesDept === null ? bornes : bornesDept.extend(bornes);
        }
    }

    // ---- Cadrage : focus demandé (zone ou commune), sinon département.
    let coucheFocus = null;
    if (props.focus) {
        coucheFocus = props.focus.famille === 'commune'
            ? indexCommunes.get(String(props.focus.cle)) ?? null
            : indexZones.get(String(props.focus.cle)) ?? null;
    }
    if (coucheFocus !== null) {
        map.fitBounds(coucheFocus.getBounds().pad(0.35));
        coucheFocus.openPopup();
    } else if (bornesDept !== null && bornesDept.isValid()) {
        map.fitBounds(bornesDept);
    } else {
        map.setView([46.6, 2.4], 5);
    }

    chargement.value = false;
});

onBeforeUnmount(() => {
    if (map !== null) {
        map.remove();
        map = null;
    }
});

/** Légende (charte herbier) : une pastille par surcouche chargée. */
const legende = computed(() => couches.value.map(c => ({
    nom: c.nom,
    famille: c.famille,
    style: c.famille === 'commune'
        ? null
        : { background: FAMSTYLE[c.famille][0], border: `1px solid ${FAMSTYLE[c.famille][1]}` },
})));
</script>

<script>
let map = null;
</script>

<template>
    <div>
        <div ref="el" id="carte-leaflet" :style="{ height: hauteur + 'px' }" />
        <div class="carte-legende">
            <span v-for="l in legende" :key="l.famille" class="item">
                <span class="pastille" :style="l.style" />{{ l.nom }}
            </span>
            <span class="item">
                Périmètres : INPN (MNHN) · contours communaux : AdminExpress (IGN) · fonds : OpenStreetMap / Plan IGN v2
            </span>
        </div>
        <p v-if="chargement" class="hint">Chargement des surcouches précalculées…</p>
    </div>
</template>
