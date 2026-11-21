<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Espèces citées par les ZNIEFF (REF_ESPECE.csv INPN, ~2,1 M de lignes
     * France entière). Table purement attributaire — aucune géométrie, donc
     * aucune localisation d'espèce : uniquement le nom cité.
     *
     * Le flag `confidentielle` (FG_CONF=true ou FG_ESP=C) est conservé en
     * base mais l'espèce ne doit JAMAIS être affichée ni comptée : le filtre
     * `confidentielle = false` est posé côté requête (App\Support\Especes).
     */
    public function up(): void
    {
        Schema::create('znieff_especes', function (Blueprint $table) {
            $table->id();
            $table->string('zonage_id', 12); // NM_SFFZN → zonages.id
            $table->unsignedInteger('cd_ref'); // TAXREF
            $table->string('nom_cite'); // peut contenir de l'italique HTML INPN (<i>…</i>)
            $table->string('groupe_taxo', 60)->nullable(); // Oiseaux, Mammifères…
            $table->boolean('determinante')->default(false); // FG_ESP = D
            $table->boolean('confidentielle')->default(false); // JAMAIS affichée
            $table->timestamps();

            $table->foreign('zonage_id')->references('id')->on('zonages')->cascadeOnDelete();
            $table->index('zonage_id');
            $table->index('cd_ref');
            $table->index(['determinante', 'confidentielle']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('znieff_especes');
    }
};
