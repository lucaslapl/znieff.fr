import test from 'node:test';
import assert from 'node:assert/strict';

import { FAMSTYLE, FAMLABEL, PLAN_IGN_URL, basemaps, styleOf, zonePopup } from '../../resources/js/map/constants.js';

test('plan IGN v2 : WMTS libres de la Géoplateforme, sans clé', () => {
    assert.match(PLAN_IGN_URL, /^https:\/\/data\.geopf\.fr\/wmts\?/);
    assert.match(PLAN_IGN_URL, /Layer=GEOGRAPHICALGRIDSYSTEMS\.PLANIGNV2/);
    assert.match(PLAN_IGN_URL, /TILEMATRIXSET=PM/);
    assert.match(PLAN_IGN_URL, /TILEMATRIX=\{z\}&TILEROW=\{y\}&TILECOL=\{x\}/);
    assert.ok(!PLAN_IGN_URL.includes('key'), 'jamais de clé dans l’URL');
});

test('palette herbier conforme à la maquette', () => {
    assert.deepEqual(FAMSTYLE.z1, ['#c9963e', '#8a6415']);
    assert.deepEqual(FAMSTYLE.z2, ['#3d6b4a', '#24422e']);
    assert.deepEqual(FAMSTYLE.nat, ['#3a7ca5', '#234e6b']);
    assert.deepEqual(FAMSTYLE.ap, ['#7b4fa0', '#4a2d63']);
    assert.equal(FAMLABEL.z1, 'ZNIEFF I');
    assert.equal(FAMLABEL.nat, 'Natura 2000');
});

test('style Layer Leaflet par famille', () => {
    assert.equal(styleOf('z2').color, '#24422e');
    assert.equal(styleOf('z2').fillColor, '#3d6b4a');
    assert.equal(styleOf('z1').weight, 1.6);
});

test('popup : libellé de famille + lien vers la fiche', () => {
    const html = zonePopup({ famille: 'z2', id: '230009093', nom: 'Forêt de Louviers' });
    assert.ok(html.includes('<b>Forêt de Louviers</b>'));
    assert.ok(html.includes('ZNIEFF II 230009093'));
    assert.ok(html.includes('href="#/znieff/230009093"'));
});

test('basemaps : OSM et Plan IGN', () => {
    const fonds = basemaps();
    assert.ok(fonds.OpenStreetMap.url.includes('openstreetmap.org'));
    assert.ok(fonds['Plan IGN (Géoportail)'].url.includes('PLANIGNV2'));
    assert.ok(fonds['Plan IGN (Géoportail)'].options.attribution.includes('Géoplateforme'));
});
