<?php

use App\Models\Child;
use App\Models\Lesson;
use App\Models\LessonGraphic;
use Database\Factories\LessonFactory;
use Illuminate\Support\Facades\DB;

describe('migration', function () {
    it('moves existing graphics to position 1 and sets the graphics mode', function () {
        $migration = require database_path('migrations/2026_10_02_140000_create_lesson_graphics_table.php');
        $migration->down();

        $child = Child::factory()->create();
        $hero = LessonFactory::fixture('fotosynthese.hero');
        $base = ['child_id' => $child->id, 'status' => 'review', 'subject' => 'Biologie', 'level' => '2. Sek', 'created_at' => now(), 'updated_at' => now()];

        $withHero = DB::table('lessons')->insertGetId([...$base, 'hero_plan' => json_encode(['muster' => 'regler', 'idee' => 'Regler']), 'hero' => json_encode($hero)]);
        $failed = DB::table('lessons')->insertGetId([...$base, 'hero_plan' => json_encode(['muster' => 'schritte', 'idee' => 'Schritte']), 'hero_error' => 'Kaputt']);
        $switchedOff = DB::table('lessons')->insertGetId([...$base, 'with_hero' => false]);
        $nothingFits = DB::table('lessons')->insertGetId($base);

        $migration->up();

        expect(DB::table('lessons')->orderBy('id')->pluck('graphics_mode', 'id')->all())
            ->toBe([$withHero => 'auto', $failed => 'auto', $switchedOff => 'none', $nothingFits => 'auto'])
            ->and(LessonGraphic::count())->toBe(2);

        $first = LessonGraphic::where('lesson_id', $withHero)->sole();
        expect($first->position)->toBe(1)
            ->and($first->plan)->toBe(['muster' => 'regler', 'idee' => 'Regler'])
            ->and($first->graphic)->toBe($hero)
            ->and($first->error)->toBeNull()
            ->and($first->request)->toBeNull();

        $second = LessonGraphic::where('lesson_id', $failed)->sole();
        expect($second->position)->toBe(1)
            ->and($second->plan)->toBe(['muster' => 'schritte', 'idee' => 'Schritte'])
            ->and($second->graphic)->toBeNull()
            ->and($second->error)->toBe('Kaputt');
    });
});

describe('model', function () {
    it('orders the graphics by position and finds one by position', function () {
        $lesson = Lesson::factory()->create();
        $lesson->graphics()->create(['position' => 3, 'request' => 'Drei']);
        $lesson->graphics()->create(['position' => 1, 'request' => 'Eins']);
        $lesson->graphics()->create(['position' => 2, 'request' => 'Zwei']);

        expect($lesson->graphics()->pluck('position')->all())->toBe([1, 2, 3])
            ->and($lesson->graphic(2)?->request)->toBe('Zwei')
            ->and($lesson->graphic(3)?->lesson->is($lesson))->toBeTrue()
            ->and(Lesson::factory()->create()->graphic(1))->toBeNull();
    });

    it('defaults to letting the ai decide', function () {
        expect(Lesson::factory()->create()->fresh()->graphics_mode)->toBe('auto')
            ->and(new Lesson()->graphics_mode)->toBe('auto');
    });

    it('creates graphic 1 for a lesson from a fixture', function () {
        $lesson = Lesson::factory()->fromFixture('oekosystem')->create();
        $hero = LessonFactory::fixture('oekosystem.hero');

        $graphic = $lesson->graphic(1);
        expect($lesson->graphics)->toHaveCount(1)
            ->and($graphic->graphic)->toBe($hero)
            ->and($graphic->plan)->toBe(['muster' => $hero['muster'], 'idee' => $hero['beschreibung']])
            ->and($graphic->error)->toBeNull();
    });
});
