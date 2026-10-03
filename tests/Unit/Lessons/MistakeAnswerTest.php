<?php

use App\Lessons\AnswerResult;
use App\Lessons\MistakeAnswer;

it('splits a sentence into words with their punctuation', function () {
	expect(MistakeAnswer::words('  Ich hoffe,  das du «kommst».'))->toBe(['Ich', 'hoffe,', 'das', 'du', '«kommst».']);
});

it('checks the tapped word and its correction', function (array $entry, mixed $answer, AnswerResult $result) {
	expect(MistakeAnswer::check($entry, $answer))->toBe($result);
})->with([
	'right' => [['sentence' => 'Ich hoffe, das du kommst.', 'mistake_word' => 2, 'correction' => 'dass'], ['word' => 2, 'correction' => 'dass'], AnswerResult::Correct],
	'spaces' => [['sentence' => 'Ich hoffe, das du kommst.', 'mistake_word' => 2, 'correction' => 'dass'], ['word' => 2, 'correction' => ' dass '], AnswerResult::Correct],
	'wrong word' => [['sentence' => 'Ich hoffe, das du kommst.', 'mistake_word' => 2, 'correction' => 'dass'], ['word' => 1, 'correction' => 'dass'], AnswerResult::Wrong],
	'wrong correction' => [['sentence' => 'Ich hoffe, das du kommst.', 'mistake_word' => 2, 'correction' => 'dass'], ['word' => 2, 'correction' => 'das'], AnswerResult::Wrong],
	'case counts' => [['sentence' => 'Dass Haus ist alt.', 'mistake_word' => 0, 'correction' => 'Das'], ['word' => 0, 'correction' => 'das'], AnswerResult::Wrong],
	'case right' => [['sentence' => 'Dass Haus ist alt.', 'mistake_word' => 0, 'correction' => 'Das'], ['word' => 0, 'correction' => 'Das'], AnswerResult::Correct],
	'punctuation of the sentence left out' => [['sentence' => 'Das Buch, dass ich lese.', 'mistake_word' => 1, 'correction' => 'Heft,'], ['word' => 1, 'correction' => 'Heft'], AnswerResult::Correct],
	'punctuation of the sentence kept' => [['sentence' => 'Das Buch, dass ich lese.', 'mistake_word' => 1, 'correction' => 'Heft,'], ['word' => 1, 'correction' => 'Heft,'], AnswerResult::Correct],
	'quotes around the word' => [['sentence' => 'Er sagt «dass».', 'mistake_word' => 2, 'correction' => '«das».'], ['word' => 2, 'correction' => 'das'], AnswerResult::Correct],
	'missing comma counts' => [['sentence' => 'Ich glaube dass wir gewinnen.', 'mistake_word' => 1, 'correction' => 'glaube,'], ['word' => 1, 'correction' => 'glaube'], AnswerResult::Wrong],
	'comma added' => [['sentence' => 'Ich glaube dass wir gewinnen.', 'mistake_word' => 1, 'correction' => 'glaube,'], ['word' => 1, 'correction' => 'glaube,'], AnswerResult::Correct],
	'empty correction' => [['sentence' => 'Er kommt, ja.', 'mistake_word' => 1, 'correction' => ','], ['word' => 1, 'correction' => ' '], AnswerResult::Wrong],
	'word as string' => [['sentence' => 'Ich hoffe, das du kommst.', 'mistake_word' => 2, 'correction' => 'dass'], ['word' => '2', 'correction' => 'dass'], AnswerResult::Wrong],
	'no correction' => [['sentence' => 'Ich hoffe, das du kommst.', 'mistake_word' => 2, 'correction' => 'dass'], ['word' => 2], AnswerResult::Wrong],
]);
