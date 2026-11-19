<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('natura_communes', function (Blueprint $table) {
            $table->string('site_code', 12);
            $table->string('insee', 5);
            $table->decimal('surface_ha', 12, 4);
            $table->decimal('part_zonage', 6, 3);  // % du site dans la commune
            $table->decimal('part_commune', 6, 3); // % du territoire communal couvert
            $table->timestamps();

            $table->primary(['site_code', 'insee']);
            $table->foreign('site_code')->references('code')->on('natura_sites')->cascadeOnDelete();
            $table->foreign('insee')->references('insee')->on('communes')->cascadeOnDelete();
            $table->index('insee');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('natura_communes');
    }
};
