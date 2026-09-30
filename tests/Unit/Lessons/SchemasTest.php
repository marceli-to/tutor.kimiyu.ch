<?php

use App\Lessons\Ai\FakeLanguageModel;
use App\Lessons\Ai\Schemas;
use Database\Factories\LessonFactory;
use Tests\Support\JsonSchema;

it('only uses features the structured output supports', function (string $name) {
    expect(JsonSchema::unsupportedKeywords(Schemas::$name()))->toBe([]);
})->with(['analysis', 'repair', 'check', 'hero', 'content']);

it('accepts both fixtures as page content', function (string $fixture) {
    expect(JsonSchema::errors(LessonFactory::fixture($fixture), Schemas::content()))->toBe([]);
})->with(LessonFactory::FIXTURES);

it('accepts both fixture heroes', function (string $fixture) {
    expect(JsonSchema::errors(LessonFactory::fixture("$fixture.hero"), Schemas::hero()))->toBe([]);
})->with(LessonFactory::FIXTURES);

it('matches the responses of the fake model', function (string $step, string $schema) {
    expect(JsonSchema::errors(FakeLanguageModel::defaultResponse($step), Schemas::$schema()))->toBe([]);
})->with([
    ['analyse', 'analysis'],
    ['reparatur', 'repair'],
    ['pruefung', 'check'],
    ['grafik', 'hero'],
]);

it('rejects content with an unknown field', function () {
    $content = LessonFactory::fixture('fotosynthese');
    $content['meta']['farbe'] = 'grün';

    expect(JsonSchema::errors($content, Schemas::content()))->toContain('$.meta.farbe ist nicht erlaubt');
});

it('lets the analysis report unreadable photos without content', function () {
    $response = [
        'quelle' => ['lesbar' => false, 'problem' => 'Das Foto ist unscharf.'],
        'zusammenfassung' => '',
        'hero_plan' => ['muster' => 'regler', 'idee' => ''],
        'inhalt' => null,
    ];

    expect(JsonSchema::errors($response, Schemas::analysis()))->toBe([]);
});
