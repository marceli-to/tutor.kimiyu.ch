<?php

use App\Enums\LessonStatus;
use App\Lessons\Ai\FakeLanguageModel;
use App\Lessons\Ai\Prompts;
use App\Lessons\Ai\Schemas;
use App\Models\Child;
use App\Models\Lesson;
use App\Models\User;
use Carbon\CarbonInterface;
use Database\Factories\LessonFactory;
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
