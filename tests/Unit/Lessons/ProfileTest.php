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

it('allows the base blocks and modules where the profile needs no room for its own', function (Profile $profile) {
	expect($profile->blocks())->toBe(['paragraph', 'formula', 'facts', 'columns', 'box', 'graphic'])
		->and($profile->modules())->toBe(['quiz', 'sorting', 'flashcards', 'cloze']);
})->with([Profile::Science, Profile::General, Profile::Geometry, Profile::German]);

it('gives languages word lists and verb tables instead of formulas', function () {
	expect(Profile::Languages->blocks())->toBe(['paragraph', 'facts', 'columns', 'box', 'graphic', 'vocabulary', 'conjugation'])
		->and(Profile::Languages->modules())->toBe(['quiz', 'sorting', 'flashcards', 'cloze']);
});

it('gives math worked solutions and exercises instead of columns and sorting', function () {
	expect(Profile::Math->blocks())->toBe(['paragraph', 'formula', 'facts', 'box', 'graphic', 'worked_solution'])
		->and(Profile::Math->modules())->toBe(['quiz', 'flashcards', 'cloze', 'exercises']);
});

it('renders formulas only where they belong', function () {
	expect(array_values(array_filter(Profile::cases(), fn (Profile $profile) => $profile->rendersMath())))
		->toBe([Profile::Science, Profile::Math, Profile::Geometry]);
});

it('uses its own fixture as the example for languages and math', function () {
	expect(Profile::Languages->fixture())->toBe('passe-compose')
		->and(Profile::Math->fixture())->toBe('dreisatz')
		->and(LessonFactory::PROFILE_FIXTURES)->toBe(['passe-compose', 'dreisatz']);
});

it('knows the speech language of a foreign language lesson', function (Profile $profile, ?string $subject, ?string $lang) {
	expect($profile->speechLang($subject))->toBe($lang);
})->with([
	[Profile::Languages, 'Französisch', 'fr-FR'],
	[Profile::Languages, ' englisch ', 'en-GB'],
	[Profile::Languages, 'Italienisch', 'it-IT'],
	[Profile::Languages, 'Latein', null],
	[Profile::Languages, null, null],
	[Profile::German, 'Deutsch', null],
	[Profile::General, 'Französisch', null],
]);

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
