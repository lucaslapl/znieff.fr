<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ap_communes', function (Blueprint $table) {
            $table->string('ap_id', 20);
            $table->string('insee', 5);
            $table->decimal('surface_ha', 12, 4);
            $table->decimal('part_zonage', 6, 3);  // % de l'aire protégée dans la commune
            $table->decimal('part_commune', 6, 3); // % du territoire communal couvert
            $table->timestamps();

            $table->primary(['ap_id', 'insee']);
            $table->foreign('ap_id')->references('id')->on('aires_protegees')->cascadeOnDelete();
            $table->foreign('insee')->references('insee')->on('communes')->cascadeOnDelete();
            $table->index('insee');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ap_communes');
    }
};
