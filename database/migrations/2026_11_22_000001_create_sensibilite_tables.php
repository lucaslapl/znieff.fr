<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Référentiel de sensibilité SINP v18 (INPN) : niveaux de sensibilité
     * des taxons pour la diffusion publique, compilés des listes régionales
     * (une ligne par taxon × département ; CD_DEPT null = pré-liste
     * nationale). Sert UNIQUEMENT à filtrer côté requête ce qui ne doit
     * jamais être affiché (App\Support\Especes), jamais à afficher une
     * sensibilité. Table purement attributaire.
     */
    public function up(): void
    {
        Schema::create('sensibilite_mtd', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary(); // CD_SENS_MTD
            $table->string('cd_reg', 3);              // code région de la liste
            $table->text('libelle_liste')->nullable();
            $table->text('ref_biblio')->nullable();
            $table->string('url')->nullable();
            $table->string('ressource')->nullable();
        });

        Schema::create('sensibilite_especes', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('cd_nom')->nullable(); // TAXREF (taxon cité)
            $table->unsignedInteger('cd_ref');            // TAXREF (taxon valide) — joint sur znieff_especes.cd_ref
            $table->unsignedSmallInteger('cd_sensibilite'); // niveau de sensibilité
            $table->unsignedSmallInteger('duree')->nullable(); // années depuis l'observation (null = permanent)
            $table->string('cd_dept', 3)->nullable();     // null = pré-liste nationale
            $table->unsignedSmallInteger('statut_biologique')->nullable();
            $table->unsignedSmallInteger('comportement')->nullable();
            $table->string('autre')->nullable();
            $table->unsignedInteger('cd_sens_mtd')->nullable();
            $table->timestamps();

            $table->foreign('cd_sens_mtd')->references('id')->on('sensibilite_mtd')->nullOnDelete();
            $table->index('cd_ref');
            $table->index('cd_dept');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sensibilite_especes');
        Schema::dropIfExists('sensibilite_mtd');
    }
};
