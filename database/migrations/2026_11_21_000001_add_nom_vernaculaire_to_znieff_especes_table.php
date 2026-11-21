<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Noms vernaculaires ZNIEFF : la correspondance latin → français est une
     * donnée du référentiel TAXREF (MNHN), pas une traduction maison — elle
     * est remplie à l'import (portail:import-inpn) quand le fichier TAXREF
     * est présent (data/inpn-data, PORTAIL_TAXREF_DIR). Absent : la colonne
     * reste null et les fiches retombent sur le nom latin.
     * Type `text` : LB_VERN de TAXVERN liste parfois 30+ variantes
     * régionales dans une seule cellule (614 caractères mesurés — l'étrille
     * de sable, cd_ref 17642) : varchar(255) déborde.
     */
    public function up(): void
    {
        Schema::table('znieff_especes', function (Blueprint $table) {
            $table->text('nom_vernaculaire')->nullable()->after('nom_cite');
        });
    }

    public function down(): void
    {
        Schema::table('znieff_especes', function (Blueprint $table) {
            $table->dropColumn('nom_vernaculaire');
        });
    }
};
