// Constantes cartographiques du Portail ZNIEFF — charte herbier de la
// maquette validée (FAMSTYLE). Module pur (aucune dépendance), testé par
// tests/js/carte.test.mjs, consommé par la page Carte (itération c).

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
};

export const COMMUNE_STYLE = { color: '#41704f', weight: 1, fill: false };

// Plan IGN v2 : WMTS libres de la Géoplateforme, SANS clé.
export const PLAN_IGN_URL =
    'https://data.geopf.fr/wmts?SERVICE=WMTS&REQUEST=GetTile&VERSION=1.0.0' +
    '&Layer=GEOGRAPHICALGRIDSYSTEMS.PLANIGNV2&STYLE=normal&TILEMATRIXSET=PM' +
    '&FORMAT=image/png&TILEMATRIX={z}&TILEROW={y}&TILECOL={x}';

export const OSM_URL = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';

export function basemaps() {
    return {
        OpenStreetMap: {
            url: OSM_URL,
            options: {
                maxZoom: 19,
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
            },
        },
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

export function zonePopup(props) {
    const route = { z1: 'znieff', z2: 'znieff', nat: 'natura', ap: 'ap' }[props.famille];
    return `<b>${props.nom}</b><br/><small>${FAMLABEL[props.famille]} ${props.id}</small><br/><a href="#/${route}/${props.id}">Ouvrir la fiche →</a>`;
}
