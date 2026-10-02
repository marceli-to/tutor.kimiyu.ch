<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Eltern können eine Grafik in der Bearbeiten-Ansicht ausblenden. Sie bleibt gespeichert
     * und kommt mit «Grafik neu erstellen» zurück.
     */
    public function up(): void
    {
        Schema::table('lesson_graphics', function (Blueprint $table) {
            $table->boolean('hidden')->default(false)->after('error');
        });
    }

    public function down(): void
    {
        Schema::table('lesson_graphics', function (Blueprint $table) {
            $table->dropColumn('hidden');
        });
    }
};
