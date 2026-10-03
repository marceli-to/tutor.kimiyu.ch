<?php

use App\Actions\Progress\RecordAnswer;
use App\Lessons\AnswerResult;
use App\Models\Child;
use App\Models\Lesson;

beforeEach(function () {
	$this->child = Child::factory()->create();
	$this->lesson = Lesson::factory()->for($this->child)->fromFixture()->create();
	$this->quiz = $this->lesson->content['modules']['quiz'][0];
});

it('stores a checked answer and returns the result', function () {
	$record = app(RecordAnswer::class);

	expect($record->handle($this->child, $this->lesson, 'quiz', $this->quiz['id'], $this->quiz['answer']))->toBe(AnswerResult::Correct)
		->and($record->handle($this->child, $this->lesson, 'quiz', $this->quiz['id'], $this->quiz['answer'] + 1))->toBe(AnswerResult::Wrong)
		->and($this->child->attempts()->pluck('correct')->all())->toBe([true, false]);
});

it('stores nothing for an item that does not exist', function () {
	expect(app(RecordAnswer::class)->handle($this->child, $this->lesson, 'quiz', 'nope', 0))->toBeNull()
		->and($this->child->attempts()->count())->toBe(0);
});

it('treats a non-scalar answer as no answer', function () {
	expect(app(RecordAnswer::class)->handle($this->child, $this->lesson, 'quiz', $this->quiz['id'], [$this->quiz['answer']]))->toBe(AnswerResult::Wrong);
});

it('stores an almost right cloze answer as not correct', function () {
	$lesson = Lesson::factory()->for($this->child)->fromFixture('passe-compose')->create();

	expect(app(RecordAnswer::class)->handle($this->child, $lesson, 'cloze', 'g5', 'ete'))->toBe(AnswerResult::Almost)
		->and($this->child->attempts()->sole()->correct)->toBeFalse();
});

it('checks and stores an exercise answer', function () {
	$lesson = Lesson::factory()->for($this->child)->fromFixture('dreisatz')->create();

	expect(app(RecordAnswer::class)->handle($this->child, $lesson, 'exercises', 'a2', '375 km'))->toBe(AnswerResult::Correct)
		->and(app(RecordAnswer::class)->handle($this->child, $lesson, 'exercises', 'a2', 375))->toBe(AnswerResult::Correct)
		->and(app(RecordAnswer::class)->handle($this->child, $lesson, 'exercises', 'a2', true))->toBe(AnswerResult::Wrong)
		->and($this->child->attempts()->pluck('correct')->all())->toBe([true, true, false]);
});

it('checks and stores a corrected mistake', function () {
	$lesson = Lesson::factory()->for($this->child)->fromFixture('das-dass')->create();
	$record = app(RecordAnswer::class);

	// f1: «Ich hoffe, das du morgen kommst.» – word 2 is «dass»
	expect($record->handle($this->child, $lesson, 'find_the_mistake', 'f1', ['word' => 2, 'correction' => 'dass']))->toBe(AnswerResult::Correct)
		->and($record->handle($this->child, $lesson, 'find_the_mistake', 'f1', ['word' => 1, 'correction' => 'dass']))->toBe(AnswerResult::Wrong)
		->and($record->handle($this->child, $lesson, 'find_the_mistake', 'f1', 'dass'))->toBe(AnswerResult::Wrong)
		->and($this->child->attempts()->pluck('correct')->all())->toBe([true, false, false]);
});

it('checks upper and lower case in a case sensitive cloze', function () {
	$lesson = Lesson::factory()->for($this->child)->fromFixture('das-dass')->create();

	// g2 is «Das» at the start of a sentence
	expect(app(RecordAnswer::class)->handle($this->child, $lesson, 'cloze', 'g2', 'Das'))->toBe(AnswerResult::Correct)
		->and(app(RecordAnswer::class)->handle($this->child, $lesson, 'cloze', 'g2', 'das'))->toBe(AnswerResult::Wrong);
});
