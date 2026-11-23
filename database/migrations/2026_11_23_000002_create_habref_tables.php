<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * HABREF v7.0 (MNHN) — référentiel national des habitats, importé pour
     * le périmètre : unités HIC (typo 8) citées par les FSD Natura 2000 du
     * périmètre, leurs correspondances EUNIS (typo 7) / CORINE (typo 22)
     * et leurs parents hiérarchiques.
     *
     * Tables purement attributaires (aucune géométrie). Les espèces
     * caractéristiques (habref_taxons) sont filtrées côté requête dans
     * App\Support\Habitats : un taxon présent dans le référentiel de
     * sensibilité SINP v18 (pré-liste nationale comprise, filtre conservateur
     * sans territoire) ou marqué confidentiel n'est JAMAIS affiché.
     */
    public function up(): void
    {
        Schema::create('habref_habitats', function (Blueprint $table) {
            $table->unsignedInteger('cd_hab')->primary();
            $table->unsignedSmallInteger('cd_typo')->index(); // 7 = EUNIS, 8 = HIC, 22 = CORINE…
            $table->string('lb_code', 30)->nullable();       // ex. 2110 (HIC), 41.13 (EUNIS)
            $table->text('nom_fr');
            $table->text('nom_fr_complet')->nullable();
            $table->text('nom_en')->nullable();
            $table->unsignedSmallInteger('niveau')->nullable();
            $table->unsignedInteger('cd_hab_sup')->nullable()->index();
            $table->string('path', 220)->nullable();         // ex. /197/831/2682
            $table->text('description')->nullable();          // LB_DESCRIPTION
            $table->text('territoires')->nullable();          // CD_SIG_TERR statut P, séparés par « , »
            $table->boolean('france')->default(true);
            $table->timestamps();
        });

        Schema::create('habref_corresp', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('cd_hab_entre')->index();
            $table->unsignedInteger('cd_hab_sortie')->index();
            $table->unsignedSmallInteger('cd_type_relation')->nullable();
            $table->timestamps();
        });

        Schema::create('habref_taxons', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('cd_hab')->index();
            $table->unsignedInteger('cd_nom');                // taxon cité (HABREF)
            $table->unsignedInteger('cd_ref')->nullable()->index(); // résolu via TAXREF v18
            $table->text('nom_cite')->nullable();
            $table->unsignedSmallInteger('cd_type_relation'); // 19 caract., 20 diagn., 39 const. …
            $table->string('type_libelle', 80);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('habref_taxons');
        Schema::dropIfExists('habref_corresp');
        Schema::dropIfExists('habref_habitats');
    }
};
