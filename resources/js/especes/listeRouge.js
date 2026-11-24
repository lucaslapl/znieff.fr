// Codes des Listes rouges UICN (statuts BDC INPN v18 : types LRN/LRE/LRM/
// LRR…) et leur rendu coloré sur les fiches espèces. Module pur (aucune
// dépendance), testé par tests/js/listeRouge.test.mjs — consommé par
// Pages/Especes/Show.vue.

// Codes UICN, du plus au moins menacé (EW = éteint à l'état sauvage).
export const LR_ORDRE = ['RE', 'EW', 'CR', 'EN', 'VU', 'NT', 'LC', 'DD', 'NE'];

export const LR_LIBELLES = {
    RE: 'Éteint dans la région',
    EW: 'Éteint à l’état sauvage',
    CR: 'En danger critique',
    EN: 'En danger',
    VU: 'Vulnérable',
    NT: 'Quasi menacé',
    LC: 'Préoccupation mineure',
    DD: 'Données insuffisantes',
    NE: 'Non évalué',
};

/**
 * Vrai si la ligne de statut BDC est une Liste rouge : type LRN/LRE/LRM/LRR…
 * (tout type commençant par « LR ») ou regroupement « Liste rouge ».
 */
export function estStatutListeRouge(type, regroupement = '') {
    const t = (type ?? '').toUpperCase();
    if (t.startsWith('LR')) {
        return true;
    }
    const r = (regroupement ?? '')
        .normalize('NFD')
        .replace(/\p{Diacritic}/gu, '') // retire les accents
        .toLowerCase();

    return r.includes('liste rouge');
}

/**
 * Normalise un code_statut BDC (« NT », « vu », « CR* »…) en code UICN
 * (« NT », « VU », « CR ») ; null si absent ou non reconnu (ex. NV1, code
 * de protection qui n'est pas un code UICN).
 */
export function codeLrNormalise(code) {
    const m = /^([A-Za-z]{2})/.exec((code ?? '').trim());
    if (!m) {
        return null;
    }
    const c = m[1].toUpperCase();

    return LR_LIBELLES[c] ? c : null;
}

/**
 * Classe CSS du badge : « lr-cr », « lr-en »… ou « lr-autre » si le code
 * n'est pas un code UICN reconnu.
 */
export function classeLr(code) {
    const c = codeLrNormalise(code);

    return c ? 'lr-' + c.toLowerCase() : 'lr-autre';
}

/**
 * Libellé complet pour le title du badge : « CR — En danger critique » ;
 * le code brut tel quel s'il n'est pas reconnu.
 */
export function libelleLr(code) {
    const c = codeLrNormalise(code);

    return c ? c + ' — ' + LR_LIBELLES[c] : (code ?? '');
}
