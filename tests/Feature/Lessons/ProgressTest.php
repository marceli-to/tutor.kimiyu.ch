<?php

use App\Enums\LessonStatus;
use App\Lessons\Progress;
use App\Models\Attempt;
use App\Models\Child;
use App\Models\Generation;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->child = Child::factory()->for($this->user)->create(['name' => 'Mia']);
    // Fotosynthese: 5 Quizfragen, 5 Lücken, kein Sortierspiel
    $this->lesson = Lesson::factory()->for($this->child)->fromFixture('fotosynthese')->create();
    // Ökosystem: 5 Quizfragen, 12 Sortier-Begriffe
    $this->eco = Lesson::factory()->for($this->child)->fromFixture('oekosystem')->create();
});

function answer(array $data, ?Lesson $lesson = null, ?string $token = null): TestResponse
{
    $lesson ??= test()->lesson;

    return test()->postJson(route('shared.answer', [$token ?? test()->child->share_token, $lesson]), $data);
}

function attempts(Child $child, Lesson $lesson, string $module, string $itemId, array $results): void
{
    foreach ($results as $i => $correct) {
        Attempt::create([
            'child_id' => $child->id,
            'lesson_id' => $lesson->id,
            'module' => $module,
            'item_id' => $itemId,
            'correct' => $correct,
            'created_at' => now()->addSeconds($i),
        ]);
    }
}

describe('answers', function () {
    it('checks quiz answers on the server', function () {
        // q1: richtige Antwort ist Index 1
        answer(['module' => 'quiz', 'item_id' => 'q1', 'answer' => 1])->assertOk()->assertJson(['correct' => true]);
        answer(['module' => 'quiz', 'item_id' => 'q1', 'answer' => 0])->assertOk()->assertJson(['correct' => false]);

        expect(Attempt::orderBy('id')->pluck('correct')->all())->toBe([true, false]);
    });

    it('ignores what the browser claims', function () {
        answer(['module' => 'quiz', 'item_id' => 'q1', 'answer' => 0, 'correct' => true]);

        expect(Attempt::sole()->correct)->toBeFalse();
    });

    it('checks sorting and cloze answers', function () {
        answer(['module' => 'sorting', 'item_id' => 's1', 'answer' => 'cat1'], $this->eco)->assertJson(['correct' => true]);
        answer(['module' => 'sorting', 'item_id' => 's1', 'answer' => 'cat2'], $this->eco)->assertJson(['correct' => false]);
        answer(['module' => 'cloze', 'item_id' => 'g1', 'answer' => ' co2 '])->assertJson(['correct' => true]);
        answer(['module' => 'cloze', 'item_id' => 'g1', 'answer' => 'Sauerstoff'])->assertJson(['correct' => false]);
    });

    it('rejects items that do not exist', function () {
        answer(['module' => 'quiz', 'item_id' => 'q99', 'answer' => 0])->assertStatus(422);
        answer(['module' => 'sorting', 'item_id' => 's1', 'answer' => 'cat1'])->assertStatus(422);
        answer(['module' => 'flashcards', 'item_id' => 'k1', 'answer' => 'x'])->assertStatus(422);
        // Module names from before the English keys are no longer accepted
        answer(['module' => 'sortieren', 'item_id' => 's1', 'answer' => 'cat1'], $this->eco)->assertStatus(422);

        expect(Attempt::count())->toBe(0);
    });

    it('only accepts answers for published lessons of this child', function () {
        $this->lesson->update(['status' => LessonStatus::Review]);
        answer(['module' => 'quiz', 'item_id' => 'q1', 'answer' => 1])->assertNotFound();

        $sibling = Child::factory()->for($this->user)->create();
        answer(['module' => 'quiz', 'item_id' => 'q1', 'answer' => 1], $this->eco, $sibling->share_token)->assertNotFound();

        answer(['module' => 'quiz', 'item_id' => 'q1', 'answer' => 1], $this->eco, 'falsch')->assertNotFound();

        expect(Attempt::count())->toBe(0);
    });
});

describe('status', function () {
    it('derives the status from the last two answers', function () {
        attempts($this->child, $this->lesson, 'quiz', 'q1', [true, true]);
        attempts($this->child, $this->lesson, 'quiz', 'q2', [false, true]);
        attempts($this->child, $this->lesson, 'quiz', 'q3', [true]);
        attempts($this->child, $this->lesson, 'quiz', 'q4', [true, true, false]);

        $status = collect(Progress::forLesson($this->child, $this->lesson))->pluck('status', 'id');

        expect($status['q1'])->toBe('sitzt')
            ->and($status['q2'])->toBe('fast')
            ->and($status['q3'])->toBe('fast')
            ->and($status['q4'])->toBe('ueben')
            ->and($status['q5'])->toBe('offen')
            ->and($status['g1'])->toBe('offen');
    });

    it('lists what still needs practice, hardest first', function () {
        attempts($this->child, $this->lesson, 'quiz', 'q2', [true]);
        attempts($this->child, $this->lesson, 'cloze', 'g1', [false]);

        $summary = Progress::summaries($this->child, collect([$this->lesson]))[$this->lesson->id];

        expect($summary['total'])->toBe(10)
            ->and($summary['counts'])->toBe(['sitzt' => 0, 'fast' => 1, 'ueben' => 1, 'offen' => 8])
            ->and(array_column($summary['open'], 'id'))->toBe(['g1', 'q2'])
            ->and($summary['open'][0]['text'])->toBe('Lücke: Kohlenstoffdioxid');
    });

    it('counts attempts per child only', function () {
        $sibling = Child::factory()->for($this->user)->create();
        attempts($sibling, $this->lesson, 'quiz', 'q1', [true, true]);

        expect(collect(Progress::forLesson($this->child, $this->lesson))->firstWhere('id', 'q1')['status'])->toBe('offen');
    });
});

describe('pages', function () {
    it('shows the parent what sits and what not', function () {
        attempts($this->child, $this->eco, 'sorting', 's6', [false]);
        attempts($this->child, $this->eco, 'quiz', 'q1', [true, true]);

        $this->actingAs($this->user)->get(route('children.progress', $this->child))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('children/Progress')
                ->where('child.name', 'Mia')
                ->whereNot('child.lastActivity', null)
                ->has('lessons', 2)
                ->where('lessons.1.title', 'Biotop + Biozönose = Ökosystem')
                ->where('lessons.1.counts.sitzt', 1)
                ->where('lessons.1.counts.ueben', 1)
                ->where('lessons.1.total', 17)
                ->where('lessons.1.open.0.text', 'Bakterien')
            );
    });

    it('leaves deleted lessons out of the progress', function () {
        attempts($this->child, $this->eco, 'quiz', 'q1', [true]);
        $this->eco->delete();

        $this->actingAs($this->user)->get(route('children.progress', $this->child))
            ->assertInertia(fn (Assert $page) => $page
                ->has('lessons', 1)
                ->where('lessons.0.id', $this->lesson->id)
            );
    });

    it('works for a lesson without quiz', function () {
        $content = $this->eco->content;
        $content['modules']['quiz'] = null;
        $this->eco->update(['content' => $content]);
        attempts($this->child, $this->eco, 'sorting', 's6', [true, true]);

        expect(Progress::forLesson($this->child, $this->eco->fresh()))->toHaveCount(12);

        $this->actingAs($this->user)->get(route('children.progress', $this->child))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('lessons.1.total', 12)
                ->where('lessons.1.counts.sitzt', 1)
            );
    });

    it('keeps the progress private to the parent', function () {
        $this->actingAs(User::factory()->create())->get(route('children.progress', $this->child))->assertForbidden();
    });

    it('shows the child how much sits per lesson', function () {
        attempts($this->child, $this->lesson, 'quiz', 'q1', [true, true]);

        $this->get(route('shared.index', $this->child->share_token))
            ->assertInertia(fn (Assert $page) => $page
                ->where('subjects.0.lessons', fn ($lessons) => collect($lessons)->firstWhere('id', $this->lesson->id)['progress'] === ['sitzt' => 1, 'total' => 10])
            );
    });

    it('sums up the costs per month and lesson', function () {
        $this->lesson->generations()->createMany([
            ['user_id' => $this->user->id, 'step' => 'analysis', 'model' => 'claude-opus-5-5', 'status' => 'ok', 'output_tokens' => 1000, 'cost_usd' => 0.1, 'duration_ms' => 30_000],
            ['user_id' => $this->user->id, 'step' => 'graphic', 'model' => 'claude-opus-5-5', 'status' => 'error', 'output_tokens' => 0, 'cost_usd' => 0.05, 'duration_ms' => 1_000],
        ]);
        $foreign = Lesson::factory()->fromFixture()->create();
        $foreign->generations()->create(
            ['user_id' => $foreign->child->user_id, 'step' => 'analysis', 'model' => 'claude-opus-5-5', 'status' => 'ok', 'cost_usd' => 9.99],
        );

        $this->actingAs($this->user)->get(route('costs'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Costs')
                ->where('total', 0.15)
                ->where('months.0.lessons', 1)
                ->where('months.0.calls', 2)
                ->where('lessons.0.title', 'Wie macht ein Blatt Zucker aus Licht?')
                ->where('lessons.0.failed', 1)
                ->where('lessons.0.outputTokens', 1000)
                ->where('lessons.0.costUsd', 0.15)
                ->where('lessons.0.deleted', false)
            );
    });

    it('shows deleted lessons and orphaned costs on the costs page', function () {
        $this->eco->generations()->forceCreate(['user_id' => $this->user->id, 'step' => 'analysis', 'model' => 'claude-opus-5-5', 'status' => 'ok', 'cost_usd' => 0.3, 'created_at' => now()->subMinute()]);
        $this->eco->delete();
        // Lernseite samt Kind gelöscht: nur noch das Konto ist bekannt
        Generation::forceCreate(['user_id' => $this->user->id, 'step' => 'graphic', 'model' => 'claude-opus-5-5', 'status' => 'ok', 'cost_usd' => 0.2, 'created_at' => now()->subMinutes(2)]);
        Generation::forceCreate(['user_id' => $this->user->id, 'step' => 'analysis', 'model' => 'claude-opus-5-5', 'status' => 'error', 'cost_usd' => 0.1, 'created_at' => now()->subMinutes(3)]);
        // Fremde Kosten ohne Lernseite zählen nicht
        Generation::create(['user_id' => User::factory()->create()->id, 'step' => 'analysis', 'model' => 'claude-opus-5-5', 'status' => 'ok', 'cost_usd' => 9.99]);

        $this->actingAs($this->user)->get(route('costs'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('total', 0.6)
                ->where('months.0.calls', 3)
                ->has('steps', 2)
                ->has('lessons', 2)
                ->where('lessons.0.id', $this->eco->id)
                ->where('lessons.0.title', 'Biotop + Biozönose = Ökosystem')
                ->where('lessons.0.child', 'Mia')
                ->where('lessons.0.deleted', true)
                ->where('lessons.0.costUsd', 0.3)
                ->where('lessons.1.id', null)
                ->where('lessons.1.title', 'Gelöschte Lernseiten')
                ->where('lessons.1.child', null)
                ->where('lessons.1.deleted', true)
                ->where('lessons.1.calls', 2)
                ->where('lessons.1.failed', 1)
                ->where('lessons.1.costUsd', 0.3)
            );
    });

    it('shows the average cost per step and model', function () {
        $this->lesson->generations()->createMany([
            ['user_id' => $this->user->id, 'step' => 'graphic', 'model' => 'claude-opus-5-5', 'status' => 'ok', 'input_tokens' => 10_000, 'output_tokens' => 20_000, 'cost_usd' => 0.6],
            ['user_id' => $this->user->id, 'step' => 'graphic', 'model' => 'claude-opus-5-5', 'status' => 'ok', 'input_tokens' => 12_000, 'output_tokens' => 10_000, 'cost_usd' => 0.4],
            ['user_id' => $this->user->id, 'step' => 'graphic', 'model' => 'claude-opus-5-5', 'status' => 'error', 'input_tokens' => 0, 'output_tokens' => 0, 'cost_usd' => 0.05],
            ['user_id' => $this->user->id, 'step' => 'modules', 'model' => 'claude-sonnet-5-5', 'status' => 'ok', 'input_tokens' => 7_000, 'output_tokens' => 4_000, 'cost_usd' => 0.05],
        ]);

        // Der Durchschnitt zählt nur erfolgreiche Aufrufe, die Summe alle
        $this->actingAs($this->user)->get(route('costs'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('steps.0', [
                    'step' => 'graphic',
                    'model' => 'claude-opus-5-5',
                    'calls' => 3,
                    'failed' => 1,
                    'inputTokens' => 11_000,
                    'outputTokens' => 15_000,
                    'avgUsd' => 0.5,
                    'totalUsd' => 1.05,
                ])
                ->where('steps.1.step', 'modules')
            );
    });
});
