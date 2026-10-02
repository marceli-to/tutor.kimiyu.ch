<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bis zu drei Grafiken pro Lernseite statt einer in der Lernseite selbst.
     * Bestehende Grafiken werden zu Grafik 1; die alten Spalten bleiben vorerst (entfernt in einer späteren Migration).
     */
    public function up(): void
    {
        Schema::create('lesson_graphics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            // 1 steht oben auf der Seite, 2 und 3 im passenden Abschnitt
            $table->unsignedTinyInteger('position');
            // Wunsch der Eltern («Selbst beschreiben»)
            $table->text('request')->nullable();
            // Gewünschtes Muster (HeroPattern)
            $table->string('pattern')->nullable();
            // Plan der Analyse: {muster, idee}
            $table->json('plan')->nullable();
            // Fertige Grafik: {muster, beschreibung, css, markup, script}
            $table->json('graphic')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['lesson_id', 'position']);
        });

        Schema::table('lessons', function (Blueprint $table) {
            // none: keine Grafik, auto: die KI entscheidet (höchstens eine), custom: Wünsche der Eltern
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
        // Verloren beim Rollback: die Wünsche der Eltern und alle Grafiken ausser Grafik 1,
        // die noch in den alten Spalten steht. «Selbst beschreiben» wird zu «mit Grafik».
        Schema::dropIfExists('lesson_graphics');

        Schema::table('lessons', function (Blueprint $table) {
            $table->dropColumn('graphics_mode');
        });
    }
};
