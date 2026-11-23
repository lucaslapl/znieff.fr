<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fiches PDF des ZNIEFF (INPN/MNHN) : métadonnées attributaires du dump
     * « PDF ZNIEFF » (un PDF par zone, nommé {NM_SFFZN}.pdf, rangé par
     * dossier régional). Les fichiers eux-mêmes sont copiés HORS de public/
     * (storage/app/portail/pdf/znieff/) et servis UNIQUEMENT via la route
     * filtrée /znieff/{id}/fiche.pdf : un PDF de zone à espèces sensibles
     * ne doit jamais être accessible directement (URL devinable).
     *
     * RÈGLE ABSOLUE : la route vérifie côté requête (App\Support\Especes::
     * zoneSensible, même filtre que les listes d'espèces + flag confidentiel)
     * qu'aucun taxon sensible/confidentiel n'est cité dans la zone avant de
     * servir le PDF — sinon 404, comme si le PDF n'existait pas.
     */
    public function up(): void
    {
        Schema::create('znieff_pdfs', function (Blueprint $table) {
            $table->string('zonage_id', 12)->primary(); // NM_SFFZN = zonages.id
            $table->foreign('zonage_id')->references('id')->on('zonages')->cascadeOnDelete();
            $table->unsignedBigInteger('taille_octets')->default(0);
            $table->string('dossier_region', 60); // ex. 28_Normandie
            $table->string('nom_fichier', 24);    // ex. 230009093.pdf
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('znieff_pdfs');
    }
};
