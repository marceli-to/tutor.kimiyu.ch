<?php

use App\Actions\Generation\PlanLesson as PlanLessonAction;
use App\Enums\LessonStatus;
use App\Jobs\CheckLesson;
use App\Jobs\FinishLesson;
use App\Jobs\GenerateLessonGraphic;
use App\Jobs\PlanLesson;
use App\Jobs\WriteLesson;
use App\Lessons\Ai\FakeLanguageModel;
use App\Lessons\Ai\ModelException;
use App\Lessons\Ai\Prompts;
use App\Lessons\Ai\Schemas;
use App\Lessons\GenerationFailed;
use App\Lessons\GenerationPipeline;
use App\Models\Child;
use App\Models\Lesson;
use App\Models\User;
use Carbon\CarbonInterface;
use Database\Factories\LessonFactory;
use Illuminate\Support\Facades\Bus;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\JsonSchema;

beforeEach(function () {
	$this->user = User::factory()->create();
	$this->child = Child::factory()->for($this->user)->create();
});

it('shows a planned lesson as «Plan prüfen» on the dashboard', function () {
	Lesson::factory()->for($this->child)->create([
		'status' => LessonStatus::Planned,
		'plan' => ['title' => 'Fotosynthese', 'key_idea' => 'Licht wird zu Zucker.', 'sections' => [], 'note' => null, 'removed_additions' => []],
		'review_plan' => true,
	]);

	$this->actingAs($this->user)
		->get(route('dashboard'))
		->assertInertia(fn (Assert $page) => $page
			->where('children.0.subjects.0.lessons.0.status', 'planned')
			->where('children.0.subjects.0.lessons.0.statusLabel', 'Plan prüfen')
		);
});

it('casts the plan fields', function () {
	$lesson = Lesson::factory()->for($this->child)->create([
		'status' => LessonStatus::Planned,
		'plan' => ['title' => 'A'],
		'plan_confirmed_at' => now(),
		'review_plan' => 1,
	])->fresh();

	expect($lesson->plan)->toBe(['title' => 'A'])
		->and($lesson->plan_confirmed_at)->toBeInstanceOf(CarbonInterface::class)
		->and($lesson->review_plan)->toBeTrue()
		->and($lesson->isPlanned())->toBeTrue();
});

function plannedLesson(Child $child, array $plan = [], array $attributes = []): Lesson
{
	$lesson = Lesson::factory()->for($child)->create([
		'status' => LessonStatus::Generating,
		'prompt' => 'Fotosynthese',
		'source_summary' => 'Pflanzen machen Zucker (ergänzt).',
		'additions' => ['Die Zellatmung wurde ergänzt.'],
		'plan' => [
			'title' => 'Wie macht ein Blatt Zucker?',
			'key_idea' => 'Pflanzen bauen mit Licht Zucker auf.',
			'sections' => [
				['title' => 'Die Zutaten', 'goal' => 'Licht, CO₂ und Wasser erkennen.'],
				['title' => 'Das Produkt', 'goal' => null],
			],
			'note' => 'Mehr Gewicht auf das Licht.',
			'removed_additions' => ['Die Geschichte der Forschung wurde ergänzt.'],
			...$plan,
		],
		...$attributes,
	]);

	return $lesson;
}

it('asks for title, key idea and sections in the analysis', function () {
	$properties = Schemas::analysis()['properties'];

	expect($properties)->toHaveKeys(['title', 'key_idea', 'sections'])
		->and($properties['sections']['items']['properties'])->toHaveKeys(['title', 'goal'])
		->and(Prompts::analysis(Lesson::factory()->for($this->child)->create(['prompt' => 'Fotosynthese']), [])->prompt)
		->toContain('`title`', '`key_idea`', '`sections`');
});

it('makes the confirmed plan binding for the text part', function () {
	$prompt = Prompts::pageRequest(plannedLesson($this->child), [])->prompt;

	expect($prompt)
		->toContain('Titel: Wie macht ein Blatt Zucker?')
		->toContain('Kernidee: Pflanzen bauen mit Licht Zucker auf.')
		->toContain('verbindlich, in dieser Reihenfolge')
		->toContain("1. Die Zutaten: Licht, CO₂ und Wasser erkennen.\n2. Das Produkt")
		->toContain('Anmerkung der Eltern zum Plan: Mehr Gewicht auf das Licht.')
		->toContain("Diese Ergänzungen haben die Eltern gestrichen, lass sie weg:\n- Die Geschichte der Forschung wurde ergänzt.")
		// The graphic plans stay
		->toContain('Diese Seite hat keine Grafik 1');
});

it('sends no plan text without a plan', function () {
	$lesson = plannedLesson($this->child);
	$lesson->update(['plan' => null]);

	expect(Prompts::pageRequest($lesson, [])->prompt)->not->toContain('Kernidee:')
		->and(Prompts::modules($lesson, [])->prompt)->not->toContain('Kernidee:');
});

it('gives the later steps the key idea, the sections, the note and the struck additions', function () {
	$lesson = plannedLesson($this->child);

	foreach ([Prompts::modules($lesson, []), Prompts::check($lesson, []), Prompts::repair($lesson, ['sections' => []], 'modules', ['Fehler.'])] as $request) {
		expect($request->prompt)
			->toContain('Kernidee: Pflanzen bauen mit Licht Zucker auf.')
			->toContain('Abschnitte: Die Zutaten · Das Produkt')
			->toContain('Anmerkung der Eltern zum Plan: Mehr Gewicht auf das Licht.')
			->toContain('Von den Eltern gestrichen, nicht verwenden:');
	}
});

it('gives the child view only the key idea', function () {
	$lesson = plannedLesson($this->child, attributes: ['content' => LessonFactory::fixture('fotosynthese')]);
	$graphic = $lesson->graphics()->create(['position' => 1, 'plan' => ['pattern' => 'sliders', 'idea' => 'Regler.']]);

	expect(Prompts::graphic($lesson, $graphic)->prompt)
		->toContain('Kernidee: Pflanzen bauen mit Licht Zucker auf.')
		->not->toContain('Mehr Gewicht auf das Licht')
		->not->toContain('gestrichen');
});

it('delivers a plan from the fake model', function () {
	$analysis = FakeLanguageModel::defaultResponse('analysis');

	expect($analysis['title'])->not->toBe('')
		->and($analysis['key_idea'])->not->toBe('')
		->and($analysis['sections'])->not->toBeEmpty()
		->and(JsonSchema::errors($analysis, Schemas::analysis()))->toBe([]);
});

describe('pipeline', function () {
	beforeEach(function () {
		$this->fake = FakeLanguageModel::install();
	});

	function requestLesson(Child $child, array $attributes = []): Lesson
	{
		return Lesson::factory()->for($child)->create(['prompt' => 'Fotosynthese', 'status' => LessonStatus::Draft, ...$attributes]);
	}

	it('stops after the plan when the parents want to review it', function () {
		$lesson = requestLesson($this->child, ['review_plan' => true]);

		GenerationPipeline::start($lesson);

		$lesson->refresh();
		expect($lesson->status)->toBe(LessonStatus::Planned)
			->and($lesson->step)->toBeNull()
			->and($lesson->content)->toBeNull()
			->and($lesson->plan_confirmed_at)->toBeNull()
			->and($lesson->title)->toBe('Wie macht ein Blatt Zucker aus Licht?')
			->and($lesson->plan['key_idea'])->toStartWith('Pflanzen bauen')
			->and($lesson->plan['sections'][0]['title'])->toBe('Das Rezept')
			->and($lesson->plan['note'])->toBeNull()
			->and($lesson->plan['removed_additions'])->toBe([])
			->and($lesson->graphic(1)->plan)->not->toBeNull()
			->and(array_map(fn ($request) => $request->step, $this->fake->requests))->toBe(['analysis']);
	});

	it('runs through without a review, as before', function () {
		$lesson = requestLesson($this->child);

		GenerationPipeline::start($lesson);

		$lesson->refresh();
		expect($lesson->status)->toBe(LessonStatus::Review)
			->and($lesson->plan_confirmed_at)->not->toBeNull()
			->and($lesson->content)->toBe(LessonFactory::fixture('fotosynthese'))
			->and($this->fake->requestsFor('page')[0]->prompt)->toContain('Kernidee: ');
	});

	it('takes the title from the plan', function () {
		$lesson = requestLesson($this->child);
		$this->fake->push('analysis', [...FakeLanguageModel::defaultResponse('analysis'), 'title' => 'Zucker aus Licht']);

		GenerationPipeline::start($lesson);

		expect($lesson->fresh()->title)->toBe('Zucker aus Licht')
			->and($lesson->fresh()->content['meta']['title'])->toBe('Zucker aus Licht');
	});

	it('cuts sections beyond the scope', function () {
		$lesson = requestLesson($this->child, ['scope' => 'short', 'review_plan' => true]);
		$this->fake->push('analysis', [...FakeLanguageModel::defaultResponse('analysis'), 'sections' => [
			['title' => 'A', 'goal' => 'a'], ['title' => 'B', 'goal' => ''], ['title' => 'C', 'goal' => 'c'],
		]]);

		GenerationPipeline::start($lesson);

		expect($lesson->fresh()->plan['sections'])->toBe([['title' => 'A', 'goal' => 'a'], ['title' => 'B', 'goal' => null]]);
	});

	it('fails without a section', function () {
		$lesson = requestLesson($this->child);
		$this->fake->push('analysis', [...FakeLanguageModel::defaultResponse('analysis'), 'sections' => []]);

		expect(fn () => app(PlanLessonAction::class)->handle($lesson))
			->toThrow(GenerationFailed::class, 'Die KI hat keinen gültigen Plan geliefert.');
	});

	it('skips the planning on a retry after a failure in the write step', function () {
		$lesson = requestLesson($this->child);
		$this->fake->push('page', new ModelException('Die KI war nicht erreichbar.'));
		GenerationPipeline::start($lesson);
		expect($lesson->fresh()->status)->toBe(LessonStatus::Failed);

		$this->fake->requests = [];
		GenerationPipeline::start($lesson->fresh());

		expect($lesson->fresh()->status)->toBe(LessonStatus::Review)
			->and($this->fake->requestsFor('analysis'))->toBe([])
			->and($this->fake->requestsFor('page'))->toHaveCount(1);
	});

	it('plans again on a retry after a failure in the planning', function () {
		$lesson = requestLesson($this->child);
		$this->fake->push('analysis', new ModelException('Die KI war nicht erreichbar.'));
		GenerationPipeline::start($lesson);
		expect($lesson->fresh()->status)->toBe(LessonStatus::Failed);

		GenerationPipeline::start($lesson->fresh());

		expect($lesson->fresh()->status)->toBe(LessonStatus::Review)
			->and($this->fake->requestsFor('analysis'))->toHaveCount(2);
	});

	it('queues the planning on its own', function () {
		Bus::fake();
		$lesson = requestLesson($this->child);

		GenerationPipeline::start($lesson);

		Bus::assertChained([PlanLesson::class]);
		expect($lesson->fresh()->step)->toBe('queued');
	});

	it('queues a graphic job only for planned graphics', function () {
		Bus::fake();
		$lesson = requestLesson($this->child, ['graphics_mode' => 'custom', 'plan_confirmed_at' => now()]);
		$lesson->graphics()->create(['position' => 1, 'request' => 'Regler', 'plan' => ['pattern' => 'sliders', 'idea' => 'Regler.']]);
		$lesson->graphics()->create(['position' => 2, 'request' => 'Vulkan', 'plan' => null, 'error' => 'Passt nicht.']);
		$lesson->graphics()->create(['position' => 3, 'request' => 'Schritte', 'plan' => ['pattern' => 'steps', 'idea' => 'Schritte.']]);

		GenerationPipeline::start($lesson);

		Bus::assertChained([
			WriteLesson::class,
			CheckLesson::class,
			fn (GenerateLessonGraphic $job) => $job->position === 1,
			fn (GenerateLessonGraphic $job) => $job->position === 3,
			FinishLesson::class,
		]);
		expect($lesson->fresh()->step)->toBe('page');
	});

	it('shows the planned graphics in the progress once there is a plan', function () {
		$lesson = requestLesson($this->child, ['graphics_mode' => 'custom', 'status' => LessonStatus::Generating, 'plan' => ['title' => 'A', 'key_idea' => 'B', 'sections' => [], 'note' => null, 'removed_additions' => []]]);
		$lesson->graphics()->create(['position' => 1, 'request' => 'Regler', 'plan' => ['pattern' => 'sliders', 'idea' => 'Regler.']]);
		$lesson->graphics()->create(['position' => 2, 'request' => 'Vulkan', 'plan' => null]);

		$this->actingAs($this->user)->get(route('lessons.show', $lesson))
			->assertInertia(fn (Assert $page) => $page->where('lesson.plannedGraphics', [1]));
	});
});
