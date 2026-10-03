<?php

use App\Lessons\ContentValidator;
use App\Lessons\Profile;
use Database\Factories\LessonFactory;

function lessonFixture(string $name = 'fotosynthese'): array
{
	return LessonFactory::fixture($name);
}

it('accepts both reference fixtures in strict mode', function (string $name) {
	expect(ContentValidator::errors(lessonFixture($name), strict: true))->toBe([]);
})->with(LessonFactory::FIXTURES);

it('requires the main parts of a page', function (string $key) {
	$content = lessonFixture();
	unset($content[$key]);

	expect(ContentValidator::make($content)->errors()->has($key))->toBeTrue();
})->with(['meta', 'sections', 'modules', 'reflect']);

it('rejects an unknown palette', function () {
	$content = lessonFixture();
	$content['meta']['palette'] = 'neonpink';

	expect(ContentValidator::make($content)->errors()->has('meta.palette'))->toBeTrue();
});

it('rejects a quiz answer that points past the options', function () {
	$content = lessonFixture();
	$content['modules']['quiz'][0]['options'] = ['A', 'B', 'C'];
	$content['modules']['quiz'][0]['answer'] = 3;

	expect(ContentValidator::errors($content))->toContain('Quizfrage q1: Die Lösung zeigt auf eine Option, die es nicht gibt.');
});

it('rejects duplicate answer options', function () {
	$content = lessonFixture();
	$content['modules']['quiz'][1]['options'][3] = 'im zellkern ';

	expect(ContentValidator::errors($content))->toContain('Quizfrage q2: Zwei Antwortoptionen sind gleich.');
});

it('rejects too few or too many options', function (int $count) {
	$content = lessonFixture();
	$content['modules']['quiz'][0]['options'] = array_fill(0, $count, 'x');

	expect(ContentValidator::make($content)->errors()->has('modules.quiz.0.options'))->toBeTrue();
})->with([2, 5]);

it('rejects sorting terms in a category that does not exist', function () {
	$content = lessonFixture('oekosystem');
	$content['modules']['sorting']['terms'][0]['category'] = 'cat3';

	expect(ContentValidator::errors($content))->toContain('Sortierspiel: «Sonnenlicht» gehört zu einer Kategorie, die es nicht gibt.');
});

it('rejects a sorting category without terms', function () {
	$content = lessonFixture('oekosystem');
	$content['modules']['sorting']['categories'][] = ['id' => 'cat3', 'label' => 'Pilze', 'sub' => null];

	expect(ContentValidator::errors($content))->toContain('Sortierspiel: Die Kategorie cat3 hat keine Begriffe.');
});

it('rejects duplicate item ids across modules', function () {
	$content = lessonFixture();
	$content['modules']['flashcards']['entries'][0]['id'] = 'q1';

	expect(ContentValidator::errors($content))->toContain('Diese IDs kommen mehrfach vor: q1.');
});

it('rejects malformed cloze segments', function () {
	$content = lessonFixture();
	$content['modules']['cloze']['segments'][1] = ['id' => 'g1', 'answers' => []];

	expect(ContentValidator::make($content)->errors()->has('modules.cloze.segments.1'))->toBeTrue();
});

it('rejects a cloze text without gaps', function () {
	$content = lessonFixture();
	$content['modules']['cloze']['segments'] = [['text' => 'Nur Text.']];

	expect(ContentValidator::errors($content))->toContain('Lückentext: Es gibt keine Lücke.');
});

it('rejects incomplete blocks', function () {
	$content = lessonFixture('oekosystem');
	unset($content['sections'][0]['blocks'][0]['entries'][1]['category']);

	expect(ContentValidator::make($content)->errors()->has('sections.0.blocks.0'))->toBeTrue();
});

it('rejects the German sharp s anywhere', function () {
	$content = lessonFixture();
	$content['sections'][1]['blocks'][0]['entries'][2]['text'] = 'Das ist groß.';

	expect(ContentValidator::errors($content))
		->toContain('Im Feld sections.1.blocks.0.entries.2.text steht ein «ß». In der Schweiz schreibt man «ss».');
});

it('accepts content without any origin markers', function () {
	// Old pages have no «origin» field
	$strip = function (array $value) use (&$strip): array {
		unset($value['origin']);

		return array_map(fn ($item) => is_array($item) ? $strip($item) : $item, $value);
	};

	$content = $strip(lessonFixture());

	expect(json_encode($content))->not->toContain('origin')
		->and(ContentValidator::errors($content, strict: true))->toBe([]);
});

it('rejects an unknown origin', function (string $key, Closure $set) {
	$content = $set(lessonFixture());

	expect(ContentValidator::make($content)->errors()->has($key))->toBeTrue();
})->with([
	'block' => ['sections.0.blocks.0.origin', function (array $c) {
		$c['sections'][0]['blocks'][0]['origin'] = 'buch';

		return $c;
	}],
	'quiz question' => ['modules.quiz.0.origin', function (array $c) {
		$c['modules']['quiz'][0]['origin'] = 'buch';

		return $c;
	}],
	'flashcard' => ['modules.flashcards.entries.0.origin', function (array $c) {
		$c['modules']['flashcards']['entries'][0]['origin'] = 'buch';

		return $c;
	}],
	'cloze' => ['modules.cloze.origin', function (array $c) {
		$c['modules']['cloze']['origin'] = 'buch';

		return $c;
	}],
]);

it('rejects an unknown origin on a sorting term', function () {
	$content = lessonFixture('oekosystem');
	$content['modules']['sorting']['terms'][0]['origin'] = 'buch';

	expect(ContentValidator::make($content)->errors()->has('modules.sorting.terms.0.origin'))->toBeTrue();
});

describe('strict mode', function () {
	it('requires between three and eight quiz questions', function (int $count, bool $valid) {
		$content = lessonFixture();
		$question = $content['modules']['quiz'][0];
		$content['modules']['quiz'] = array_map(
			fn (int $i) => [...$question, 'id' => 'q'.($i + 1), 'answer' => $i % 3],
			range(0, $count - 1),
		);

		expect(ContentValidator::make($content)->passes())->toBeTrue()
			->and(ContentValidator::make($content, strict: true)->passes())->toBe($valid);
	})->with([
		'two' => [2, false],
		'three' => [3, true],
		'eight' => [8, true],
		'nine' => [9, false],
	]);

	it('rejects answers that are always in the same position', function () {
		$content = lessonFixture();
		foreach ($content['modules']['quiz'] as &$question) {
			$question['answer'] = 1;
		}

		expect(ContentValidator::errors($content))->toBe([])
			->and(ContentValidator::errors($content, strict: true))
			->toContain('Quiz: Die richtige Antwort steht immer an derselben Position.');
	});

	it('accepts a quiz as the only module', function () {
		$content = lessonFixture();
		$content['modules']['sorting'] = null;
		$content['modules']['flashcards'] = null;
		$content['modules']['cloze'] = null;

		expect(ContentValidator::errors($content, strict: true))->toBe([]);
	});
});

describe('optional quiz', function () {
	it('accepts a page without quiz but with flashcards', function (bool $strict) {
		$content = lessonFixture();
		$content['modules']['quiz'] = null;

		expect($content['modules']['flashcards'])->not->toBeNull()
			->and(ContentValidator::errors($content, strict: $strict))->toBe([]);
	})->with(['non-strict' => false, 'strict' => true]);

	it('still needs the quiz key', function () {
		$content = lessonFixture();
		unset($content['modules']['quiz']);

		expect(ContentValidator::make($content)->errors()->has('modules.quiz'))->toBeTrue();
	});

	it('rejects an empty quiz list', function () {
		$content = lessonFixture();
		$content['modules']['quiz'] = [];

		expect(ContentValidator::make($content)->errors()->has('modules.quiz'))->toBeTrue();
	});

	it('rejects a page without any module', function (bool $strict) {
		$content = lessonFixture();
		$content['modules'] = ['quiz' => null, 'sorting' => null, 'flashcards' => null, 'cloze' => null];

		expect(ContentValidator::errors($content, strict: $strict))->toContain('Die Seite braucht mindestens ein Lernmodul.')
			->and(ContentValidator::errorsByPart($content, strict: $strict)['modules'])->toContain('Die Seite braucht mindestens ein Lernmodul.');
	})->with(['non-strict' => false, 'strict' => true]);
});

describe('graphic blocks', function () {
	function withGraphicBlock(array $block, int $section = 1): array
	{
		$content = lessonFixture();
		$content['sections'][$section]['blocks'][] = $block;

		return $content;
	}

	it('accepts a block for graphic 2 or 3', function (int $number) {
		expect(ContentValidator::errors(withGraphicBlock(['type' => 'graphic', 'number' => $number, 'origin' => 'photo']), strict: true))->toBe([]);
	})->with([2, 3]);

	it('rejects a block without a valid number', function (array $block) {
		expect(ContentValidator::errors(withGraphicBlock($block)))->not->toBe([]);
	})->with([
		'graphic 1 is at the top' => [['type' => 'graphic', 'number' => 1]],
		'there are only 3' => [['type' => 'graphic', 'number' => 4]],
		'missing' => [['type' => 'graphic']],
		'text' => [['type' => 'graphic', 'number' => 'zwei']],
	]);

	it('needs explaining text next to a graphic in strict mode', function () {
		$content = lessonFixture();
		$content['sections'][] = ['title' => 'Nur Grafik', 'blocks' => [['type' => 'graphic', 'number' => 2]]];

		expect(ContentValidator::errors($content))->toBe([])
			->and(ContentValidator::errors($content, strict: true))
			->toContain('Abschnitt «Nur Grafik»: Eine Grafik braucht erklärenden Text daneben.');
	});
});

describe('profiles', function () {
	it('rejects experiments on a fresh page of a profile without experiments', function () {
		$content = lessonFixture();

		expect(ContentValidator::errors($content, strict: true, profile: Profile::General))
			->toContain('Das Fachprofil «Allgemein» hat keine Experimente («Ausprobieren»).')
			->and(ContentValidator::errorsByPart($content, strict: true, profile: Profile::General)['page'])->not->toBe([])
			->and(ContentValidator::errors($content, profile: Profile::General))->toBe([])
			->and(ContentValidator::errors($content, strict: true, profile: Profile::Science))->toBe([]);
	});

	it('accepts the fixtures with their profile', function (string $name) {
		expect(ContentValidator::errors(lessonFixture($name), strict: true, profile: Profile::Science))->toBe([]);
	})->with(LessonFactory::FIXTURES);

	it('accepts the languages fixture strictly with its profile', function () {
		expect(ContentValidator::errors(lessonFixture('passe-compose'), strict: true, profile: Profile::Languages))->toBe([]);
	});

	it('rejects vocabulary and conjugation on a fresh page of another profile', function () {
		$content = lessonFixture('passe-compose');

		expect(ContentValidator::errors($content, strict: true, profile: Profile::General))
			->toContain('Das Fachprofil «Allgemein» hat keine Bausteine vom Typ «vocabulary».')
			->toContain('Das Fachprofil «Allgemein» hat keine Bausteine vom Typ «conjugation».')
			->and(ContentValidator::errors($content))->toBe([]);
	});

	it('checks the vocabulary block', function (Closure $change) {
		$content = lessonFixture('passe-compose');
		$content['sections'][1]['blocks'][1] = $change($content['sections'][1]['blocks'][1]);

		expect(ContentValidator::make($content)->errors()->has('sections.1.blocks.1'))->toBeTrue();
	})->with([
		'too few entries' => fn (array $block) => [...$block, 'entries' => array_slice($block['entries'], 0, 3)],
		'too many entries' => fn (array $block) => [...$block, 'entries' => array_fill(0, 31, $block['entries'][0])],
		'empty german' => fn (array $block) => [...$block, 'entries' => [['foreign' => 'vu', 'german' => ' ', 'info' => null], ...array_slice($block['entries'], 1)]],
		'info not text' => fn (array $block) => [...$block, 'entries' => [['foreign' => 'vu', 'german' => 'gesehen', 'info' => 3], ...array_slice($block['entries'], 1)]],
		'title not text' => fn (array $block) => [...$block, 'title' => ['x']],
	]);

	it('accepts vocabulary without title and info', function () {
		$content = lessonFixture('passe-compose');
		$content['sections'][1]['blocks'][1]['title'] = null;
		unset($content['sections'][1]['blocks'][1]['entries'][0]['info']);

		expect(ContentValidator::errors($content))->toBe([]);
	});

	it('checks the conjugation block', function (Closure $change) {
		$content = lessonFixture('passe-compose');
		$content['sections'][0]['blocks'][1] = $change($content['sections'][0]['blocks'][1]);

		expect(ContentValidator::make($content)->errors()->has('sections.0.blocks.1'))->toBeTrue();
	})->with([
		'five forms' => fn (array $block) => [...$block, 'forms' => array_slice($block['forms'], 0, 5)],
		'seven forms' => fn (array $block) => [...$block, 'forms' => [...$block['forms'], $block['forms'][0]]],
		'empty form' => fn (array $block) => [...$block, 'forms' => [['person' => 'je', 'form' => ''], ...array_slice($block['forms'], 1)]],
		'no verb' => fn (array $block) => array_diff_key($block, ['verb' => true]),
		'no tense' => fn (array $block) => [...$block, 'tense' => ''],
	]);
});

describe('math', function () {
	it('accepts the math fixture strictly with its profile', function () {
		expect(ContentValidator::errors(lessonFixture('dreisatz'), strict: true, profile: Profile::Math))->toBe([]);
	});

	it('rejects worked solutions and exercises on a fresh page of another profile', function () {
		$content = lessonFixture('dreisatz');

		expect(ContentValidator::errors($content, strict: true, profile: Profile::General))
			->toContain('Das Fachprofil «Allgemein» hat keine Bausteine vom Typ «worked_solution».')
			->toContain('Das Fachprofil «Allgemein» hat kein Lernmodul «exercises».')
			->and(ContentValidator::errors($content))->toBe([]);
	});

	it('accepts old pages without exercises', function () {
		$content = lessonFixture();
		unset($content['modules']['exercises']);

		expect(ContentValidator::errors($content, strict: true))->toBe([]);
	});

	it('accepts 20 exercises', function () {
		$content = lessonFixture('dreisatz');
		$content['modules']['exercises']['entries'] = array_map(fn (int $i) => [...$content['modules']['exercises']['entries'][0], 'id' => "a$i"], range(1, 20));

		expect(ContentValidator::errors($content, strict: true, profile: Profile::Math))->toBe([]);
	});

	it('counts exercises as a learning module', function () {
		$content = lessonFixture('dreisatz');
		$content['modules'] = [...$content['modules'], 'quiz' => null, 'cloze' => null];

		expect(ContentValidator::errors($content))->toBe([]);
	});

	it('checks the worked solution block', function (Closure $change) {
		$content = lessonFixture('dreisatz');
		$content['sections'][1]['blocks'][0] = $change($content['sections'][1]['blocks'][0]);

		expect(ContentValidator::make($content)->errors()->has('sections.1.blocks.0'))->toBeTrue();
	})->with([
		'one step' => fn (array $block) => [...$block, 'steps' => array_slice($block['steps'], 0, 1)],
		'nine steps' => fn (array $block) => [...$block, 'steps' => array_fill(0, 9, $block['steps'][0])],
		'empty step' => fn (array $block) => [...$block, 'steps' => [['text' => ' ', 'reason' => null], ...array_slice($block['steps'], 1)]],
		'reason not text' => fn (array $block) => [...$block, 'steps' => [['text' => 'So', 'reason' => 1], ...array_slice($block['steps'], 1)]],
		'no task' => fn (array $block) => array_diff_key($block, ['task' => true]),
		'empty result' => fn (array $block) => [...$block, 'result' => ''],
	]);

	it('checks the exercises', function (Closure $change, string $key) {
		$content = lessonFixture('dreisatz');
		$content['modules']['exercises'] = $change($content['modules']['exercises']);

		expect(ContentValidator::make($content)->errors()->keys())->toContain($key);
	})->with([
		'too few' => [fn (array $module) => [...$module, 'entries' => array_slice($module['entries'], 0, 2)], 'modules.exercises.entries'],
		'more than 20' => [fn (array $module) => [...$module, 'entries' => array_map(fn (int $i) => [...$module['entries'][0], 'id' => "a$i"], range(1, 21))], 'modules.exercises.entries'],
		'unknown kind' => [fn (array $module) => array_replace_recursive($module, ['entries' => [0 => ['kind' => 'percent']]]), 'modules.exercises.entries.0.kind'],
		'answer no number' => [fn (array $module) => array_replace_recursive($module, ['entries' => [0 => ['answer' => 'zehn']]]), 'modules.exercises.entries.0.answer'],
		'answer no fraction' => [fn (array $module) => array_replace_recursive($module, ['entries' => [4 => ['answer' => '3/0']]]), 'modules.exercises.entries.4.answer'],
		'negative tolerance' => [fn (array $module) => array_replace_recursive($module, ['entries' => [0 => ['tolerance' => -1]]]), 'modules.exercises.entries.0.tolerance'],
		'no solution path' => [fn (array $module) => array_replace_recursive($module, ['entries' => [0 => ['solution_path' => '']]]), 'modules.exercises.entries.0.solution_path'],
		'duplicate id' => [fn (array $module) => array_replace_recursive($module, ['entries' => [1 => ['id' => 'q1']]]), 'modules'],
	]);
});

describe('tex', function () {
	function withText(string $text): array
	{
		$content = lessonFixture('dreisatz');
		$content['sections'][0]['blocks'][0]['text'] = $text;

		return $content;
	}

	it('rejects broken tex on a fresh page of a math profile', function (string $text, string $message) {
		expect(ContentValidator::errors(withText($text), strict: true, profile: Profile::Math))->toContain($message)
			->and(ContentValidator::errors(withText($text)))->toBe([])
			->and(ContentValidator::errors(withText($text), strict: true, profile: Profile::General))->not->toContain($message);
	})->with([
		'odd dollar' => ['Es kostet $3 : 4 = 0{,}75.', 'Im Feld sections.0.blocks.0.text ist ein «$» nicht geschlossen.'],
		'open brace' => ['Also $\frac{3}{4$.', 'Im Feld sections.0.blocks.0.text sind die geschweiften Klammern in einer Formel nicht ausgeglichen.'],
		'closing brace first' => ['Also $}3{$.', 'Im Feld sections.0.blocks.0.text sind die geschweiften Klammern in einer Formel nicht ausgeglichen.'],
		'paren delimiter' => ['Also \(x = 2\).', 'Im Feld sections.0.blocks.0.text steht eine Formel mit \( oder \[. Formeln gehören zwischen $…$ oder $$…$$.'],
		'bracket delimiter' => ['Also \[x = 2\]', 'Im Feld sections.0.blocks.0.text steht eine Formel mit \( oder \[. Formeln gehören zwischen $…$ oder $$…$$.'],
	]);

	it('accepts valid tex', function (string $text) {
		expect(ContentValidator::errors(withText($text), strict: true, profile: Profile::Math))->toBe([]);
	})->with([
		'inline' => ['Also $\frac{3}{4}$ und $x^{2}$.'],
		'display' => ['Rechne: $$\dfrac{7.50}{3} = 2.50$$'],
		'escaped dollar' => ['Ein \$ ist kein Franken.'],
		'escaped brace' => ['Die Menge $\{1, 2\}$.'],
		'no tex' => ['Einfach Text.'],
	]);

	it('checks tex in the modules too', function () {
		$content = lessonFixture('dreisatz');
		$content['modules']['exercises']['entries'][0]['solution_path'] = '$6 : 4';

		expect(ContentValidator::errorsByPart($content, strict: true, profile: Profile::Math)['modules'])
			->toContain('Im Feld modules.exercises.entries.0.solution_path ist ein «$» nicht geschlossen.');
	});
});

describe('geometry', function () {
	/**
	 * The fixture's first figure block, changed by $change, and the errors of the validator.
	 */
	function figureErrors(Closure $change): array
	{
		$content = lessonFixture('winkel-parallelen');
		$content['sections'][0]['blocks'][1] = $change($content['sections'][0]['blocks'][1]);

		return ContentValidator::errors($content);
	}

	it('accepts the geometry fixture strictly with its profile', function () {
		expect(lessonFixture('winkel-parallelen')['sections'][0]['blocks'][1]['type'])->toBe('figure')
			->and(ContentValidator::errors(lessonFixture('winkel-parallelen'), strict: true, profile: Profile::Geometry))->toBe([]);
	});

	it('rejects figures on a fresh page of another profile but shows them on old pages', function () {
		$content = lessonFixture('winkel-parallelen');

		expect(ContentValidator::errors($content, strict: true, profile: Profile::Math))
			->toContain('Das Fachprofil «Mathematik» hat keine Bausteine vom Typ «figure».')
			->and(ContentValidator::errors($content))->toBe([]);
	});

	it('names the broken part of a figure', function (Closure $change, string $error) {
		expect(figureErrors($change))->toContain($error);
	})->with([
		'unknown point in a line' => [
			fn (array $figure) => [...$figure, 'lines' => [['from' => 'A', 'to' => 'Z', 'label' => null, 'style' => 'solid']]],
			'Figur in sections.0.blocks.1: Der Punkt «Z» ist nicht definiert.',
		],
		'unknown point in an angle' => [
			fn (array $figure) => [...$figure, 'angles' => [['vertex' => 'X', 'from' => 'A', 'to' => 'B', 'label' => null]]],
			'Figur in sections.0.blocks.1: Der Punkt «X» ist nicht definiert.',
		],
		'coordinate out of range' => [
			fn (array $figure) => array_replace_recursive($figure, ['points' => [0 => ['x' => 120]]]),
			'Figur in sections.0.blocks.1: Der Punkt «G1» liegt ausserhalb von 0–100.',
		],
		'duplicate point id' => [
			fn (array $figure) => array_replace_recursive($figure, ['points' => [1 => ['id' => $figure['points'][0]['id']]]]),
			'Figur in sections.0.blocks.1: Die Punkt-ID «G1» kommt mehrfach vor.',
		],
	]);

	it('checks the shape of a figure', function (Closure $change) {
		expect(figureErrors($change))->toContain('Block sections.0.blocks.1 (Typ «figure») ist unvollständig oder hat falsche Felder.');
	})->with([
		'one point' => fn (array $figure) => [...$figure, 'points' => array_slice($figure['points'], 0, 1), 'lines' => [], 'angles' => []],
		'13 points' => fn (array $figure) => [...$figure, 'points' => array_map(fn ($i) => ['id' => "P{$i}", 'x' => $i, 'y' => $i, 'label' => null], range(1, 13))],
		'17 lines' => fn (array $figure) => [...$figure, 'lines' => array_fill(0, 17, $figure['lines'][0])],
		'7 angles' => fn (array $figure) => [...$figure, 'angles' => array_fill(0, 7, $figure['angles'][0])],
		'coordinate not a number' => fn (array $figure) => array_replace_recursive($figure, ['points' => [0 => ['x' => 'links']]]),
		'unknown line style' => fn (array $figure) => array_replace_recursive($figure, ['lines' => [0 => ['style' => 'dotted']]]),
		'no lines field' => fn (array $figure) => array_diff_key($figure, ['lines' => true]),
		'title not text' => fn (array $figure) => [...$figure, 'title' => 5],
	]);
});

describe('german', function () {
	it('accepts the german fixture strictly with its profile', function () {
		expect(ContentValidator::errors(lessonFixture('das-dass'), strict: true, profile: Profile::German))->toBe([]);
	});

	it('rejects find the mistake on a fresh page of another profile', function () {
		$content = lessonFixture('das-dass');

		expect(ContentValidator::errors($content, strict: true, profile: Profile::General))
			->toContain('Das Fachprofil «Allgemein» hat kein Lernmodul «find_the_mistake».')
			->and(ContentValidator::errors($content))->toBe([]);
	});

	it('counts find the mistake as a learning module', function () {
		$content = lessonFixture('das-dass');
		$content['modules'] = [...$content['modules'], 'quiz' => null, 'sorting' => null, 'flashcards' => null, 'cloze' => null];

		expect(ContentValidator::errors($content))->toBe([]);
	});

	it('checks find the mistake', function (Closure $change, string $key) {
		$content = lessonFixture('das-dass');
		$content['modules']['find_the_mistake'] = $change($content['modules']['find_the_mistake']);

		expect(ContentValidator::make($content)->errors()->keys())->toContain($key);
	})->with([
		'too few' => [fn (array $module) => [...$module, 'entries' => array_slice($module['entries'], 0, 3)], 'modules.find_the_mistake.entries'],
		'too many' => [fn (array $module) => [...$module, 'entries' => [...$module['entries'], ...$module['entries']]], 'modules.find_the_mistake.entries'],
		'word beyond the sentence' => [fn (array $module) => array_replace_recursive($module, ['entries' => [0 => ['mistake_word' => 6]]]), 'modules.find_the_mistake.entries.0.mistake_word'],
		'negative word' => [fn (array $module) => array_replace_recursive($module, ['entries' => [0 => ['mistake_word' => -1]]]), 'modules.find_the_mistake.entries.0.mistake_word'],
		'word as string' => [fn (array $module) => array_replace_recursive($module, ['entries' => [0 => ['mistake_word' => '2']]]), 'modules.find_the_mistake.entries.0.mistake_word'],
		'correction same as the word' => [fn (array $module) => array_replace_recursive($module, ['entries' => [0 => ['correction' => 'das']]]), 'modules.find_the_mistake.entries.0.correction'],
		'no explanation' => [fn (array $module) => array_replace_recursive($module, ['entries' => [0 => ['explanation' => '']]]), 'modules.find_the_mistake.entries.0.explanation'],
		'duplicate id' => [fn (array $module) => array_replace_recursive($module, ['entries' => [1 => ['id' => 'q1']]]), 'modules'],
	]);

	it('accepts only a boolean or null as case sensitivity of the cloze', function (mixed $value, bool $valid) {
		$content = lessonFixture('das-dass');
		$content['modules']['cloze']['case_sensitive'] = $value;

		expect(ContentValidator::make($content)->errors()->has('modules.cloze.case_sensitive'))->toBe(! $valid);
	})->with([
		[true, true],
		[false, true],
		[null, true],
		['true', false],
		[1, false],
	]);
});
