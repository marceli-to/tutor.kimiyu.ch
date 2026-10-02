<?php

use App\Enums\LessonStatus;
use App\Lessons\Ai\FakeLanguageModel;
use App\Models\Child;
use App\Models\Lesson;
use App\Models\LessonGraphic;
use App\Models\User;
use Database\Factories\LessonFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;

describe('migration', function () {
    it('moves existing graphics to position 1 and sets the graphics mode', function () {
        // Roll back the later migrations first, so the old columns are back
        (require database_path('migrations/2026_10_02_150000_drop_hero_columns_from_lessons_table.php'))->down();
        (require database_path('migrations/2026_10_02_145000_add_hidden_to_lesson_graphics_table.php'))->down();
        $migration = require database_path('migrations/2026_10_02_140000_create_lesson_graphics_table.php');
        $migration->down();

        $child = Child::factory()->create();
        $hero = LessonFactory::fixture('fotosynthese.graphic');
        $base = ['child_id' => $child->id, 'status' => 'review', 'subject' => 'Biologie', 'level' => '2. Sek', 'created_at' => now(), 'updated_at' => now()];

        $withHero = DB::table('lessons')->insertGetId([...$base, 'hero_plan' => json_encode(['pattern' => 'sliders', 'idea' => 'Regler']), 'hero' => json_encode($hero)]);
        $failed = DB::table('lessons')->insertGetId([...$base, 'hero_plan' => json_encode(['pattern' => 'steps', 'idea' => 'Schritte']), 'hero_error' => 'Kaputt']);
        $switchedOff = DB::table('lessons')->insertGetId([...$base, 'with_hero' => false]);
        $nothingFits = DB::table('lessons')->insertGetId($base);

        $migration->up();

        expect(DB::table('lessons')->orderBy('id')->pluck('graphics_mode', 'id')->all())
            ->toBe([$withHero => 'auto', $failed => 'auto', $switchedOff => 'none', $nothingFits => 'auto'])
            ->and(LessonGraphic::count())->toBe(2);

        $first = LessonGraphic::where('lesson_id', $withHero)->sole();
        expect($first->position)->toBe(1)
            ->and($first->plan)->toBe(['pattern' => 'sliders', 'idea' => 'Regler'])
            ->and($first->graphic)->toBe($hero)
            ->and($first->error)->toBeNull()
            ->and($first->request)->toBeNull();

        $second = LessonGraphic::where('lesson_id', $failed)->sole();
        expect($second->position)->toBe(1)
            ->and($second->plan)->toBe(['pattern' => 'steps', 'idea' => 'Schritte'])
            ->and($second->graphic)->toBeNull()
            ->and($second->error)->toBe('Kaputt');
    });
});

describe('dropping the old columns', function () {
    it('removes the old columns and copies graphic 1 back on rollback', function () {
        $migration = require database_path('migrations/2026_10_02_150000_drop_hero_columns_from_lessons_table.php');
        $hero = LessonFactory::fixture('fotosynthese.graphic');

        expect(Schema::hasColumns('lessons', ['hero', 'hero_plan', 'hero_error', 'with_hero']))->toBeFalse();

        $custom = Lesson::factory()->fromFixture()->create(['graphics_mode' => 'custom']);
        $custom->graphic(1)->update(['error' => 'Kaputt']);
        $custom->graphics()->create(['position' => 2, 'request' => 'Zwei', 'graphic' => $hero]);
        $none = Lesson::factory()->create(['graphics_mode' => 'none']);

        $migration->down();

        $row = DB::table('lessons')->find($custom->id);
        expect(json_decode($row->hero, true))->toBe($hero)
            ->and(json_decode($row->hero_plan, true))->toBe($custom->graphic(1)->plan)
            ->and($row->hero_error)->toBe('Kaputt')
            ->and((bool) $row->with_hero)->toBeTrue()
            ->and((bool) DB::table('lessons')->find($none->id)->with_hero)->toBeFalse()
            ->and(DB::table('lessons')->find($none->id)->hero)->toBeNull()
            ->and(LessonGraphic::count())->toBe(2);

        $migration->up();

        expect(Schema::hasColumn('lessons', 'hero'))->toBeFalse()
            ->and(Schema::hasColumn('lessons', 'with_hero'))->toBeFalse()
            ->and(Lesson::count())->toBe(2)
            ->and(LessonGraphic::count())->toBe(2);
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
        $expected = LessonFactory::fixture('oekosystem.graphic');

        $graphic = $lesson->graphic(1);
        expect($lesson->graphics)->toHaveCount(1)
            ->and($graphic->graphic)->toBe($expected)
            ->and($graphic->plan)->toBe(['pattern' => $expected['pattern'], 'idea' => $expected['description']])
            ->and($graphic->error)->toBeNull();
    });
});

describe('display', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
        $this->child = Child::factory()->for($this->user)->create();

        // Graphic 2 in the second section; graphic 3 failed but has a place
        $content = LessonFactory::fixture('oekosystem');
        $content['sections'][] = ['title' => 'Die Nahrungskette', 'blocks' => [
            ['type' => 'paragraph', 'text' => 'Pflanzen werden von Tieren gefressen.', 'origin' => 'photo'],
            ['type' => 'graphic', 'number' => 2, 'origin' => 'photo'],
        ]];
        $content['sections'][0]['blocks'][] = ['type' => 'graphic', 'number' => 3, 'origin' => 'photo'];

        $this->lesson = Lesson::factory()->for($this->child)->fromFixture('oekosystem')->create([
            'graphics_mode' => 'custom',
            'content' => $content,
        ]);
        $this->lesson->graphics()->create([
            'position' => 2,
            'request' => 'Nahrungskette zum Durchklicken',
            'plan' => ['pattern' => 'steps', 'idea' => 'Vier Schritte'],
            'graphic' => [...LessonFactory::fixture('fotosynthese.graphic'), 'description' => 'Die Nahrungskette', 'markup' => '<p id="graphic-zwei">Zwei</p>'],
        ]);
        $this->lesson->graphics()->create([
            'position' => 3,
            'request' => 'Kreislauf',
            'plan' => ['pattern' => 'kreislauf', 'idea' => 'Kreislauf'],
            'error' => 'Die KI war nicht erreichbar.',
        ]);
    });

    function blocksOfType(array $content, string $type): array
    {
        return collect($content['sections'])
            ->flatMap(fn (array $section, int $k) => collect($section['blocks'])
                ->filter(fn (array $block) => $block['type'] === $type)
                ->map(fn (array $block) => [...$block, 'section' => $k]))
            ->values()
            ->all();
    }

    it('gives the parent all finished graphics and the state of each graphic', function () {
        $this->actingAs($this->user)->get(route('lessons.show', $this->lesson))
            ->assertInertia(fn (Assert $page) => $page
                ->has('lesson.graphics', 2)
                ->has('lesson.graphics.1.url')
                ->where('lesson.graphics.2.description', 'Die Nahrungskette')
                ->missing('lesson.graphics.3')
                ->where('parent.graphics', [
                    ['number' => 1, 'error' => null, 'canRegenerate' => true, 'hidden' => false],
                    ['number' => 2, 'error' => null, 'canRegenerate' => true, 'hidden' => false],
                    ['number' => 3, 'error' => 'Die KI war nicht erreichbar.', 'canRegenerate' => true, 'hidden' => false],
                ])
                ->where('parent.canRegenerate', ['quiz' => true])
                ->where('lesson.plannedGraphics', [1, 2, 3])
            );
    });

    it('gives the child only the finished graphics, without errors, wishes or plans', function () {
        $response = $this->get(route('shared.show', [$this->child->share_token, $this->lesson]));

        $response->assertInertia(fn (Assert $page) => $page
            ->has('lesson.graphics', 2)
            ->has('lesson.graphics.2.url')
            ->has('lesson.graphics.1.url')
        );

        $keys = [];
        array_walk_recursive($response->viewData('page')['props'], function ($value, $key) use (&$keys) {
            $keys[] = $key;
        });
        $flat = json_encode($response->viewData('page')['props']);

        expect($keys)->not->toContain('error')->not->toContain('request')->not->toContain('plan')
            ->and($flat)->not->toContain('Nahrungskette zum Durchklicken')
            ->and($flat)->not->toContain('Vier Schritte')
            ->and($flat)->not->toContain('nicht erreichbar');
    });

    it('removes the block of an unfinished graphic', function () {
        $this->get(route('shared.show', [$this->child->share_token, $this->lesson]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('lesson.content', fn ($content) => array_column(blocksOfType($content->toArray(), 'graphic'), 'number') === [2])
            );
    });

    it('drops a section that only held an unfinished graphic', function () {
        $content = $this->lesson->content;
        $content['sections'][] = ['title' => 'Der Kreislauf', 'blocks' => [['type' => 'graphic', 'number' => 3, 'origin' => 'photo']]];
        $this->lesson->update(['content' => $content]);
        $this->lesson->graphic(2)->update(['hidden' => true]);

        $this->get(route('shared.show', [$this->child->share_token, $this->lesson]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('lesson.content.sections', function ($sections) use ($content) {
                    $titles = array_column($sections->toArray(), 'title');

                    // «Die Nahrungskette» keeps its text, only the hidden graphic is gone
                    return $titles === array_column(array_slice($content['sections'], 0, -1), 'title')
                        && collect($sections)->every(fn ($section) => count($section['blocks']) > 0);
                })
            );
    });

    it('appends a finished graphic without a block to the last section', function () {
        $content = LessonFactory::fixture('oekosystem');
        $this->lesson->update(['content' => $content]);

        $this->get(route('shared.show', [$this->child->share_token, $this->lesson]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('lesson.content', function ($content) {
                    $blocks = blocksOfType($content->toArray(), 'graphic');

                    return count($blocks) === 1
                        && $blocks[0]['number'] === 2
                        && $blocks[0]['section'] === count($content['sections']) - 1;
                })
            );
    });

    it('serves each graphic under its own signed url', function () {
        $this->get(URL::signedRoute('lessons.graphic', [$this->lesson, 2]))
            ->assertOk()
            ->assertHeader('Content-Security-Policy')
            ->assertSee('<p id="graphic-zwei">', escape: false);

        $this->get(URL::signedRoute('lessons.graphic', [$this->lesson, 1]))
            ->assertOk()
            ->assertSee(LessonFactory::fixture('oekosystem.graphic')['markup'], escape: false);

        $this->get(route('lessons.graphic', [$this->lesson, 2]))->assertForbidden();
        $this->get(URL::signedRoute('lessons.graphic', [$this->lesson, 3]))->assertNotFound();
        $this->get(URL::signedRoute('lessons.graphic', [$this->lesson, 4]))->assertNotFound();
    });

    it('only offers a new graphic where there is a plan', function () {
        $this->lesson->graphic(3)->update(['plan' => null, 'error' => 'Passt nicht zum Stoff.']);
        $this->lesson->update(['status' => LessonStatus::Review]);

        $this->actingAs($this->user)->get(route('lessons.show', $this->lesson))
            ->assertInertia(fn (Assert $page) => $page
                ->where('parent.graphics.2', ['number' => 3, 'error' => 'Passt nicht zum Stoff.', 'canRegenerate' => false, 'hidden' => false])
                ->where('lesson.plannedGraphics', [1, 2])
            );
    });

    it('plans the graphics from the wishes before the analysis', function () {
        $lesson = Lesson::factory()->for($this->child)->create(['status' => LessonStatus::Generating, 'graphics_mode' => 'custom']);
        $lesson->graphics()->create(['position' => 1, 'request' => 'Eins']);
        $lesson->graphics()->create(['position' => 2, 'request' => 'Zwei']);

        $auto = Lesson::factory()->for($this->child)->create(['status' => LessonStatus::Generating, 'graphics_mode' => 'auto']);
        $none = Lesson::factory()->for($this->child)->create(['status' => LessonStatus::Generating, 'graphics_mode' => 'none']);

        $this->actingAs($this->user)->get(route('lessons.show', $lesson))
            ->assertInertia(fn (Assert $page) => $page->where('lesson.plannedGraphics', [1, 2]));
        $this->actingAs($this->user)->get(route('lessons.show', $auto))
            ->assertInertia(fn (Assert $page) => $page->where('lesson.plannedGraphics', [1]));
        $this->actingAs($this->user)->get(route('lessons.show', $none))
            ->assertInertia(fn (Assert $page) => $page->where('lesson.plannedGraphics', []));
    });

    function withoutGraphicBlock(array $content, int $number): array
    {
        foreach ($content['sections'] as $k => $section) {
            $content['sections'][$k]['blocks'] = array_values(array_filter(
                $section['blocks'],
                fn (array $block) => $block['type'] !== 'graphic' || $block['number'] !== $number,
            ));
        }

        return $content;
    }

    it('shows a short label for each graphic in the edit view', function () {
        $this->actingAs($this->user)->get(route('lessons.edit', $this->lesson))
            ->assertInertia(fn (Assert $page) => $page
                ->where('graphicLabels.2', 'Die Nahrungskette')
                ->where('graphicLabels.3', 'Kreislauf')
            );

        $this->lesson->graphic(3)->update(['plan' => null, 'request' => str_repeat('Lang ', 40)]);

        $this->actingAs($this->user)->get(route('lessons.edit', $this->lesson))
            ->assertInertia(fn (Assert $page) => $page
                ->where('graphicLabels.3', fn (string $label) => mb_strlen($label) <= 121 && str_ends_with($label, '…'))
            );
    });

    it('saves the content with its graphic blocks', function () {
        $this->actingAs($this->user)->put(route('lessons.update', $this->lesson), ['content' => $this->lesson->content])
            ->assertSessionHasNoErrors();

        expect($this->lesson->fresh()->content['sections'][1]['blocks'])->toContain(['type' => 'graphic', 'number' => 2, 'origin' => 'photo'])
            ->and($this->lesson->graphics()->where('hidden', true)->count())->toBe(0);
    });

    it('hides a graphic whose block the parent removed', function () {
        $this->actingAs($this->user)->put(route('lessons.update', $this->lesson), [
            'content' => withoutGraphicBlock($this->lesson->content, 2),
        ])->assertSessionHasNoErrors();

        expect($this->lesson->graphic(2)->fresh()->hidden)->toBeTrue()
            ->and($this->lesson->graphic(1)->fresh()->hidden)->toBeFalse()
            ->and($this->lesson->graphic(3)->fresh()->hidden)->toBeFalse();

        $this->actingAs($this->user)->get(route('lessons.show', $this->lesson))
            ->assertInertia(fn (Assert $page) => $page
                ->has('lesson.graphics', 1)
                ->has('lesson.graphics.1.url')
                ->where('lesson.content', fn ($content) => blocksOfType($content->toArray(), 'graphic') === [])
                ->where('parent.graphics.1', ['number' => 2, 'error' => null, 'canRegenerate' => true, 'hidden' => true])
            );

        $this->get(route('shared.show', [$this->child->share_token, $this->lesson]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('lesson.graphics', 1)
                ->where('lesson.content', fn ($content) => blocksOfType($content->toArray(), 'graphic') === [])
            );

        $this->get(URL::signedRoute('lessons.graphic', [$this->lesson, 2]))->assertNotFound();
    });

    it('shows a hidden graphic again when its block comes back', function () {
        $content = $this->lesson->content;
        $this->lesson->graphic(2)->update(['hidden' => true]);
        $this->lesson->update(['content' => withoutGraphicBlock($content, 2)]);

        $this->actingAs($this->user)->put(route('lessons.update', $this->lesson), ['content' => $content])
            ->assertSessionHasNoErrors();

        expect($this->lesson->graphic(2)->fresh()->hidden)->toBeFalse();
        $this->get(URL::signedRoute('lessons.graphic', [$this->lesson, 2]))->assertOk();
    });

    it('keeps appending a finished graphic that never had a block', function () {
        $this->lesson->update(['content' => withoutGraphicBlock($this->lesson->content, 2)]);

        $this->get(route('shared.show', [$this->child->share_token, $this->lesson]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('lesson.content', fn ($content) => array_column(blocksOfType($content->toArray(), 'graphic'), 'number') === [2])
            );
    });

    it('shows a graphic without a block in the edit view, so the parent can keep or hide it', function () {
        $this->lesson->update(['content' => withoutGraphicBlock($this->lesson->content, 2)]);

        $edited = null;
        $this->actingAs($this->user)->get(route('lessons.edit', $this->lesson))
            ->assertInertia(function (Assert $page) use (&$edited) {
                $page->where('lesson.content', function ($content) use (&$edited) {
                    $edited = $content->toArray();

                    $numbers = array_column(blocksOfType($edited, 'graphic'), 'number');
                    sort($numbers);

                    return $numbers === [2, 3];
                });
            });

        // Save unchanged: graphic 2 stays visible and now has a fixed place
        $this->actingAs($this->user)->put(route('lessons.update', $this->lesson), ['content' => $edited])
            ->assertSessionHasNoErrors();
        expect($this->lesson->graphic(2)->fresh()->hidden)->toBeFalse();

        // Save without the block: hidden
        $this->actingAs($this->user)->put(route('lessons.update', $this->lesson), ['content' => withoutGraphicBlock($edited, 2)])
            ->assertSessionHasNoErrors();
        expect($this->lesson->graphic(2)->fresh()->hidden)->toBeTrue();
    });

    it('places a graphic without a block in a section that still has room', function () {
        $content = withoutGraphicBlock($this->lesson->content, 2);
        $last = array_key_last($content['sections']);
        $content['sections'][$last]['blocks'] = array_fill(0, 4, ['type' => 'paragraph', 'text' => 'Text.', 'origin' => 'photo']);
        $this->lesson->update(['content' => $content]);

        $this->actingAs($this->user)->get(route('lessons.edit', $this->lesson))
            ->assertInertia(fn (Assert $page) => $page
                ->where('lesson.content', function ($content) use ($last) {
                    $blocks = collect(blocksOfType($content->toArray(), 'graphic'))->firstWhere('number', 2);

                    return $blocks !== null && $blocks['section'] !== $last;
                })
            );
    });

    it('shows a hidden graphic again after drawing it anew', function () {
        FakeLanguageModel::install();
        $this->lesson->graphic(2)->update(['hidden' => true]);
        $this->lesson->update(['content' => withoutGraphicBlock($this->lesson->content, 2)]);

        $this->actingAs($this->user)->post(route('lessons.graphic.regenerate', [$this->lesson, 2]))
            ->assertRedirect();

        expect($this->lesson->graphic(2)->fresh()->hidden)->toBeFalse();
        $this->actingAs($this->user)->get(route('lessons.show', $this->lesson))
            ->assertInertia(fn (Assert $page) => $page
                ->has('lesson.graphics.2.url')
                ->where('lesson.content', function ($content) {
                    $blocks = blocksOfType($content->toArray(), 'graphic');

                    return count($blocks) === 1 && $blocks[0]['section'] === count($content['sections']) - 1;
                })
            );
    });
});
