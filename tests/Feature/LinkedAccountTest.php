<?php

use App\Enums\LessonStatus;
use App\Lessons\Ai\FakeLanguageModel;
use App\Models\Child;
use App\Models\Generation;
use App\Models\Lesson;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
	FakeLanguageModel::install();
	$this->owner = User::factory()->create();
	$this->friend = User::factory()->create(['owner_id' => $this->owner->id]);
	$this->child = Child::factory()->for($this->owner)->create(['name' => 'Mia']);
	$this->lesson = Lesson::factory()->for($this->child)->fromFixture('oekosystem')->create(['status' => LessonStatus::Review]);
});

it('shows the owner’s children and lessons to a linked account', function () {
	Child::factory()->create(['name' => 'Fremd']);

	$this->actingAs($this->friend)->get(route('children.index'))
		->assertInertia(fn (Assert $page) => $page->has('children', 1)->where('children.0.name', 'Mia'));

	$this->actingAs($this->friend)->get(route('lessons.show', $this->lesson))->assertOk();
	$this->actingAs($this->friend)->get(route('children.progress', $this->child))->assertOk();
});

it('lets a linked account work on the owner’s data', function () {
	$this->actingAs($this->friend)->post(route('children.store'), ['name' => 'Noah', 'level' => '1. Sek'])
		->assertSessionHasNoErrors();

	expect($this->owner->children()->where('name', 'Noah')->exists())->toBeTrue();

	$this->actingAs($this->friend)->patch(route('children.update', $this->child), ['name' => 'Mia Sophie', 'level' => '3. Sek'])
		->assertSessionHasNoErrors();

	expect($this->child->fresh()->name)->toBe('Mia Sophie');
});

it('shows the owner’s costs to a linked account', function () {
	Generation::query()->create(['user_id' => $this->owner->id, 'step' => 'page', 'model' => 'x', 'status' => 'ok', 'cost_usd' => 0.5]);

	$this->actingAs($this->friend)->get(route('costs'))
		->assertInertia(fn (Assert $page) => $page->where('months.0.calls', 1));
});

it('keeps an unlinked account away from other data', function () {
	$stranger = User::factory()->create();

	$this->actingAs($stranger)->get(route('lessons.show', $this->lesson))->assertForbidden();
	$this->actingAs($stranger)->get(route('children.progress', $this->child))->assertForbidden();
});
