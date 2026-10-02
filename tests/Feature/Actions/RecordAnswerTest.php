<?php

use App\Actions\Progress\RecordAnswer;
use App\Models\Child;
use App\Models\Lesson;

beforeEach(function () {
	$this->child = Child::factory()->create();
	$this->lesson = Lesson::factory()->for($this->child)->fromFixture()->create();
	$this->quiz = $this->lesson->content['modules']['quiz'][0];
});

it('stores a checked answer and returns whether it was correct', function () {
	$record = app(RecordAnswer::class);

	expect($record->handle($this->child, $this->lesson, 'quiz', $this->quiz['id'], $this->quiz['answer']))->toBeTrue()
		->and($record->handle($this->child, $this->lesson, 'quiz', $this->quiz['id'], $this->quiz['answer'] + 1))->toBeFalse()
		->and($this->child->attempts()->pluck('correct')->all())->toBe([true, false]);
});

it('stores nothing for an item that does not exist', function () {
	expect(app(RecordAnswer::class)->handle($this->child, $this->lesson, 'quiz', 'nope', 0))->toBeNull()
		->and($this->child->attempts()->count())->toBe(0);
});

it('treats a non-scalar answer as no answer', function () {
	expect(app(RecordAnswer::class)->handle($this->child, $this->lesson, 'quiz', $this->quiz['id'], [$this->quiz['answer']]))->toBeFalse();
});
