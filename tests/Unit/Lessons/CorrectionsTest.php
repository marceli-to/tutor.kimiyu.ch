<?php

use App\Lessons\Corrections;
use Database\Factories\LessonFactory;

function correction(string $pfad, string $wert): array
{
    return ['path' => $pfad, 'value' => $wert, 'area' => 'Test', 'change' => 'Test.'];
}

beforeEach(fn () => $this->content = LessonFactory::fixture('fotosynthese'));

it('replaces a text value', function () {
    $result = Corrections::apply($this->content, [correction('/modules/quiz/0/hint', 'Neuer Tipp.')]);

    expect($result['content']['modules']['quiz'][0]['hint'])->toBe('Neuer Tipp.')
        ->and($result['applied'])->toHaveCount(1)
        ->and($result['rejected'])->toBe([]);
});

it('decodes json for values that are not strings', function () {
    $result = Corrections::apply($this->content, [correction('/modules/quiz/0/answer', '2')]);

    expect($result['content']['modules']['quiz'][0]['answer'])->toBe(2);
});

it('rejects paths that do not exist', function (string $pfad) {
    $result = Corrections::apply($this->content, [correction($pfad, 'x')]);

    expect($result['content'])->toBe($this->content)
        ->and($result['rejected'])->toHaveCount(1);
})->with(['/modules/quiz/99/hint', '/meta/farbe', '', 'meta/title']);

it('rejects changes to ids', function () {
    $result = Corrections::apply($this->content, [correction('/modules/quiz/0/id', 'q9')]);

    expect($result['content'])->toBe($this->content);
});

it('rejects changes to the origin', function (string $pfad) {
    // Die Herkunft bestimmt, was die Eltern als ergänzt sehen; die Prüfung darf sie nicht umschreiben
    $result = Corrections::apply($this->content, [correction($pfad, 'added')]);

    expect($result['content'])->toBe($this->content)
        ->and($result['rejected'])->toHaveCount(1);
})->with(['/modules/quiz/0/origin', '/sections/0/blocks/0/origin', '/modules/cloze/origin']);

it('rejects invalid json for non-string values', function () {
    $result = Corrections::apply($this->content, [correction('/modules/quiz/0/answer', 'eins')]);

    expect($result['content'])->toBe($this->content);
});

it('keeps valid corrections and drops the ones that break the content', function () {
    $result = Corrections::apply($this->content, [
        correction('/modules/quiz/0/answer', '99'),
        correction('/meta/key_idea', 'Pflanzen machen aus Licht Zucker.'),
    ]);

    expect($result['content']['modules']['quiz'][0]['answer'])->toBe($this->content['modules']['quiz'][0]['answer'])
        ->and($result['content']['meta']['key_idea'])->toBe('Pflanzen machen aus Licht Zucker.')
        ->and($result['applied'])->toHaveCount(1)
        ->and($result['rejected'])->toHaveCount(1);
});

it('applies corrections of the same item together', function () {
    $this->content['modules']['quiz'][0]['answer'] = 3;

    $result = Corrections::apply($this->content, [
        correction('/modules/quiz/0/options', '["Sauerstoff und Wasser","Kohlenstoffdioxid und Wasser","Traubenzucker und Sauerstoff"]'),
        correction('/modules/quiz/0/answer', '1'),
    ]);

    expect($result['content']['modules']['quiz'][0]['options'])->toHaveCount(3)
        ->and($result['content']['modules']['quiz'][0]['answer'])->toBe(1)
        ->and($result['applied'])->toHaveCount(2)
        ->and($result['rejected'])->toBe([]);
});

it('rejects all corrections of an item when one of them is invalid', function () {
    $result = Corrections::apply($this->content, [
        correction('/modules/quiz/0/hint', 'Neuer Tipp.'),
        correction('/modules/quiz/0/answer', '99'),
    ]);

    expect($result['content'])->toBe($this->content)
        ->and($result['content']['modules']['quiz'][0]['hint'])->toBe($this->content['modules']['quiz'][0]['hint'])
        ->and($result['applied'])->toBe([])
        ->and($result['rejected'])->toHaveCount(2);
});

it('stores a json encoded text without quotes', function () {
    $result = Corrections::apply($this->content, [correction('/modules/quiz/0/hint', '"Neuer Tipp."')]);

    expect($result['content']['modules']['quiz'][0]['hint'])->toBe('Neuer Tipp.')
        ->and($result['applied'])->toHaveCount(1);
});

it('rejects a value of another type', function () {
    $result = Corrections::apply($this->content, [correction('/modules/quiz/0/answer', '"2"')]);

    expect($result['content'])->toBe($this->content)
        ->and($result['rejected'])->toHaveCount(1);
});

it('rejects setting a value to null', function () {
    $result = Corrections::apply($this->content, [correction('/modules/flashcards', 'null')]);

    expect($result['content'])->toBe($this->content)
        ->and($result['rejected'])->toHaveCount(1);
});

it('rejects replacing a list of objects', function () {
    $quiz = array_slice($this->content['modules']['quiz'], 0, 3);

    $result = Corrections::apply($this->content, [correction('/modules/quiz', json_encode($quiz, JSON_UNESCAPED_UNICODE))]);

    expect($result['content'])->toBe($this->content)
        ->and($result['rejected'])->toHaveCount(1);
});

it('rejects replacing a whole object', function () {
    $question = [...$this->content['modules']['quiz'][0], 'id' => 'q9', 'hint' => 'Neuer Tipp.'];

    $result = Corrections::apply($this->content, [correction('/modules/quiz/0', json_encode($question, JSON_UNESCAPED_UNICODE))]);

    expect($result['content'])->toBe($this->content)
        ->and($result['rejected'])->toHaveCount(1);
});

it('rejects filling a module that is null', function () {
    expect($this->content['modules']['sorting'])->toBeNull();

    $result = Corrections::apply($this->content, [correction('/modules/sorting', '{"instructions":"Sortiere.","categories":[],"terms":[]}')]);

    expect($result['content'])->toBe($this->content)
        ->and($result['rejected'])->toHaveCount(1);
});

it('rejects filling a text that is null', function () {
    $this->content['modules']['quiz'][0]['hint'] = null;

    $result = Corrections::apply($this->content, [correction('/modules/quiz/0/hint', 'Neuer Tipp.')]);

    expect($result['content'])->toBe($this->content)
        ->and($result['rejected'])->toHaveCount(1);
});

it('rejects an empty list or a list with objects for a list of texts', function (string $wert) {
    $result = Corrections::apply($this->content, [correction('/modules/quiz/0/options', $wert)]);

    expect($result['content'])->toBe($this->content)
        ->and($result['rejected'])->toHaveCount(1);
})->with(['[]', '[{"text":"A"},{"text":"B"}]', '{"a":"A","b":"B"}', '"A"']);
