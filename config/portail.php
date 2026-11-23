<?php

// Config métier du Portail ZNIEFF : chemins des données INPN (import local
// uniquement, via Docker + PostGIS), versions affichées des référentiels,
// calibrage des simplifications GeoJSON, référentiels géographiques.
// Une donnée brute par colonne, jamais d'indicateur composite (cf. /donnees).

return [

    /*
    |----------------------------------------------------------------------
    | Données INPN (MNHN) — import local uniquement
    |----------------------------------------------------------------------
    | Jamais dans git (1,5 Go) : montées en lecture seule dans le conteneur
    | Docker (voir docker-compose.yml) ou pointées par PORTAIL_INPN_DIR.
    | En production, aucune de ces données brutes n'est requise : uniquement
    | le SQL portable (portail:export-sql) et les GeoJSON précalculés.
    */
    'inpn' => [
        'dir' => env('PORTAIL_INPN_DIR', base_path('data/inpn-data')),
        'admin_express_dir' => env('PORTAIL_ADMIN_EXPRESS_DIR', base_path('data/inpn-data/admin-express')),

        // TAXREF (MNHN) : référentiel taxonomique — distribution séparée,
        // conditions INPN à accepter (v18 : ~670 Mo complet, TAXVERNv18
        // dédicacé aux noms vernaculaires ~9,7 Mo, fr + eng mélangés).
        // PORTAIL_TAXREF_FICHIER (fichier explicite) l'emporte ; sinon le
        // pilote cherche dans PORTAIL_TAXREF_DIR (défaut : data/inpn-data/
        // TAXREF — majuscule, le conteneur Linux est case-sensitive ; en
        // repli <inpn.dir>/TAXREF puis <inpn.dir>/taxref) dans l'ordre :
        // TAXVERNv*.txt → TAXREFv*.txt → TAXREF.txt.
        // Aucun fichier trouvé : nom_vernaculaire reste null, latin seul.
        'taxref_fichier' => env('PORTAIL_TAXREF_FICHIER', ''),
        'taxref_dossier' => env('PORTAIL_TAXREF_DIR', base_path('data/inpn-data/TAXREF')),

        // Référentiel de sensibilité SINP v18 (INPN) : RefSensibilite_18.csv
        // (taxon × département × niveau de sensibilité) + RefSensibiliteMtd.csv
        // (méthodes / crédit des listes régionales). Sert à renforcer le filtre
        // « jamais d'espèce sensible affichée » côté requête (App\Support\Especes).
        'sensibilite_dossier' => env('PORTAIL_SENSIBILITE_DIR', base_path('data/inpn-data/SENSIBILITE_18')),

        // Base de Connaissance des statuts (BDC v18, INPN) : statuts de
        // protection/réglementation par taxon et par territoire (bdc_18_01.csv,
        // ~448 k lignes lues en flux, filtrées aux cd_ref du périmètre).
        // Les lignes SENSIBILITE n'y sont jamais importées (filtre, pas affichage).
        'bdc_dossier' => env('PORTAIL_BDC_DIR', base_path('data/inpn-data/BDC_18')),

        // INPG — Inventaire National du Patrimoine Géologique (INPN/MNHN) :
        // BDD attributaire (inpg_data_*.csv, « ; ») + SIG shapefiles par
        // territoire (SIG_INPG/inpg_sig_{metrop,glp,guf,mtq,myt,reu,spm}.shp).
        // Le dossier est versionné (INPG_BDD_072025) : repli sur le plus
        // récent glob INPG_BDD_* sous <inpn.dir>.
        'inpg_dossier' => env('PORTAIL_INPG_DIR', base_path('data/inpn-data/INPG_BDD_072025')),

        // Fiches PDF ZNIEFF (INPN/MNHN) : 19 dossiers régionaux sous ce
        // répertoire, un PDF par zone nommé {NM_SFFZN}.pdf (ex.
        // 28_Normandie/230009093.pdf). Les PDF copiés en local le sont dans
        // portail.stockage_pdf (HORS public/) et servis uniquement via la
        // route filtrée /znieff/{id}/fiche.pdf (jamais d'URL statique).
        'pdf_dossier' => env('PORTAIL_PDF_ZNIEFF_DIR', base_path('data/inpn-data/PDF ZNIEFF')),

        // Versions des référentiels, affichées en pied de page et sur la page
        // Données & méthodologie. Date de la base diffusée par l'INPN.
        'versions' => [
            'znieff' => 'extraction ZNIEFF INPN — juin 2025',
            'natura' => 'base Natura 2000 (INPN/MNHN) — décembre 2024',
            'aires' => 'inventaire des aires protégées APA-INPN — juillet 2025',
            'adminexpress' => 'contours communaux AdminExpress (IGN) — édition 2025',
            'taxref' => 'référentiel taxonomique TAXREF (MNHN)',
            'sensibilite' => 'référentiel de sensibilité des données SINP (INPN) — v18, août 2025',
            'bdc' => 'base de connaissance des statuts BDC (INPN) — v18, juillet 2025',
            'inpg' => 'inventaire national du patrimoine géologique INPG (INPN/MNHN) — juillet 2025',
            'pdf' => 'fiches PDF des ZNIEFF (INPN/MNHN) — diffusion INPN',
        ],
    ],

    /*
    |----------------------------------------------------------------------
    | Fiches INPN (liens sortants complémentaires — les données naturalistes
    | rediffusées le sont sous les licences de l'INPN, jamais LO 2.0)
    |----------------------------------------------------------------------
    */
    'urls' => [
        'znieff' => 'https://inpn.mnhn.fr/zone/znieff/',           // + NM_SFFZN
        'natura' => 'https://inpn.mnhn.fr/site/natura2000/',       // + code FR
        'ap' => 'https://inpn.mnhn.fr/espace/protege/',            // + id MNHN
        'commune_inpn' => 'https://inpn.mnhn.fr/collTerr/commune/', // + code INSEE
        'inpg' => 'https://inpn.mnhn.fr/site/inpg/',               // + identifiant métier (ex. NAQ0004)
        'abc' => 'https://observatoire-abc.fr',
        'abc_commune' => 'https://observatoire-abc.fr/commune/',   // + code INSEE
        // SINP : point d'entrée des demandes d'accès aux données sensibles
        // (fiches PDF des zones à espèces sensibles, cf. route znieff.pdf).
        'sinp' => 'https://sinp.naturefrance.fr/',
    ],

    /*
    |----------------------------------------------------------------------
    | Surcouches GeoJSON précalculées (public/geo)
    |----------------------------------------------------------------------
    | générées par portail:import-inpn, servies statiquement (jamais
    | calculées à la volée). Tolérances de simplification en mètres,
    | calibrées sur la précision de saisie des sources :
    | ZNIEFF II 55 m · ZNIEFF I 20 m · Natura 25 m · AP ~5 m · communes 100 m.
    | Zones < 1 ha (mares…) : rendu par point, superficie conservée en props.
    */
    'geojson' => [
        'dir' => public_path('geo'),
        'simplification' => [
            'z1' => 20,
            'z2' => 55,
            'natura' => 25,
            'ap' => 5,
            'inpg' => 10,
            'communes' => 100,
        ],
        'decimales' => 5,
        'seuil_point_ha' => 1.0,
    ],

    /*
    | Communes voisines (chips de fiche, navigation de commune en commune).
    */
    'voisines' => [
        'rayon_m' => 15000,
    ],

    /*
    | Référentiel géographique (COG / AdminExpress) : noms affichés.
    */
    'regions' => [
        '01' => 'Guadeloupe',
        '02' => 'Martinique',
        '03' => 'Guyane',
        '04' => 'La Réunion',
        '05' => 'Saint-Pierre-et-Miquelon',
        '06' => 'Mayotte',
        '11' => 'Île-de-France',
        '24' => 'Centre-Val de Loire',
        '27' => 'Bourgogne-Franche-Comté',
        '28' => 'Normandie',
        '32' => 'Hauts-de-France',
        '44' => 'Grand Est',
        '52' => 'Pays de la Loire',
        '53' => 'Bretagne',
        '75' => 'Nouvelle-Aquitaine',
        '76' => 'Occitanie',
        '84' => 'Auvergne-Rhône-Alpes',
        '93' => 'Provence-Alpes-Côte d\'Azur',
        '94' => 'Corse',
    ],

    'departements' => [
        '01' => 'Ain', '02' => 'Aisne', '03' => 'Allier', '04' => 'Alpes-de-Haute-Provence',
        '05' => 'Hautes-Alpes', '06' => 'Alpes-Maritimes', '07' => 'Ardèche', '08' => 'Ardennes',
        '09' => 'Ariège', '10' => 'Aube', '11' => 'Aude', '12' => 'Aveyron',
        '13' => 'Bouches-du-Rhône', '14' => 'Calvados', '15' => 'Cantal', '16' => 'Charente',
        '17' => 'Charente-Maritime', '18' => 'Cher', '19' => 'Corrèze',
        '2A' => 'Corse-du-Sud', '2B' => 'Haute-Corse',
        '21' => 'Côte-d\'Or', '22' => 'Côtes-d\'Armor', '23' => 'Creuse', '24' => 'Dordogne',
        '25' => 'Doubs', '26' => 'Drôme', '27' => 'Eure', '28' => 'Eure-et-Loir',
        '29' => 'Finistère', '30' => 'Gard', '31' => 'Haute-Garonne', '32' => 'Gers',
        '33' => 'Gironde', '34' => 'Hérault', '35' => 'Ille-et-Vilaine', '36' => 'Indre',
        '37' => 'Indre-et-Loire', '38' => 'Isère', '39' => 'Jura', '40' => 'Landes',
        '41' => 'Loir-et-Cher', '42' => 'Loire', '43' => 'Haute-Loire', '44' => 'Loire-Atlantique',
        '45' => 'Loiret', '46' => 'Lot', '47' => 'Lot-et-Garonne', '48' => 'Lozère',
        '49' => 'Maine-et-Loire', '50' => 'Manche', '51' => 'Marne', '52' => 'Haute-Marne',
        '53' => 'Mayenne', '54' => 'Meurthe-et-Moselle', '55' => 'Meuse', '56' => 'Morbihan',
        '57' => 'Moselle', '58' => 'Nièvre', '59' => 'Nord', '60' => 'Oise',
        '61' => 'Orne', '62' => 'Pas-de-Calais', '63' => 'Puy-de-Dôme', '64' => 'Pyrénées-Atlantiques',
        '65' => 'Hautes-Pyrénées', '66' => 'Pyrénées-Orientales', '67' => 'Bas-Rhin', '68' => 'Haut-Rhin',
        '69' => 'Rhône', '70' => 'Haute-Saône', '71' => 'Saône-et-Loire', '72' => 'Sarthe',
        '73' => 'Savoie', '74' => 'Haute-Savoie', '75' => 'Paris', '76' => 'Seine-Maritime',
        '77' => 'Seine-et-Marne', '78' => 'Yvelines', '79' => 'Deux-Sèvres', '80' => 'Somme',
        '81' => 'Tarn', '82' => 'Tarn-et-Garonne', '83' => 'Var', '84' => 'Vaucluse',
        '85' => 'Vendée', '86' => 'Vienne', '87' => 'Haute-Vienne', '88' => 'Vosges',
        '89' => 'Yonne', '90' => 'Territoire de Belfort', '91' => 'Essonne', '92' => 'Hauts-de-Seine',
        '93' => 'Seine-Saint-Denis', '94' => 'Val-de-Marne', '95' => 'Val-d\'Oise',
        '971' => 'Guadeloupe', '972' => 'Martinique', '973' => 'Guyane',
        '974' => 'La Réunion', '975' => 'Saint-Pierre-et-Miquelon', '976' => 'Mayotte',
    ],

    /*
    |----------------------------------------------------------------------
    | Types d'aires protégées (catalogue APA-INPN)
    |----------------------------------------------------------------------
    | Le GPKG INPN porte un libellé long (type_espace, ex. « Arrêté
    | préfectoral de protection de biotope ») ; on en dérive un code court
    | pour les badges et les filtres. Premier mot-clé trouvé qui l'emporte
    | (l'ordre compte). Libellés non reconnus : code « AP », libellé source
    | conservé (et comptés dans les logs d'import).
    */
    'ap_types' => [
        ['motcle' => 'biotope', 'code' => 'APPB', 'libelle' => 'Arrêté de protection de biotope'],
        ['motcle' => 'géotope', 'code' => 'APG', 'libelle' => 'Arrêté de protection de géotope'],
        ['motcle' => 'réserve naturelle régionale', 'code' => 'RNR', 'libelle' => 'Réserve naturelle régionale'],
        ['motcle' => 'réserve naturelle nationale', 'code' => 'RNN', 'libelle' => 'Réserve naturelle nationale'],
        ['motcle' => 'réserve biologique intégrale', 'code' => 'RBI', 'libelle' => 'Réserve biologique intégrale'],
        ['motcle' => 'réserve biologique dirigée', 'code' => 'RBD', 'libelle' => 'Réserve biologique dirigée'],
        ['motcle' => 'réserve biologique', 'code' => 'RB', 'libelle' => 'Réserve biologique'],
        ['motcle' => 'conservatoire du littoral', 'code' => 'CELRL', 'libelle' => 'Conservatoire du littoral'],
        ['motcle' => 'coeur de parc', 'code' => 'PN-COEUR', 'libelle' => 'Cœur de parc national'],
        ['motcle' => 'parc national', 'code' => 'PN', 'libelle' => 'Parc national'],
        ['motcle' => 'parc naturel régional', 'code' => 'PNR', 'libelle' => 'Parc naturel régional'],
        ['motcle' => 'forêt de protection', 'code' => 'FP', 'libelle' => 'Forêt de protection'],
        ['motcle' => 'site classé', 'code' => 'SC', 'libelle' => 'Site classé'],
        ['motcle' => 'site inscrit', 'code' => 'SI', 'libelle' => 'Site inscrit'],
        ['motcle' => 'réserve nationale de chasse', 'code' => 'RNC', 'libelle' => 'Réserve nationale de chasse et de faune sauvage'],
        ['motcle' => 'réserve de chasse', 'code' => 'RC', 'libelle' => 'Réserve de chasse'],
    ],

    /*
    |----------------------------------------------------------------------
    | Source « ABC » (badge des fiches communes)
    |----------------------------------------------------------------------
    | L'API v1 de l'Observatoire des ABC (même équipe) est interrogée UNE
    | fois par an au rafraîchissement des référentiels, avec limitation de
    | débit respectée (une requête, jamais de rafale).
    | ABC_API_ENABLED=false → has_abc reste false partout.
    */
    'abc_api' => [
        'enabled' => env('ABC_API_ENABLED', true),
        'base' => env('ABC_API_BASE', 'https://observatoire-abc.fr/api/v1'),
        // Une seule requête de liste ; endpoint paginé éventuel.
        'per_page' => 500,
    ],

    /*
    |----------------------------------------------------------------------
    | geo.api.gouv.fr (codes postaux des communes)
    |----------------------------------------------------------------------
    | Une requête massive unique, mise en cache dans storage/app/portail.
    | En cas d'échec : codes_postaux laissés vides (recherche par nom et
    | code INSEE toujours fonctionnelles).
    */
    'geo_api' => [
        'base' => env('GEO_API_BASE', 'https://geo.api.gouv.fr'),
    ],

    /*
    | Répertoires d'export (SQL portable de déploiement, jamais dans git).
    */
    'export_dir' => storage_path('app/portail/deploy'),
    'cache_dir' => storage_path('app/portail/cache'),

    /*
    | Stockage LOCAL des fiches PDF ZNIEFF (INPN) : JAMAIS dans public/,
    | elles sont servies uniquement via la route filtrée
    | /znieff/{id}/fiche.pdf (ZonageController@pdf) qui bloque les zones à
    | espèces sensibles côté requête — un PDF sensible ne doit pas être
    | accessible par une URL statique devinable.
    */
    'stockage_pdf' => storage_path('app/portail/pdf/znieff'),
];
