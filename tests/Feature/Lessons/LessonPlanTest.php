<?php

use App\Enums\LessonStatus;
use App\Models\Child;
use App\Models\Lesson;
use App\Models\User;
use Carbon\CarbonInterface;
use Inertia\Testing\AssertableInertia as Assert;

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
