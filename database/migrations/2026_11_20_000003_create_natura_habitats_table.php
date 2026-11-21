<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Habitats d'intérêt communautaire des sites Natura 2000 (FSD,
     * habit1.csv.csv), enrichis du référentiel de la directive Habitats
     * (dhff_habitats : nom français du code EUR, caractère prioritaire).
     *
     * `conservation` = structure et fonctions (A/B/C, champ « conserve ») ;
     * `evaluation_globale` = évaluation globale du site (champ « global »).
     */
    public function up(): void
    {
        Schema::create('natura_habitats', function (Blueprint $table) {
            $table->id();
            $table->string('site_code', 12); // → natura_sites.code
            $table->string('cd_ue', 10);     // code EUR annexe I, ex. 2110
            $table->string('nom')->nullable(); // dhff_habitats (lb_habdh_fr)
            $table->boolean('prioritaire')->default(false);
            $table->string('cd_hab', 20)->nullable(); // Corine Biotopes
            $table->decimal('cover', 12, 4)->nullable(); // champ FSD « cover », unité source
            $table->decimal('area', 12, 4)->nullable();  // champ FSD « area », unité source
            $table->char('representativite', 1)->nullable(); // A-D
            $table->char('conservation', 1)->nullable();     // A-C (structure & fonctions)
            $table->char('evaluation_globale', 1)->nullable(); // A-D
            $table->timestamps();

            $table->foreign('site_code')->references('code')->on('natura_sites')->cascadeOnDelete();
            $table->index('site_code');
            $table->index('cd_ue');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('natura_habitats');
    }
};
