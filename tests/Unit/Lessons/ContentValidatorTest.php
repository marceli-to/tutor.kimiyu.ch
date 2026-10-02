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
})->with(['meta', 'abschnitte', 'module', 'nachdenken']);

it('rejects an unknown palette', function () {
    $content = lessonFixture();
    $content['meta']['palette'] = 'neonpink';

    expect(ContentValidator::make($content)->errors()->has('meta.palette'))->toBeTrue();
});

it('rejects a quiz answer that points past the options', function () {
    $content = lessonFixture();
    $content['module']['quiz'][0]['optionen'] = ['A', 'B', 'C'];
    $content['module']['quiz'][0]['loesung'] = 3;

    expect(ContentValidator::errors($content))->toContain('Quizfrage q1: Die Lösung zeigt auf eine Option, die es nicht gibt.');
});

it('rejects duplicate answer options', function () {
    $content = lessonFixture();
    $content['module']['quiz'][1]['optionen'][3] = 'im zellkern ';

    expect(ContentValidator::errors($content))->toContain('Quizfrage q2: Zwei Antwortoptionen sind gleich.');
});

it('rejects too few or too many options', function (int $count) {
    $content = lessonFixture();
    $content['module']['quiz'][0]['optionen'] = array_fill(0, $count, 'x');

    expect(ContentValidator::make($content)->errors()->has('module.quiz.0.optionen'))->toBeTrue();
})->with([2, 5]);

it('rejects sorting terms in a category that does not exist', function () {
    $content = lessonFixture('oekosystem');
    $content['module']['sortieren']['begriffe'][0]['kategorie'] = 'cat3';

    expect(ContentValidator::errors($content))->toContain('Sortierspiel: «Sonnenlicht» gehört zu einer Kategorie, die es nicht gibt.');
});

it('rejects a sorting category without terms', function () {
    $content = lessonFixture('oekosystem');
    $content['module']['sortieren']['kategorien'][] = ['id' => 'cat3', 'label' => 'Pilze', 'sub' => null];

    expect(ContentValidator::errors($content))->toContain('Sortierspiel: Die Kategorie cat3 hat keine Begriffe.');
});

it('rejects duplicate item ids across modules', function () {
    $content = lessonFixture();
    $content['module']['karten']['eintraege'][0]['id'] = 'q1';

    expect(ContentValidator::errors($content))->toContain('Diese IDs kommen mehrfach vor: q1.');
});

it('rejects malformed cloze segments', function () {
    $content = lessonFixture();
    $content['module']['lueckentext']['segmente'][1] = ['id' => 'g1', 'loesungen' => []];

    expect(ContentValidator::make($content)->errors()->has('module.lueckentext.segmente.1'))->toBeTrue();
});

it('rejects a cloze text without gaps', function () {
    $content = lessonFixture();
    $content['module']['lueckentext']['segmente'] = [['text' => 'Nur Text.']];

    expect(ContentValidator::errors($content))->toContain('Lückentext: Es gibt keine Lücke.');
});

it('rejects incomplete blocks', function () {
    $content = lessonFixture('oekosystem');
    unset($content['abschnitte'][0]['bloecke'][0]['eintraege'][1]['kategorie']);

    expect(ContentValidator::make($content)->errors()->has('abschnitte.0.bloecke.0'))->toBeTrue();
});

it('rejects the German sharp s anywhere', function () {
    $content = lessonFixture();
    $content['abschnitte'][1]['bloecke'][0]['eintraege'][2]['text'] = 'Das ist groß.';

    expect(ContentValidator::errors($content))
        ->toContain('Im Feld abschnitte.1.bloecke.0.eintraege.2.text steht ein «ß». In der Schweiz schreibt man «ss».');
});

it('accepts content without any origin markers', function () {
    // Alte Seiten haben kein Feld «herkunft»
    $strip = function (array $value) use (&$strip): array {
        unset($value['herkunft']);

        return array_map(fn ($item) => is_array($item) ? $strip($item) : $item, $value);
    };

    $content = $strip(lessonFixture());

    expect(json_encode($content))->not->toContain('herkunft')
        ->and(ContentValidator::errors($content, strict: true))->toBe([]);
});

it('rejects an unknown origin', function (string $key, Closure $set) {
    $content = $set(lessonFixture());

    expect(ContentValidator::make($content)->errors()->has($key))->toBeTrue();
})->with([
    'block' => ['abschnitte.0.bloecke.0.herkunft', function (array $c) {
        $c['abschnitte'][0]['bloecke'][0]['herkunft'] = 'buch';

        return $c;
    }],
    'quiz question' => ['module.quiz.0.herkunft', function (array $c) {
        $c['module']['quiz'][0]['herkunft'] = 'buch';

        return $c;
    }],
    'flashcard' => ['module.karten.eintraege.0.herkunft', function (array $c) {
        $c['module']['karten']['eintraege'][0]['herkunft'] = 'buch';

        return $c;
    }],
    'cloze' => ['module.lueckentext.herkunft', function (array $c) {
        $c['module']['lueckentext']['herkunft'] = 'buch';

        return $c;
    }],
]);

it('rejects an unknown origin on a sorting term', function () {
    $content = lessonFixture('oekosystem');
    $content['module']['sortieren']['begriffe'][0]['herkunft'] = 'buch';

    expect(ContentValidator::make($content)->errors()->has('module.sortieren.begriffe.0.herkunft'))->toBeTrue();
});

describe('strict mode', function () {
    it('requires exactly five quiz questions', function () {
        $content = lessonFixture();
        array_pop($content['module']['quiz']);

        expect(ContentValidator::make($content)->passes())->toBeTrue()
            ->and(ContentValidator::make($content, strict: true)->errors()->has('module.quiz'))->toBeTrue();
    });

    it('rejects answers that are always in the same position', function () {
        $content = lessonFixture();
        foreach ($content['module']['quiz'] as &$question) {
            $question['loesung'] = 1;
        }

        expect(ContentValidator::errors($content))->toBe([])
            ->and(ContentValidator::errors($content, strict: true))
            ->toContain('Quiz: Die richtige Antwort steht immer an derselben Position.');
    });

    it('requires at least one module besides the quiz', function () {
        $content = lessonFixture();
        $content['module']['karten'] = null;
        $content['module']['lueckentext'] = null;

        expect(ContentValidator::errors($content, strict: true))
            ->toContain('Neben dem Quiz braucht es mindestens ein weiteres Modul (Sortieren, Karteikarten oder Lückentext).');
    });
});

describe('graphic blocks', function () {
    function withGraphicBlock(array $block, int $section = 1): array
    {
        $content = lessonFixture();
        $content['abschnitte'][$section]['bloecke'][] = $block;

        return $content;
    }

    it('accepts a block for graphic 2 or 3', function (int $nr) {
        expect(ContentValidator::errors(withGraphicBlock(['typ' => 'grafik', 'nr' => $nr, 'herkunft' => 'foto']), strict: true))->toBe([]);
    })->with([2, 3]);

    it('rejects a block without a valid number', function (array $block) {
        expect(ContentValidator::errors(withGraphicBlock($block)))->not->toBe([]);
    })->with([
        'graphic 1 is at the top' => [['typ' => 'grafik', 'nr' => 1]],
        'there are only 3' => [['typ' => 'grafik', 'nr' => 4]],
        'missing' => [['typ' => 'grafik']],
        'text' => [['typ' => 'grafik', 'nr' => 'zwei']],
    ]);

    it('needs explaining text next to a graphic in strict mode', function () {
        $content = lessonFixture();
        $content['abschnitte'][] = ['titel' => 'Nur Grafik', 'bloecke' => [['typ' => 'grafik', 'nr' => 2]]];

        expect(ContentValidator::errors($content))->toBe([])
            ->and(ContentValidator::errors($content, strict: true))
            ->toContain('Abschnitt «Nur Grafik»: Eine Grafik braucht erklärenden Text daneben.');
    });
});
