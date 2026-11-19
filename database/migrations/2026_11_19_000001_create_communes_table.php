<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Référentiel communal (AdminExpress COG — IGN). Aucune géométrie en
     * base : le centroïde, la superficie calculée et le contour précalculé
     * (GeoJSON statique dans public/geo/) suffisent au site public.
     */
    public function up(): void
    {
        Schema::create('communes', function (Blueprint $table) {
            $table->string('insee', 5)->primary();
            $table->string('slug')->unique();
            $table->string('nom');
            $table->string('dept', 3)->index();
            $table->string('dept_nom')->nullable();
            $table->string('reg_code', 2)->nullable();
            $table->string('reg_nom')->nullable();
            $table->unsignedInteger('population')->nullable();
            // Superficie officielle INSEE (référence), et superficie calculée
            // sur la géométrie AdminExpress en localiless (Lambert-93) : c'est
            // la seconde qui sert de dénominateur aux parts de recouvrement.
            $table->decimal('superficie_ha', 12, 2)->nullable();       // INSEE (affichée)
            $table->decimal('superficie_calc_ha', 12, 2)->nullable();  // géométrie (calculs)
            $table->decimal('lat', 9, 6)->nullable();
            $table->decimal('lng', 9, 6)->nullable();
            // 190 : les grandes communes cumulent leurs codes postaux
            // (Marseille ≈ 95 caractères pour 16 codes).
            $table->string('codes_postaux', 190)->nullable();
            $table->boolean('has_abc')->default(false);
            $table->timestamps();

            $table->index('nom');
            $table->index('slug');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communes');
    }
};
