<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Up to three graphics per lesson instead of one in the lesson itself.
     * Existing graphics become graphic 1; the old columns stay for now (removed in a later migration).
     */
    public function up(): void
    {
        Schema::create('lesson_graphics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            // 1 is at the top of the page, 2 and 3 in the matching section
            $table->unsignedTinyInteger('position');
            // The parents' wish (graphics mode «custom»)
            $table->text('request')->nullable();
            // Requested pattern (GraphicPattern)
            $table->string('pattern')->nullable();
            // Plan from the analysis: {pattern, idea}
            $table->json('plan')->nullable();
            // Finished graphic: {pattern, description, css, markup, script}
            $table->json('graphic')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['lesson_id', 'position']);
        });

        Schema::table('lessons', function (Blueprint $table) {
            // none: no graphic, auto: the AI decides (at most one), custom: the parents' wishes
            $table->string('graphics_mode')->default('auto')->after('with_hero');
        });

        DB::table('lessons')->where('with_hero', false)->update(['graphics_mode' => 'none']);

        DB::table('lessons')
            ->where(fn ($query) => $query->whereNotNull('hero_plan')->orWhereNotNull('hero')->orWhereNotNull('hero_error'))
            ->lazyById()
            ->each(fn (object $lesson) => DB::table('lesson_graphics')->insert([
                'lesson_id' => $lesson->id,
                'position' => 1,
                'plan' => $lesson->hero_plan,
                'graphic' => $lesson->hero,
                'error' => $lesson->hero_error,
                'created_at' => $lesson->updated_at ?? now(),
                'updated_at' => $lesson->updated_at ?? now(),
            ]));
    }

    public function down(): void
    {
        // Lost on rollback: the parents' wishes and all graphics except graphic 1,
        // which is still in the old columns. «custom» becomes with_hero = true.
        Schema::dropIfExists('lesson_graphics');

        Schema::table('lessons', function (Blueprint $table) {
            $table->dropColumn('graphics_mode');
        });
    }
};
