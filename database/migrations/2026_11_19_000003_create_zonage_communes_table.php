<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pivot ZNIEFF × commune : la table de croisement cœur du portail.
     * Surfaces et parts précalculées en local (PostGIS, Lambert-93) lors de
     * l'import — jamais calculées à la volée.
     */
    public function up(): void
    {
        Schema::create('zonage_communes', function (Blueprint $table) {
            $table->string('zonage_id', 12);
            $table->string('insee', 5);
            $table->decimal('surface_ha', 12, 4);
            $table->decimal('part_zonage', 6, 3);  // % du zonage dans la commune
            $table->decimal('part_commune', 6, 3); // % du territoire communal couvert
            $table->timestamps();

            $table->primary(['zonage_id', 'insee']);
            $table->foreign('zonage_id')->references('id')->on('zonages')->cascadeOnDelete();
            $table->foreign('insee')->references('insee')->on('communes')->cascadeOnDelete();
            $table->index('insee');
            $table->index('part_zonage');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zonage_communes');
    }
};
