<?php

use App\Lessons\ClozeParser;

it('splits text and gaps into segments', function () {
    expect(ClozeParser::parse('Die Pflanze nimmt [CO₂|CO2] und [Wasser] auf.'))->toBe([
        ['text' => 'Die Pflanze nimmt '],
        ['id' => 'g1', 'answers' => ['CO₂', 'CO2']],
        ['text' => ' und '],
        ['id' => 'g2', 'answers' => ['Wasser']],
        ['text' => ' auf.'],
    ]);
});

it('handles gaps at the start and end', function () {
    expect(ClozeParser::parse('[Licht] ist Energie für [Pflanzen]'))->toBe([
        ['id' => 'g1', 'answers' => ['Licht']],
        ['text' => ' ist Energie für '],
        ['id' => 'g2', 'answers' => ['Pflanzen']],
    ]);
});

it('trims alternatives and drops empty ones', function () {
    expect(ClozeParser::parse('[ Sauerstoff | O₂ || ]'))->toBe([
        ['id' => 'g1', 'answers' => ['Sauerstoff', 'O₂']],
    ]);
});

it('skips gap ids that are already taken', function () {
    $segments = ClozeParser::parse('[a] und [b]', existingIds: ['g1', 'g3']);

    expect(array_column(array_filter($segments, fn ($s) => isset($s['id'])), 'id'))->toBe(['g2', 'g4']);
});

it('rejects an empty gap', function () {
    ClozeParser::parse('Das ist [ | ] leer.');
})->throws(InvalidArgumentException::class, 'Eine Lücke ist leer');

it('rejects an unclosed bracket', function (string $markup) {
    ClozeParser::parse($markup);
})->with([
    'opening only' => 'Die Pflanze nimmt [CO₂ auf.',
    'closing only' => 'Die Pflanze nimmt CO₂] auf.',
    'nested' => 'Die [Pflanze [nimmt]] auf.',
])->throws(InvalidArgumentException::class);

it('turns segments back into the same markup', function () {
    $markup = 'Die Pflanze nimmt [Kohlenstoffdioxid|CO₂|CO2] aus der Luft und [Wasser] aus dem Boden.';

    expect(ClozeParser::toMarkup(ClozeParser::parse($markup)))->toBe($markup);
});

it('accepts answers regardless of case and extra spaces', function () {
    $solutions = ['Kohlenstoffdioxid', 'CO₂'];

    expect(ClozeParser::isCorrect('  kohlenstoffdioxid ', $solutions))->toBeTrue()
        ->and(ClozeParser::isCorrect('co₂', $solutions))->toBeTrue()
        ->and(ClozeParser::isCorrect('Sauerstoff', $solutions))->toBeFalse()
        ->and(ClozeParser::normalize("Rote   \n Blutkörperchen"))->toBe('rote blutkörperchen');
});
