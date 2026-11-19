<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * Mini-carte Leaflet d'une fiche — affiche l'objet de la fiche (contour
 * communal ou périmètre d'un zonage) à partir d'un GeoJSON précalculé
 * servi statiquement. JAMAIS de calcul en ligne.
 *
 * - Leaflet n'est importé QUE côté client (SSR : le div garde son fond
 *   hachuré de la charte, aucune dépendance navigateur en PHP-FPM).
 * - Fonds au choix : OpenStreetMap ou Plan IGN v2 (WMTS libres de la
 *   Géoplateforme, sans clé).
 *
 * Props :
 *   - couches : [{ url: '/geo/…', famille: 'commune'|'z1'|'z2'|'nat'|'ap',
 *                  href?: '/commune/louviers', label?: 'Louviers' }]
 *   - hauteur : hauteur en pixels (défaut 240)
 *   - captation : true → liens cliquables (popups vers fiches)
 */
const props = defineProps({
    couches: { type: Array, required: true },
    hauteur: { type: Number, default: 240 },
});

const el = ref(null);
let map = null;
let couche = null;

onMounted(async () => {
    if (props.couches.length === 0) return;

    const L = (await import('leaflet')).default;
    await import('leaflet/dist/leaflet.css');
    const { basemaps, styleOf, styleCommune } = await import('../map/constants');

    map = L.map(el.value, {
        scrollWheelZoom: false,
        attributionControl: true,
    });

    const fonds = basemaps();
    const couchesFond = {
        'OpenStreetMap': L.tileLayer(fonds['OpenStreetMap'].url, fonds['OpenStreetMap'].options),
        'Plan IGN (Géoportail)': L.tileLayer(fonds['Plan IGN (Géoportail)'].url, fonds['Plan IGN (Géoportail)'].options),
    };
    couchesFond['OpenStreetMap'].addTo(map);
    L.control.layers(couchesFond, null, { collapsed: true }).addTo(map);

    let bornes = null;
    for (const c of props.couches) {
        const reponse = await fetch(c.url, { headers: { Accept: 'application/geo+json,application/json' } });
        if (!reponse.ok) continue;
        const geojson = await reponse.json();
        couche = L.geoJSON(geojson, {
            style: () => (c.famille === 'commune' ? styleCommune() : styleOf(c.famille)),
            pointToLayer: (famille => (feature, latlng) => {
                if (famille === 'commune') return L.marker(latlng);
                return L.circleMarker(latlng, {
                    radius: 6,
                    color: styleOf(c.famille).color,
                    weight: 1.5,
                    fillColor: styleOf(c.famille).fillColor,
                    fillOpacity: 0.85,
                });
            })(c.famille),
            onEachFeature: (feature, layer) => {
                if (!c.href) return;
                const nom = feature.properties?.nom ?? '';
                layer.bindPopup(
                    `<b>${nom}</b><br/><a href="${c.href}">Ouvrir la fiche →</a>`
                );
            },
        }).addTo(map);
        if (bornes === null) {
            bornes = couche.getBounds();
        } else {
            bornes.extend(couche.getBounds());
        }
    }
    if (bornes !== null && bornes.isValid()) {
        map.fitBounds(bornes, { padding: [14, 14] });
    } else {
        map.setView([46.6, 2.4], 5);
    }
});

onBeforeUnmount(() => {
    if (map !== null) {
        map.remove();
        map = null;
    }
});
</script>

<template>
    <div ref="el" class="mini-map mini-carte" :style="{ height: hauteur + 'px' }" role="img" aria-label="Carte du périmètre (source INPN)" />
</template>
