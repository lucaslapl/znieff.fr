<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('natura_sites', function (Blueprint $table) {
            $table->string('code', 12)->primary(); // ex. FR8212001
            $table->string('type', 4);             // SIC (ZSC) | ZPS
            $table->string('nom')->nullable();
            $table->decimal('superficie_ha', 12, 2)->nullable();      // calculée (affichée)
            $table->decimal('superficie_ref_ha', 12, 2)->nullable(); // officielle FSD
            $table->string('precision_saisie', 20)->nullable();     // ex. 1/25 000
            $table->date('date_designation')->nullable();
            $table->date('date_modif_geo')->nullable();
            $table->string('territoire', 10)->index();
            $table->decimal('bbox_min_lat', 9, 6)->nullable();
            $table->decimal('bbox_max_lat', 9, 6)->nullable();
            $table->decimal('bbox_min_lng', 9, 6)->nullable();
            $table->decimal('bbox_max_lng', 9, 6)->nullable();
            $table->string('url_inpn')->nullable();
            $table->timestamps();

            $table->index('nom');
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('natura_sites');
    }
};
