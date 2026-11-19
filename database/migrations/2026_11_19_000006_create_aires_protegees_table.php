<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Aires protégées (catalogue APA-INPN) : ZONAGES JURIDIQUES, à la
     * différence des ZNIEFF (inventaire scientifique). Géométrie de saisie
     * fine (précalcul d'affichage ~5 m), aucune géométrie stockée ici.
     */
    public function up(): void
    {
        Schema::create('aires_protegees', function (Blueprint $table) {
            $table->string('id', 20)->primary(); // ex. FR3800583
            $table->string('type_code', 12);    // APPB | RNN | RNR | RBI |…
            $table->string('type_nom');         // libellé INPN complet
            $table->string('nom');
            $table->decimal('superficie_ha', 14, 4)->nullable();      // calculée
            $table->decimal('superficie_ref_ha', 14, 4)->nullable(); // INPN
            $table->string('acte', 255)->nullable();
            $table->string('gestionnaire', 255)->nullable();
            $table->string('objectif_protection', 100)->nullable();
            $table->boolean('doc_gestion')->default(false);
            $table->date('date_crea_sign')->nullable();
            $table->date('date_modif_geo')->nullable();
            $table->string('territoire', 10)->index();
            $table->decimal('bbox_min_lat', 9, 6)->nullable();
            $table->decimal('bbox_max_lat', 9, 6)->nullable();
            $table->decimal('bbox_min_lng', 9, 6)->nullable();
            $table->decimal('bbox_max_lng', 9, 6)->nullable();
            $table->string('url_inpn')->nullable();
            $table->timestamps();

            $table->index('type_code');
            $table->index('nom');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aires_protegees');
    }
};
