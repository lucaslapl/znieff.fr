// Constantes cartographiques du Portail ZNIEFF — charte herbier de la
// maquette validée (FAMSTYLE). Module pur (aucune dépendance), testé par
// tests/js/carte.test.mjs : consommé par les mini-cartes de fiches
// (itération b) et la page Carte interactive (itération c).

export const FAMSTYLE = {
    z1: ['#c9963e', '#8a6415'],
    z2: ['#3d6b4a', '#24422e'],
    nat: ['#3a7ca5', '#234e6b'],
    ap: ['#7b4fa0', '#4a2d63'],
};

export const FAMLABEL = {
    z1: 'ZNIEFF I',
    z2: 'ZNIEFF II',
    nat: 'Natura 2000',
    ap: 'Aire protégée',
    commune: 'Contours communaux',
};

export const COMMUNE_STYLE = { color: '#41704f', weight: 1, fill: false };

// Contour de la commune comme OBJET PRINCIPAL d'une fiche (mini-carte) :
// trait vert + très léger remplissage, la géométrie reste lisible sur les
// deux fonds.
export const styleCommune = () => ({
    color: '#41704f',
    weight: 2,
    fillColor: '#eaf2ea',
    fillOpacity: 0.35,
});

// Plan IGN v2 : WMTS libres de la Géoplateforme, SANS clé.
export const PLAN_IGN_URL =
    'https://data.geopf.fr/wmts?SERVICE=WMTS&REQUEST=GetTile&VERSION=1.0.0' +
    '&Layer=GEOGRAPHICALGRIDSYSTEMS.PLANIGNV2&STYLE=normal&TILEMATRIXSET=PM' +
    '&FORMAT=image/png&TILEMATRIX={z}&TILEROW={y}&TILECOL={x}';

export const OSM_URL = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';

export function basemaps() {
    return {
        'OpenStreetMap': {
            url: OSM_URL,
            options: {
                maxZoom: 19,
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
            },
        },
        // Libellé affiché dans le sélecteur Leaflet — Géoportail est le
        // service du diffuseur (Géoplateforme), la charte l'appelle ainsi.
        'Plan IGN (Géoportail)': {
            url: PLAN_IGN_URL,
            options: {
                maxZoom: 19,
                attribution: 'IGN · <a href="https://geoplateforme.fr">Géoplateforme</a>',
            },
        },
    };
}

export function styleOf(famille) {
    const c = FAMSTYLE[famille] ?? ['#cccccc', '#666666'];
    return { color: c[1], weight: 1.6, fillColor: c[0], fillOpacity: 0.3 };
}

// Fiche du portail pour un identifiant de zone (lien réel, pas de hash —
// navigation complète : les popups Leaflet sont du HTML brut).
export function ficheHref(famille, id) {
    const base = { z1: '/znieff/', z2: '/znieff/', nat: '/natura/', ap: '/ap/' }[famille];
    return base + id;
}

export function zonePopup(props) {
    return (
        '<b>' + props.nom + '</b><br/>' +
        '<small>' + (FAMLABEL[props.famille] ?? 'Zone') + ' ' + props.id + '</small><br/>' +
        '<a href="' + ficheHref(props.famille, props.id) + '">Ouvrir la fiche →</a>'
    );
}

export function communePopup(props) {
    return '<b>' + props.nom + '</b><br/><a href="/commune/' + props.slug + '">Fiche de la commune →</a>';
}

// Fichiers de surcouches précalculés d'un département (générés à l'import
// par GeojsonExportService — jamais calculés en ligne).
const FICHIER_DEPT = {
    z1: 'znieff-z1.geojson',
    z2: 'znieff-z2.geojson',
    nat: 'natura.geojson',
    ap: 'ap.geojson',
    commune: 'communes.geojson',
};

/**
 * Surcouches d'un département : URL GeoJSON précalculé + famille + libellé
 * (checkbox Leaflet). Ordre = ordre de la légende de la maquette.
 */
export function deptSurcouches(dept) {
    const base = '/geo/dept-' + dept + '/';
    return [
        { famille: 'commune', label: FAMLABEL.commune, url: base + FICHIER_DEPT.commune },
        { famille: 'z2', label: FAMLABEL.z2, url: base + FICHIER_DEPT.z2 },
        { famille: 'z1', label: FAMLABEL.z1, url: base + FICHIER_DEPT.z1 },
        { famille: 'nat', label: FAMLABEL.nat, url: base + FICHIER_DEPT.nat },
        { famille: 'ap', label: FAMLABEL.ap, url: base + FICHIER_DEPT.ap },
    ];
}

/**
 * Style des petites zones (< 1 ha : rendues par un point + surface
 * affichée sur la fiche) sur les surcouches.
 */
export function pointStyle(famille) {
    return {
        radius: 6,
        color: styleOf(famille).color,
        weight: 1.5,
        fillColor: styleOf(famille).fillColor,
        fillOpacity: 0.85,
    };
}
