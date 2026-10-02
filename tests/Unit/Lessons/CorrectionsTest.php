<?php

use App\Lessons\Corrections;
use Database\Factories\LessonFactory;

function correction(string $pfad, string $wert): array
{
    return ['pfad' => $pfad, 'wert' => $wert, 'bereich' => 'Test', 'aenderung' => 'Test.'];
}

beforeEach(fn () => $this->content = LessonFactory::fixture('fotosynthese'));

it('replaces a text value', function () {
    $result = Corrections::apply($this->content, [correction('/module/quiz/0/tipp', 'Neuer Tipp.')]);

    expect($result['content']['module']['quiz'][0]['tipp'])->toBe('Neuer Tipp.')
        ->and($result['applied'])->toHaveCount(1)
        ->and($result['rejected'])->toBe([]);
});

it('decodes json for values that are not strings', function () {
    $result = Corrections::apply($this->content, [correction('/module/quiz/0/loesung', '2')]);

    expect($result['content']['module']['quiz'][0]['loesung'])->toBe(2);
});

it('rejects paths that do not exist', function (string $pfad) {
    $result = Corrections::apply($this->content, [correction($pfad, 'x')]);

    expect($result['content'])->toBe($this->content)
        ->and($result['rejected'])->toHaveCount(1);
})->with(['/module/quiz/99/tipp', '/meta/farbe', '', 'meta/titel']);

it('rejects changes to ids', function () {
    $result = Corrections::apply($this->content, [correction('/module/quiz/0/id', 'q9')]);

    expect($result['content'])->toBe($this->content);
});

it('rejects invalid json for non-string values', function () {
    $result = Corrections::apply($this->content, [correction('/module/quiz/0/loesung', 'eins')]);

    expect($result['content'])->toBe($this->content);
});

it('keeps valid corrections and drops the ones that break the content', function () {
    $result = Corrections::apply($this->content, [
        correction('/module/quiz/0/loesung', '99'),
        correction('/meta/kernidee', 'Pflanzen machen aus Licht Zucker.'),
    ]);

    expect($result['content']['module']['quiz'][0]['loesung'])->toBe($this->content['module']['quiz'][0]['loesung'])
        ->and($result['content']['meta']['kernidee'])->toBe('Pflanzen machen aus Licht Zucker.')
        ->and($result['applied'])->toHaveCount(1)
        ->and($result['rejected'])->toHaveCount(1);
});

it('applies corrections of the same item together', function () {
    $this->content['module']['quiz'][0]['loesung'] = 3;

    $result = Corrections::apply($this->content, [
        correction('/module/quiz/0/optionen', '["Sauerstoff und Wasser","Kohlenstoffdioxid und Wasser","Traubenzucker und Sauerstoff"]'),
        correction('/module/quiz/0/loesung', '1'),
    ]);

    expect($result['content']['module']['quiz'][0]['optionen'])->toHaveCount(3)
        ->and($result['content']['module']['quiz'][0]['loesung'])->toBe(1)
        ->and($result['applied'])->toHaveCount(2)
        ->and($result['rejected'])->toBe([]);
});

it('rejects all corrections of an item when one of them is invalid', function () {
    $result = Corrections::apply($this->content, [
        correction('/module/quiz/0/tipp', 'Neuer Tipp.'),
        correction('/module/quiz/0/loesung', '99'),
    ]);

    expect($result['content'])->toBe($this->content)
        ->and($result['content']['module']['quiz'][0]['tipp'])->toBe($this->content['module']['quiz'][0]['tipp'])
        ->and($result['applied'])->toBe([])
        ->and($result['rejected'])->toHaveCount(2);
});

it('stores a json encoded text without quotes', function () {
    $result = Corrections::apply($this->content, [correction('/module/quiz/0/tipp', '"Neuer Tipp."')]);

    expect($result['content']['module']['quiz'][0]['tipp'])->toBe('Neuer Tipp.')
        ->and($result['applied'])->toHaveCount(1);
});

it('rejects a value of another type', function () {
    $result = Corrections::apply($this->content, [correction('/module/quiz/0/loesung', '"2"')]);

    expect($result['content'])->toBe($this->content)
        ->and($result['rejected'])->toHaveCount(1);
});

it('rejects setting a value to null', function () {
    $result = Corrections::apply($this->content, [correction('/module/karten', 'null')]);

    expect($result['content'])->toBe($this->content)
        ->and($result['rejected'])->toHaveCount(1);
});

it('rejects replacing a list of objects', function () {
    $quiz = array_slice($this->content['module']['quiz'], 0, 3);

    $result = Corrections::apply($this->content, [correction('/module/quiz', json_encode($quiz, JSON_UNESCAPED_UNICODE))]);

    expect($result['content'])->toBe($this->content)
        ->and($result['rejected'])->toHaveCount(1);
});

it('rejects replacing a whole object', function () {
    $question = [...$this->content['module']['quiz'][0], 'id' => 'q9', 'tipp' => 'Neuer Tipp.'];

    $result = Corrections::apply($this->content, [correction('/module/quiz/0', json_encode($question, JSON_UNESCAPED_UNICODE))]);

    expect($result['content'])->toBe($this->content)
        ->and($result['rejected'])->toHaveCount(1);
});

it('rejects filling a module that is null', function () {
    expect($this->content['module']['sortieren'])->toBeNull();

    $result = Corrections::apply($this->content, [correction('/module/sortieren', '{"anleitung":"Sortiere.","kategorien":[],"begriffe":[]}')]);

    expect($result['content'])->toBe($this->content)
        ->and($result['rejected'])->toHaveCount(1);
});

it('rejects filling a text that is null', function () {
    $this->content['module']['quiz'][0]['tipp'] = null;

    $result = Corrections::apply($this->content, [correction('/module/quiz/0/tipp', 'Neuer Tipp.')]);

    expect($result['content'])->toBe($this->content)
        ->and($result['rejected'])->toHaveCount(1);
});

it('rejects an empty list or a list with objects for a list of texts', function (string $wert) {
    $result = Corrections::apply($this->content, [correction('/module/quiz/0/optionen', $wert)]);

    expect($result['content'])->toBe($this->content)
        ->and($result['rejected'])->toHaveCount(1);
})->with(['[]', '[{"text":"A"},{"text":"B"}]', '{"a":"A","b":"B"}', '"A"']);
