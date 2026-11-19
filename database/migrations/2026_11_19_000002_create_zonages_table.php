<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Zonages ZNIEFF (types I et II, INPN/MNHN). Aucune géométrie en base :
     * les périmètres affichés sont des GeoJSON précalculés, les croisements
     * des surfaces précalculées par code INSEE (tables pivots).
     */
    public function up(): void
    {
        Schema::create('zonages', function (Blueprint $table) {
            // Identifiant national INPN (NM_SFFZN), ex. 230009093.
            $table->string('id', 12)->primary();
            // Ancien numéro régional (NM_REGZN), ex. 8526 / 85260005 :
            // cherchable, redirige vers la fiche canonique.
            $table->string('num_regional', 12)->nullable();
            $table->string('famille', 2); // z1 | z2
            $table->string('nom');
            // Superficie affichée = aire géométrique calculée en Lambert-93
            // (cohérente avec les parts) ; SU_ZN INPN conservée en référence.
            $table->decimal('superficie_ha', 12, 2)->nullable();       // calculée (affichée)
            $table->decimal('superficie_ref_ha', 12, 2)->nullable();  // INPN SU_ZN
            $table->string('precision_saisie', 20)->default('1/25 000');
            $table->date('date_crea')->nullable();
            $table->date('date_modif_geo')->nullable(); // mise à jour INPN de la zone
            $table->string('territoire', 10)->index(); // metrop | glp | guf | myt | reu | spm
            $table->string('volet', 5)->nullable();     // CONT | MER
            $table->decimal('bbox_min_lat', 9, 6)->nullable();
            $table->decimal('bbox_max_lat', 9, 6)->nullable();
            $table->decimal('bbox_min_lng', 9, 6)->nullable();
            $table->decimal('bbox_max_lng', 9, 6)->nullable();
            $table->string('url_inpn')->nullable();
            $table->timestamps();

            $table->index('famille');
            $table->index('nom');
            $table->index('num_regional');
        });

        // FK auto-référente (ZNIEFF II de rattachement) : après coup, sinon
        // Postgres refuse au CREATE TABLE (PK non-auto n'existe pas encore).
        Schema::table('zonages', function (Blueprint $table) {
            $table->string('id_parent', 12)->nullable();
            $table->foreign('id_parent')->references('id')->on('zonages')->nullOnDelete();
            $table->index('id_parent');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zonages');
    }
};
