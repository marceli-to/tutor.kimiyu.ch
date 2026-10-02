<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Die Grafiken stehen in lesson_graphics, der Modus in graphics_mode: die alten Spalten weg.
     */
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->dropColumn(['hero', 'hero_plan', 'hero_error', 'with_hero']);
        });
    }

    /**
     * Stellt die alten Spalten wieder her und kopiert Grafik 1 zurück. lesson_graphics bleibt;
     * die alten Spalten kennen aber nur eine Grafik: Grafiken 2 und 3, Wünsche und «ausgeblendet»
     * gibt es dort nicht. «Selbst beschreiben» wird zu «mit Grafik».
     */
    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->boolean('with_hero')->default(true)->after('notes');
            $table->json('hero_plan')->nullable()->after('content');
            $table->json('hero')->nullable()->after('hero_plan');
            $table->text('hero_error')->nullable()->after('hero');
        });

        DB::table('lessons')->where('graphics_mode', 'none')->update(['with_hero' => false]);

        DB::table('lesson_graphics')
            ->where('position', 1)
            ->lazyById()
            ->each(fn (object $graphic) => DB::table('lessons')->where('id', $graphic->lesson_id)->update([
                'hero_plan' => $graphic->plan,
                'hero' => $graphic->graphic,
                'hero_error' => $graphic->error,
            ]));
    }
};
