<?php

use App\Models\Lesson;

// child_id set, so make() doesn't create a child in the database
function makeLesson(array $attributes = []): Lesson
{
	return Lesson::factory()->make(['child_id' => 1, ...$attributes]);
}

describe('isFromTopic', function () {
	it('is true for a lesson with a topic only', function () {
		expect(makeLesson(['topic' => 'Kommaregeln'])->isFromTopic())->toBeTrue();
	});

	it('is true for a prompt without photos', function () {
		expect(makeLesson(['prompt' => 'Erkläre die Kommaregeln.', 'photo_count' => 0])->isFromTopic())->toBeTrue();
	});

	it('is false for a prompt with photos', function () {
		expect(makeLesson(['prompt' => 'Prüfung am Freitag', 'photo_count' => 2])->isFromTopic())->toBeFalse();
	});

	it('treats a photo count from the database as a number', function () {
		$lesson = makeLesson(['prompt' => 'Erkläre die Kommaregeln.', 'photo_count' => '0']);

		expect($lesson->photo_count)->toBe(0)
			->and($lesson->isFromTopic())->toBeTrue();
	});

	it('is false without topic and prompt', function () {
		expect(makeLesson()->isFromTopic())->toBeFalse();
	});
});

describe('displayTitle', function () {
	it('prefers the title', function () {
		expect(makeLesson(['title' => 'Fotosynthese', 'topic' => 'Pflanzen', 'prompt' => 'Auftrag'])->displayTitle())
			->toBe('Fotosynthese');
	});

	it('falls back to the topic', function () {
		expect(makeLesson(['topic' => 'Pflanzen', 'prompt' => 'Auftrag'])->displayTitle())->toBe('Pflanzen');
	});

	it('falls back to the shortened prompt', function () {
		$title = makeLesson(['prompt' => str_repeat('a', 100)])->displayTitle();

		expect($title)->toEndWith('...')
			->and(mb_strlen($title))->toBeLessThanOrEqual(63);
	});

	it('falls back to a placeholder', function () {
		expect(makeLesson()->displayTitle())->toBe('Neue Lernseite');
	});
});

describe('generatedModules', function () {
	it('fills the exercises only in math and geometry when the parents chose them', function (?string $profile, array $chosen, array $generated) {
		$lesson = makeLesson(['profile' => $profile, 'modules' => $chosen]);

		expect($lesson->generatedModules())->toBe($generated);
	})->with([
		'math with exercises' => ['math', ['quiz', 'exercises'], ['quiz', 'exercises']],
		'math without' => ['math', ['quiz', 'cloze'], ['quiz', 'cloze']],
		'geometry only exercises' => ['geometry', ['exercises'], ['exercises']],
		'science ignores them' => ['science', ['quiz', 'exercises'], ['quiz']],
		'german brings find the mistake' => ['german', ['quiz', 'exercises'], ['quiz', 'find_the_mistake']],
		'nothing left falls back to the quiz' => ['science', ['exercises'], ['quiz']],
	]);
});
