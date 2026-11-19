<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Communes voisines (rayon 15 km autour des centroïdes), précalculées
     * lors du rafraîchissement des référentiels. Paires normalisées
     * (insee_a < insee_b) sans doublon.
     */
    public function up(): void
    {
        Schema::create('commune_communes', function (Blueprint $table) {
            $table->string('insee_a', 5);
            $table->string('insee_b', 5);
            $table->unsignedInteger('distance_m');
            $table->timestamps();

            $table->primary(['insee_a', 'insee_b']);
            $table->foreign('insee_a')->references('insee')->on('communes')->cascadeOnDelete();
            $table->foreign('insee_b')->references('insee')->on('communes')->cascadeOnDelete();
            $table->index('insee_b');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commune_communes');
    }
};
