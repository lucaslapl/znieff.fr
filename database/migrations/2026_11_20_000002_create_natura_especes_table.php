<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Espèces des sites Natura 2000 (formulaire standard de données INPN,
     * species.csv.csv), enrichies des référentiels de directives
     * (dhff_especes : annexe II / prioritaire ; code_do_oiseaux : annexe I).
     *
     * Une ligne par (site, espèce) : les statuts biologiques du FSD
     * (r/c/w/p) sont fusionnés dans `presences` (ex. « c,r,w »).
     * Table attributaire — aucune localisation.
     */
    public function up(): void
    {
        Schema::create('natura_especes', function (Blueprint $table) {
            $table->id();
            $table->string('site_code', 12); // ex. FR8212001 → natura_sites.code
            $table->string('code_n2000', 10); // ex. A140 (oiseaux) ou 1065 (habitats)
            $table->unsignedInteger('cd_ref')->nullable(); // TAXREF
            $table->string('nom_scientifique')->nullable();
            $table->string('nom_vernaculaire')->nullable();
            $table->string('taxgroup', 40)->nullable();
            $table->boolean('annexe_ii')->default(false); // directive Habitats
            $table->boolean('annexe_1')->default(false);  // directive Oiseaux
            $table->boolean('prioritaire')->default(false); // habitat/espèce prioritaire
            $table->string('presences', 20)->nullable(); // statuts FSD fusionnés
            $table->timestamps();

            $table->foreign('site_code')->references('code')->on('natura_sites')->cascadeOnDelete();
            $table->index('site_code');
            $table->index('code_n2000');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('natura_especes');
    }
};
