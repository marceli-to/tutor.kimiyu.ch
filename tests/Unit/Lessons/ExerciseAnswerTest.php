<?php

use App\Lessons\AnswerResult;
use App\Lessons\ExerciseAnswer;

function exercise(array $changes = []): array
{
	return [
		'id' => 'a1',
		'question' => 'Wie viel?',
		'kind' => 'number',
		'answer' => '1250.5',
		'tolerance' => null,
		'unit' => null,
		'hint' => null,
		'solution_path' => 'So.',
		...$changes,
	];
}

it('reads numbers in swiss and german formats', function (string $input, ?float $expected) {
	expect(ExerciseAnswer::number($input))->toBe($expected);
})->with([
	['1250.5', 1250.5],
	['1250,5', 1250.5],
	["1'250,5", 1250.5],
	['1’250.5', 1250.5],
	['1 250', 1250.0],
	["1\u{00A0}250", 1250.0],
	['1.250.000', 1250000.0],
	['1.250,5', 1250.5],
	['1,250.5', 1250.5],
	['-3,5', -3.5],
	['−3,5', -3.5],
	['+7', 7.0],
	['0,75', 0.75],
	[',5', 0.5],
	['12.', 12.0],
	['12.–', 12.0],
	['', null],
	['zwölf', null],
	['3/4', null],
	['1,2,3,4', null],
	['12,5000', 12.5],
	['1,2.3,4', null],
]);

it('reads fractions, decimals and whole numbers as fractions', function (string $input, ?float $expected) {
	expect(ExerciseAnswer::fraction($input))->toEqualWithDelta($expected, 1e-12);
})->with([
	['3/4', 0.75],
	[' 6 / 8 ', 0.75],
	['0,75', 0.75],
	['-1/2', -0.5],
	['2', 2.0],
	['1 1/2', 1.5],
	['-2 3/4', -2.75],
]);

it('rejects what is no fraction', function (string $input) {
	expect(ExerciseAnswer::fraction($input))->toBeNull();
})->with(['3/0', '3/', '/4', 'drei Viertel', '3/4/5', '']);

it('checks numbers with tolerance', function (string $input, AnswerResult $result) {
	expect(ExerciseAnswer::check(exercise(['answer' => '66,7', 'tolerance' => 0.05]), $input))->toBe($result);
})->with([
	['66,7', AnswerResult::Correct],
	['66.7', AnswerResult::Correct],
	['66,73', AnswerResult::Correct],
	['66,75', AnswerResult::Correct],
	['66,8', AnswerResult::Wrong],
	['67', AnswerResult::Wrong],
]);

it('checks numbers exactly without tolerance', function () {
	expect(ExerciseAnswer::check(exercise(), "1'250,5"))->toBe(AnswerResult::Correct)
		->and(ExerciseAnswer::check(exercise(), '1250,6'))->toBe(AnswerResult::Wrong)
		->and(ExerciseAnswer::check(exercise(), 'viel'))->toBe(AnswerResult::Wrong)
		->and(ExerciseAnswer::check(exercise(), ''))->toBe(AnswerResult::Wrong);
});

it('accepts the unit before or after the number, or none at all', function (string $input, AnswerResult $result) {
	expect(ExerciseAnswer::check(exercise(['answer' => '10.50', 'unit' => 'Fr.']), $input))->toBe($result);
})->with([
	['10.50', AnswerResult::Correct],
	['10,5', AnswerResult::Correct],
	['10.50 Fr.', AnswerResult::Correct],
	['Fr. 10.50', AnswerResult::Correct],
	['10.50 fr.', AnswerResult::Correct],
	['10.50 Fr', AnswerResult::Correct],
	['10.50 CHF', AnswerResult::Wrong],
	['Fr. 10.50 Fr.', AnswerResult::Wrong],
	['10.50 kg', AnswerResult::Wrong],
	['11 Fr.', AnswerResult::Wrong],
]);

it('accepts units with letters only after the number', function () {
	$entry = exercise(['answer' => '375', 'unit' => 'km']);

	expect(ExerciseAnswer::check($entry, '375 km'))->toBe(AnswerResult::Correct)
		->and(ExerciseAnswer::check($entry, '375km'))->toBe(AnswerResult::Correct)
		->and(ExerciseAnswer::check($entry, ' 375 KM '))->toBe(AnswerResult::Correct)
		->and(ExerciseAnswer::check($entry, '375 m'))->toBe(AnswerResult::Wrong);
});

it('accepts area and volume units written with a plain digit', function () {
	$area = exercise(['answer' => '24', 'unit' => 'cm²']);
	$volume = exercise(['answer' => '8', 'unit' => 'm³']);

	expect(ExerciseAnswer::check($area, '24 cm²'))->toBe(AnswerResult::Correct)
		->and(ExerciseAnswer::check($area, '24 cm2'))->toBe(AnswerResult::Correct)
		->and(ExerciseAnswer::check($area, '24cm2'))->toBe(AnswerResult::Correct)
		->and(ExerciseAnswer::check($area, '24 cm3'))->toBe(AnswerResult::Wrong)
		->and(ExerciseAnswer::check($area, '24 cm'))->toBe(AnswerResult::Wrong)
		->and(ExerciseAnswer::check($volume, '8 m3'))->toBe(AnswerResult::Correct)
		->and(ExerciseAnswer::check(exercise(['answer' => '242']), '24 2'))->toBe(AnswerResult::Correct);
});

it('rejects a unit where the task has none', function () {
	expect(ExerciseAnswer::check(exercise(['answer' => '12']), '12 kg'))->toBe(AnswerResult::Wrong)
		->and(ExerciseAnswer::check(exercise(['answer' => '12']), '12'))->toBe(AnswerResult::Correct);
});

it('treats equal fractions as the same answer', function (string $input, AnswerResult $result) {
	expect(ExerciseAnswer::check(exercise(['kind' => 'fraction', 'answer' => '3/4']), $input))->toBe($result);
})->with([
	['3/4', AnswerResult::Correct],
	['6/8', AnswerResult::Correct],
	['0,75', AnswerResult::Correct],
	['0.75', AnswerResult::Correct],
	['0 3/4', AnswerResult::Correct],
	['4/3', AnswerResult::Wrong],
	['0,7', AnswerResult::Wrong],
	['drei Viertel', AnswerResult::Wrong],
]);

it('compares text answers like a gap', function () {
	$entry = exercise(['kind' => 'text', 'answer' => 'proportional']);

	expect(ExerciseAnswer::check($entry, '  Proportional '))->toBe(AnswerResult::Correct)
		->and(ExerciseAnswer::check($entry, 'umgekehrt proportional'))->toBe(AnswerResult::Wrong);
});

it('knows whether a solution can be checked at all', function (string $kind, string $answer, bool $valid) {
	expect(ExerciseAnswer::isCheckable($kind, $answer))->toBe($valid);
})->with([
	['number', "1'250,5", true],
	['number', 'zwölf', false],
	['fraction', '3/8', true],
	['fraction', '3/0', false],
	['text', 'proportional', true],
	['text', '  ', false],
	['percent', '5', false],
]);
