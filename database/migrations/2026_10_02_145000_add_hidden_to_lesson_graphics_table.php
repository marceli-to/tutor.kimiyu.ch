<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Parents can hide a graphic in the edit view. It stays stored
     * and comes back with «Grafik neu erstellen».
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
