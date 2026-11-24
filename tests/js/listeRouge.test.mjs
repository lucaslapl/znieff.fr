import test from 'node:test';
import assert from 'node:assert/strict';

import {
    LR_LIBELLES,
    LR_ORDRE,
    classeLr,
    codeLrNormalise,
    estStatutListeRouge,
    libelleLr,
} from '../../resources/js/especes/listeRouge.js';

test('détection des statuts Liste rouge (type LR* ou regroupement « Liste rouge »)', () => {
    assert.ok(estStatutListeRouge('LRN', 'Liste rouge')); // nationale
    assert.ok(estStatutListeRouge('LRE', 'Liste rouge')); // européenne
    assert.ok(estStatutListeRouge('LRM', 'Liste rouge')); // mondiale
    assert.ok(estStatutListeRouge('lrr', 'Liste rouge régionale'));
    assert.ok(estStatutListeRouge('', 'Liste rouge')); // repli sur le regroupement
    assert.ok(!estStatutListeRouge('PN', 'Protection'));
    assert.ok(!estStatutListeRouge('PD', 'Protection'));
    assert.ok(!estStatutListeRouge('DH', 'Directives européennes'));
    assert.ok(!estStatutListeRouge('BERN', 'Conventions internationales'));
    assert.ok(!estStatutListeRouge('ZDET', 'ZNIEFF'));
    assert.ok(!estStatutListeRouge(null, null));
});

test('normalisation des codes UICN du code_statut BDC', () => {
    assert.equal(codeLrNormalise('NT'), 'NT');
    assert.equal(codeLrNormalise(' vu '), 'VU');
    assert.equal(codeLrNormalise('CR*'), 'CR');
    assert.equal(codeLrNormalise('EW'), 'EW');
    assert.equal(codeLrNormalise('RE'), 'RE');
    assert.equal(codeLrNormalise(''), null);
    assert.equal(codeLrNormalise(null), null);
    assert.equal(codeLrNormalise('NV1'), null); // code de protection, pas un code UICN
    assert.equal(codeLrNormalise('CDH2'), null); // annexe directive Habitats
});

test('classe CSS du badge coloré', () => {
    assert.equal(classeLr('CR'), 'lr-cr');
    assert.equal(classeLr('EN'), 'lr-en');
    assert.equal(classeLr('VU'), 'lr-vu');
    assert.equal(classeLr('NT'), 'lr-nt');
    assert.equal(classeLr('LC'), 'lr-lc');
    assert.equal(classeLr('DD'), 'lr-dd');
    assert.equal(classeLr('NE'), 'lr-ne');
    assert.equal(classeLr('ZZ'), 'lr-autre'); // code inconnu : badge neutre
    assert.equal(classeLr(null), 'lr-autre');
});

test('libellé complet pour le title du badge', () => {
    assert.equal(libelleLr('VU'), 'VU — Vulnérable');
    assert.equal(libelleLr('CR'), 'CR — En danger critique');
    assert.equal(libelleLr('RE'), 'RE — Éteint dans la région');
    assert.equal(libelleLr('??'), '??'); // code brut si non reconnu
});

test('les 9 codes UICN ont tous un libellé et une classe dédiée', () => {
    assert.equal(LR_ORDRE.length, 9);
    for (const c of LR_ORDRE) {
        assert.ok(LR_LIBELLES[c], c + ' a un libellé');
        assert.equal(classeLr(c), 'lr-' + c.toLowerCase());
    }
    // ordre du plus au moins menacé
    assert.deepEqual(LR_ORDRE.slice(0, 3), ['RE', 'EW', 'CR']);
});
