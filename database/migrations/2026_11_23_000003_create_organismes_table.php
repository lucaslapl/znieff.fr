<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Organismes adhérents au SINP (référentiel public des producteurs de
     * données, INPN) — page /organismes « producteurs de données ».
     *
     * Table purement attributaire. On n'importe PAS les coordonnées
     * personnelles (mail, téléphone, adresse postale) : elles ne servent
     * pas à la page et le référentiel public suffit en lien. Champs retenus :
     * libellés, domaines d'intervention, zones géographiques, niveau
     * d'adhésion, statut, type, URL publique.
     */
    public function up(): void
    {
        Schema::create('organismes', function (Blueprint $table) {
            $table->string('id', 40)->primary(); // UUID du référentiel SINP
            $table->string('libelle_court', 200);
            $table->text('libelle_long')->nullable();
            $table->text('domaines')->nullable();   // séparés par « | » (Faune, Flore, Habitats, Fonge…)
            $table->text('zones')->nullable();      // territoires d'intervention
            $table->string('niveau_adhesion', 120)->nullable();
            $table->string('statut_organisme', 60)->nullable();
            $table->string('type_organisme', 120)->nullable();
            $table->string('url', 255)->nullable();
            $table->date('date_adhesion')->nullable();
            $table->timestamps();

            $table->index('libelle_court');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organismes');
    }
};
