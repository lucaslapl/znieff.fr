<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Journal des imports de référentiels : source, version datée, volume.
     * C'est de là que sort l'affichage « données INPN — base … » en pied de
     * page et sur la page Données & méthodologie.
     */
    public function up(): void
    {
        Schema::create('referentiel_imports', function (Blueprint $table) {
            $table->id();
            $table->string('source')->unique(); // znieff | natura | aires | adminexpress
            $table->string('version_source');
            $table->unsignedInteger('nb_entites');
            $table->timestamp('importe_le');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referentiel_imports');
    }
};
