<?php

use App\Lessons\Ai\FakeLanguageModel;
use App\Lessons\Ai\Schemas;
use Database\Factories\LessonFactory;
use Tests\Support\JsonSchema;

it('only uses features the structured output supports', function (array $schema) {
    expect(JsonSchema::unsupportedKeywords($schema))->toBe([]);
})->with([
    'analysis' => fn () => Schemas::analysis(),
    'modules' => fn () => Schemas::modulesResult(),
    'repair page' => fn () => Schemas::part('seite'),
    'repair modules' => fn () => Schemas::part('module'),
    'check page' => fn () => Schemas::part('seite', withChanges: true),
    'check modules' => fn () => Schemas::part('module', withChanges: true),
    'hero' => fn () => Schemas::hero(),
]);

it('never sends the whole page as one schema', function () {
    // Die API lehnt das Schema der ganzen Seite ab: «The compiled grammar is too large»
    expect(Schemas::analysis()['properties']['seite']['anyOf'][0]['properties'])->not->toHaveKey('module')
        ->and(Schemas::part('seite')['properties']['seite']['properties'])->not->toHaveKey('module');
});

it('accepts both fixtures as page content', function (string $fixture) {
    expect(JsonSchema::errors(LessonFactory::fixture($fixture), Schemas::content()))->toBe([]);
})->with(LessonFactory::FIXTURES);

it('accepts both fixture heroes', function (string $fixture) {
    expect(JsonSchema::errors(LessonFactory::fixture("$fixture.hero"), Schemas::hero()))->toBe([]);
})->with(LessonFactory::FIXTURES);

it('matches the responses of the fake model', function (string $step, Closure $schema) {
    expect(JsonSchema::errors(FakeLanguageModel::defaultResponse($step), $schema()))->toBe([]);
})->with([
    ['analyse', fn () => Schemas::analysis()],
    ['module', fn () => Schemas::modulesResult()],
    ['reparatur-seite', fn () => Schemas::part('seite')],
    ['reparatur-module', fn () => Schemas::part('module')],
    ['pruefung-seite', fn () => Schemas::part('seite', withChanges: true)],
    ['pruefung-module', fn () => Schemas::part('module', withChanges: true)],
    ['grafik', fn () => Schemas::hero()],
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
        'hero_plan' => null,
        'seite' => null,
    ];

    expect(JsonSchema::errors($response, Schemas::analysis()))->toBe([]);
});
