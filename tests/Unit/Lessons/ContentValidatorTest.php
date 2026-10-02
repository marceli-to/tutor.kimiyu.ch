<?php

use App\Lessons\ContentValidator;
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
