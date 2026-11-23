<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * INPG — Inventaire National du Patrimoine Géologique (INPN/MNHN).
     * Tables purement attributaires (aucune géométrie : les périmètres sont
     * des GeoJSON précalculés, les croisements des surfaces précalculées).
     *
     * RÈGLE ABSOLUE (même politique que les espèces) : un site
     * `niv_diffusion = 1` (confidentiel, ex. gisement vulnérable) n'est
     * JAMAIS affiché ni compté, et sa géométrie n'est jamais exportée :
     * le filtre `niv_diffusion = 2` est posé côté requête dans
     * App\Support\Inpg (listes, compteurs, fiche), et le staging spatial /
     * les GeoJSON excluent ces sites dès l'import. Même règle pour les
     * documents `diff_doc` (1/2 = diffusables).
     *
     * Les informateurs (personnes physiques, inpg_*_informateur*) ne sont
     * JAMAIS importés (RGPD) — ni les organismes (réservés au référentiel
     * ORGANISMES, lot ultérieur).
     */
    public function up(): void
    {
        Schema::create('inpg_sites', function (Blueprint $table) {
            $table->string('id', 12)->primary(); // id_site_inpg (clé du SIG INPG)
            $table->string('identifiant_permanent', 40)->nullable()->index();
            $table->string('identifiant_metier', 10)->unique(); // ex. NAQ0004 — clé d'URL
            $table->text('nom');
            $table->unsignedSmallInteger('niv_diffusion')->default(2); // 1 = confidentiel, JAMAIS affiché
            $table->decimal('superficie_saisie', 12, 2)->nullable();
            $table->text('justif_superficie')->nullable();
            $table->string('unite_superficie', 20)->nullable();
            $table->string('etat', 60)->nullable();        // code_etat dénormalisé
            $table->text('present_succincte')->nullable();
            $table->text('descr_phys')->nullable();        // HTML INPN (nettoyé côté serveur à l'affichage)
            $table->text('descr_geol')->nullable();        // HTML INPN
            $table->text('itineraire')->nullable();
            $table->text('gilges')->nullable();            // code_gilges dénormalisé
            $table->string('phenom_geol', 120)->nullable();
            $table->text('interet_hist_geol')->nullable();
            $table->string('rarete', 60)->nullable();
            $table->text('comment_eval')->nullable();
            $table->text('vuln_nat')->nullable();
            $table->text('menace_anthrop')->nullable();
            $table->text('suivi_protec')->nullable();
            $table->text('comment_gnrl_menace')->nullable();
            $table->date('date_crea_site')->nullable();
            $table->date('date_prem_visite')->nullable();
            $table->date('date_der_visite')->nullable();
            $table->date('date_modif')->nullable();
            $table->string('ref_carto', 120)->nullable();
            $table->decimal('superficie_ha', 12, 2)->nullable(); // calculée en Lambert-93 (affichée)
            $table->decimal('bbox_min_lat', 9, 6)->nullable();
            $table->decimal('bbox_max_lat', 9, 6)->nullable();
            $table->decimal('bbox_min_lng', 9, 6)->nullable();
            $table->decimal('bbox_max_lng', 9, 6)->nullable();
            $table->string('territoire', 10)->index(); // metrop | glp | guf | mtq | myt | reu | spm
            $table->string('url_inpn')->nullable();    // https://inpn.mnhn.fr/site/inpg/{identifiant_metier}
            $table->timestamps();

            $table->index('nom');
        });

        Schema::create('inpg_communes', function (Blueprint $table) {
            $table->id();
            $table->string('site_id', 12); // → inpg_sites.id
            $table->string('insee', 5);
            $table->decimal('surface_ha', 12, 4)->nullable(); // intersection calculée (précalculée)
            $table->decimal('part_zonage', 9, 3)->nullable(); // part du site INPG (%) — colonne requise par croiser()
            $table->decimal('part_commune', 9, 3)->nullable();
            $table->timestamps();

            $table->foreign('site_id')->references('id')->on('inpg_sites')->cascadeOnDelete();
            $table->foreign('insee')->references('insee')->on('communes')->cascadeOnDelete();
            $table->unique(['site_id', 'insee']);
            $table->index('insee');
        });

        Schema::create('inpg_interets', function (Blueprint $table) {
            $table->id();
            $table->string('site_id', 12);
            $table->string('interet', 80);            // code_interet dénormalisé
            $table->string('interet_sup', 120)->nullable();
            $table->text('justif')->nullable();
            $table->foreign('site_id')->references('id')->on('inpg_sites')->cascadeOnDelete();
            $table->index('site_id');
        });

        Schema::create('inpg_zonage_ref', function (Blueprint $table) {
            $table->id();
            $table->string('site_id', 12);
            $table->string('zonage_ref', 120);        // code_zonage_ref dénormalisé (ZNIEFF, SIC/ZSC, PNR…)
            $table->string('reference', 120)->nullable(); // ex. NM_SFFZN, code FR…, nom du PNR
            $table->text('commentaire')->nullable();
            $table->foreign('site_id')->references('id')->on('inpg_sites')->cascadeOnDelete();
            $table->index('site_id');
        });

        Schema::create('inpg_strati', function (Blueprint $table) {
            $table->id();
            $table->string('site_id', 12);
            $table->string('strati', 120);            // code_strati : étage géologique (libellé FR)
            $table->decimal('age_inf', 8, 1)->nullable(); // Ma
            $table->decimal('age_sup', 8, 1)->nullable();
            $table->foreign('site_id')->references('id')->on('inpg_sites')->cascadeOnDelete();
            $table->index('site_id');
        });

        Schema::create('inpg_documents', function (Blueprint $table) {
            $table->id();
            $table->string('site_id', 12);
            $table->string('lb_doc')->nullable();
            $table->text('legende_doc')->nullable();
            $table->string('type_doc', 80)->nullable(); // carte géologique, coupe, photo…
            $table->unsignedSmallInteger('diff_doc')->default(3); // 1/2 = diffusable, JAMAIS affiché si 3
            $table->string('path_doc')->nullable();     // chemin interne INPN — jamais servi tel quel
            $table->foreign('site_id')->references('id')->on('inpg_sites')->cascadeOnDelete();
            $table->index('site_id');
        });

        Schema::create('inpg_lieudits', function (Blueprint $table) {
            $table->id();
            $table->string('site_id', 12);
            $table->string('lb_lieudit', 200);
            $table->foreign('site_id')->references('id')->on('inpg_sites')->cascadeOnDelete();
            $table->index('site_id');
        });

        Schema::create('inpg_notes', function (Blueprint $table) {
            $table->id();
            $table->string('site_id', 12);
            $table->string('type_note', 80); // note géologique principale/secondaire, pédagogique…
            $table->text('note')->nullable();
            $table->decimal('note_pond', 5, 2)->nullable();
            $table->foreign('site_id')->references('id')->on('inpg_sites')->cascadeOnDelete();
            $table->index('site_id');
        });

        Schema::create('inpg_biblio', function (Blueprint $table) {
            $table->id();
            $table->string('site_id', 12);
            $table->text('biblio'); // référence reconstituée depuis code_biblio (auteurs, titre, année, revue)
            $table->foreign('site_id')->references('id')->on('inpg_sites')->cascadeOnDelete();
            $table->index('site_id');
        });

        Schema::create('inpg_accessibilites', function (Blueprint $table) {
            $table->id();
            $table->string('site_id', 12);
            $table->string('accessib', 40);
            $table->foreign('site_id')->references('id')->on('inpg_sites')->cascadeOnDelete();
            $table->index('site_id');
        });

        Schema::create('inpg_typologies', function (Blueprint $table) {
            $table->id();
            $table->string('site_id', 12);
            $table->string('typologie', 60);
            $table->foreign('site_id')->references('id')->on('inpg_sites')->cascadeOnDelete();
            $table->index('site_id');
        });

        Schema::create('inpg_cartes', function (Blueprint $table) {
            $table->id();
            $table->string('site_id', 12);
            $table->string('type_carte', 20);  // geol | ign | marine
            $table->string('carte', 120);      // ex. « CAHORS 0881 »
            $table->foreign('site_id')->references('id')->on('inpg_sites')->cascadeOnDelete();
            $table->index('site_id');
        });
    }

    public function down(): void
    {
        foreach (['inpg_cartes', 'inpg_typologies', 'inpg_accessibilites', 'inpg_biblio', 'inpg_notes', 'inpg_lieudits', 'inpg_documents', 'inpg_strati', 'inpg_zonage_ref', 'inpg_interets', 'inpg_communes', 'inpg_sites'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
