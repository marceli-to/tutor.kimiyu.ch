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
