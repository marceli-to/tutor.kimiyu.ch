<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The graphics live in lesson_graphics, the mode in graphics_mode: drop the old columns.
     */
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->dropColumn(['hero', 'hero_plan', 'hero_error', 'with_hero']);
        });
    }

    /**
     * Restores the old columns and copies graphic 1 back. lesson_graphics stays;
     * but the old columns know only one graphic: graphics 2 and 3, wishes and «hidden»
     * don't exist there. «custom» becomes with_hero = true.
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
