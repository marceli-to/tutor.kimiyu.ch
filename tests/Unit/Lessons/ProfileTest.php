<?php

use App\Lessons\Profile;
use App\Models\Lesson;
use Database\Factories\LessonFactory;

it('derives the profile from the subject', function (?string $subject, Profile $profile) {
	expect(Profile::forSubject($subject))->toBe($profile);
})->with([
	['Biologie', Profile::Science],
	[' natur und technik ', Profile::Science],
	['CHEMIE', Profile::Science],
	['Mathematik', Profile::Math],
	['Geometrie', Profile::Geometry],
	['Deutsch', Profile::German],
	['Französisch', Profile::Languages],
	['Englisch', Profile::Languages],
	['Geschichte', Profile::General],
	['Allgemein', Profile::General],
	['', Profile::General],
	[null, Profile::General],
]);

it('has a german label, a prompt file and an example for every profile', function (Profile $profile) {
	expect($profile->label())->not->toBe('')
		->and(file_exists($profile->promptFile()))->toBeTrue()
		->and(LessonFactory::fixture($profile->fixture()))->toHaveKey('sections');
})->with(Profile::cases());

it('allows the base blocks and modules for every profile', function (Profile $profile) {
	expect($profile->blocks())->toBe(['paragraph', 'formula', 'facts', 'columns', 'box', 'graphic'])
		->and($profile->modules())->toBe(['quiz', 'sorting', 'flashcards', 'cloze']);
})->with(Profile::cases());

it('allows experiments only for science', function () {
	expect(array_values(array_filter(Profile::cases(), fn (Profile $profile) => $profile->allowsExperiments())))
		->toBe([Profile::Science]);
});

it('resolves the profile of a lesson: the parents’ choice first, else from the subject', function () {
	$lesson = Lesson::factory()->make(['child_id' => 1, 'subject' => 'Biologie']);

	expect($lesson->resolvedProfile())->toBe(Profile::Science);

	$lesson->profile = Profile::General;

	expect($lesson->resolvedProfile())->toBe(Profile::General);
});
