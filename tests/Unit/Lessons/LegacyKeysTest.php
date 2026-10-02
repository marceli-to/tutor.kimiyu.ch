<?php

use App\Lessons\LegacyKeys;
use Database\Factories\LessonFactory;

/**
 * @param  array<array-key, mixed>  $data
 * @return list<string>
 */
function allKeys(array $data): array
{
    $keys = [];

    foreach ($data as $key => $value) {
        if (is_string($key)) {
            $keys[] = $key;
        }

        if (is_array($value)) {
            array_push($keys, ...allKeys($value));
        }
    }

    return array_values(array_unique($keys));
}

dataset('fixtures', fn () => [
    ...LessonFactory::FIXTURES,
    ...array_map(fn (string $name) => "$name.graphic", LessonFactory::FIXTURES),
]);

it('maps a German page to English and back without loss', function (string $name) {
    // The fixtures are English since 2026-10-02; their German form is what old lessons stored
    $german = LegacyKeys::contentToGerman(LessonFactory::fixture($name));
    $english = LegacyKeys::contentToEnglish($german);

    expect($english)->toBe(LessonFactory::fixture($name))
        ->and(LegacyKeys::contentToGerman($english))->toBe($german)
        ->and(array_intersect(allKeys($english), array_keys(LegacyKeys::KEYS)))->toBe([])
        ->and(array_intersect(allKeys($german), array_values(LegacyKeys::KEYS)))->toBe([]);
})->with('fixtures');

it('maps the enum values only in their fields', function () {
    $german = [
        'meta' => ['titel' => 'grafik', 'palette' => 'gruen'],
        'abschnitte' => [['titel' => 'foto', 'bloecke' => [
            ['typ' => 'grafik', 'nr' => 2],
            ['typ' => 'absatz', 'text' => 'absatz', 'herkunft' => 'ergaenzt'],
        ]]],
        'plan' => ['muster' => 'regler', 'idee' => 'regler'],
    ];

    expect(LegacyKeys::contentToEnglish($german))->toBe([
        'meta' => ['title' => 'grafik', 'palette' => 'green'],
        'sections' => [['title' => 'foto', 'blocks' => [
            ['type' => 'graphic', 'number' => 2],
            ['type' => 'paragraph', 'text' => 'absatz', 'origin' => 'added'],
        ]]],
        'plan' => ['pattern' => 'sliders', 'idea' => 'regler'],
    ]);
});

it('maps step names part by part', function (string $german, string $english) {
    expect(LegacyKeys::stepToEnglish($german))->toBe($english)
        ->and(LegacyKeys::stepToGerman($english))->toBe($german);
})->with([
    ['warteschlange', 'queued'],
    ['analyse', 'analysis'],
    ['seite', 'page'],
    ['module', 'modules'],
    ['pruefung', 'check'],
    ['grafik', 'graphic'],
    ['grafik-2', 'graphic-2'],
    ['grafik-reparatur', 'graphic-repair'],
    ['reparatur-seite', 'repair-page'],
    ['reparatur-module', 'repair-modules'],
    ['neu-quiz', 'regenerate-quiz'],
    ['neu-grafik', 'regenerate-graphic'],
    ['pruefung-seite', 'check-page'],
]);

it('keeps unknown values', function () {
    expect(LegacyKeys::value('irgendwas', LegacyKeys::MODULES))->toBe('irgendwas')
        ->and(LegacyKeys::value(null, LegacyKeys::SCOPES))->toBeNull()
        ->and(LegacyKeys::value('ausfuehrlich', LegacyKeys::SCOPES))->toBe('detailed')
        ->and(LegacyKeys::value('exam', LegacyKeys::PURPOSES, toGerman: true))->toBe('pruefung');
});
