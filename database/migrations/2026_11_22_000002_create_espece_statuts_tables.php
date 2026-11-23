<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Enrichissement des fiches espèces (BDC v18 + TAXREF v18, INPN/MNHN),
     * filtré aux cd_ref du périmètre importé (znieff_especes ∪ natura_especes).
     * Tables purement attributaires.
     *
     * - taxons_ref : extrait de TAXREFv18 (nom valide, groupes INPN, habitat,
     *   présence France / outre-mer, URL de la fiche INPN du taxon).
     * - espece_statuts : statuts de protection/réglementation de la Base de
     *   Connaissance (BDC v18). Les lignes ZNIEFF-déterminantes (ZDET) et
     *   SENSIBILITE (SENSNAT/SENSREG/SENSDEP) ne sont JAMAIS importées ici :
     *   la sensibilité ne s'affiche pas, elle ne sert que de filtre côté
     *   requête (App\Support\Especes) ; les statuts communaux (INSEEC) sont
     *   exclus (trop verbeux, sans intérêt à l'échelle d'une fiche espèce).
     */
    public function up(): void
    {
        Schema::create('taxons_ref', function (Blueprint $table) {
            $table->unsignedInteger('cd_ref')->primary(); // TAXREF
            $table->text('nom_valide')->nullable(); // nom + auteur, peut dépasser 255 caractères
            $table->string('groupe1_inpn', 60)->nullable();
            $table->string('groupe2_inpn', 60)->nullable();
            $table->string('habitat_libelle', 80)->nullable(); // HABITAT traduit via habitats_note.csv
            $table->string('presence_fr', 1)->nullable();      // statut de présence TAXREF (P, E, I…)
            $table->boolean('present_om')->default(false);     // au moins un territoire d'outre-mer = P
            $table->text('url_inpn')->nullable();              // fiche INPN du taxon
        });

        Schema::create('espece_statuts', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('cd_ref'); // TAXREF → taxons_ref.cd_ref / znieff_especes.cd_ref
            $table->string('cd_type_statut', 12);   // PN, DH, BERN, LRN, PNA…
            $table->string('lb_type_statut', 120);
            $table->string('regroupement_type', 60); // Protection, Liste rouge, Directives européennes…
            $table->string('code_statut', 80)->nullable();  // ex. NV1, CDH2, IBE1, NT, pr. B2b(iii)…
            $table->text('label_statut')->nullable();       // libellé complet, souvent > 255 caractères
            $table->text('rq_statut')->nullable();          // remarque INPN
            $table->string('cd_sig', 12);                   // WORLD, ETATFRA, TERFXFR, INSEER…, INSEED…
            $table->string('lb_adm_tr', 120)->nullable();   // libellé du territoire d'application
            $table->string('niveau_admin', 40)->nullable();
            $table->text('doc_url')->nullable();            // texte réglementaire référencé (URL longue)
            $table->text('full_citation')->nullable();      // référence bibliographique INPN
            $table->string('thematique', 30)->default('STATUTS');
            $table->timestamps();

            $table->index('cd_ref');
            $table->index(['cd_ref', 'cd_type_statut']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('espece_statuts');
        Schema::dropIfExists('taxons_ref');
    }
};
