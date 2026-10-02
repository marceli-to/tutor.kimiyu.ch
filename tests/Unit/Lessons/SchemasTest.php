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
    'check' => fn () => Schemas::checkResult(),
    'hero' => fn () => Schemas::hero(),
]);

it('never sends the whole page as one schema', function () {
    // Die API lehnt das Schema der ganzen Seite ab: «The compiled grammar is too large»
    expect(Schemas::analysis()['properties']['seite']['anyOf'][0]['properties'])->not->toHaveKey('module')
        ->and(Schemas::part('seite')['properties']['seite']['properties'])->not->toHaveKey('module');
});

it('has a list of additions in the analysis and an origin on every item', function () {
    $analysis = Schemas::analysis()['properties'];
    $page = $analysis['seite']['anyOf'][0]['properties'];
    $modules = Schemas::modules()['properties'];
    $origin = ['type' => 'string', 'enum' => ['foto', 'ergaenzt']];

    expect($analysis)->toHaveKey('ergaenzungen')
        ->and($analysis['ergaenzungen']['items'])->toBe(['type' => 'string'])
        ->and($modules['quiz']['items']['properties']['herkunft'])->toMatchArray($origin)
        ->and($modules['sortieren']['anyOf'][0]['properties']['begriffe']['items']['properties']['herkunft'])->toMatchArray($origin)
        ->and($modules['karten']['anyOf'][0]['properties']['eintraege']['items']['properties']['herkunft'])->toMatchArray($origin)
        ->and($modules['lueckentext']['anyOf'][0]['properties']['herkunft'])->toMatchArray($origin);

    foreach ($page['abschnitte']['items']['properties']['bloecke']['items']['anyOf'] as $block) {
        expect($block['properties']['herkunft'])->toMatchArray($origin)
            ->and($block['required'])->toContain('herkunft');
    }
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
    ['pruefung', fn () => Schemas::checkResult()],
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
        'ergaenzungen' => [],
        'grafik_plaene' => [],
        'seite' => null,
    ];

    expect(JsonSchema::errors($response, Schemas::analysis()))->toBe([]);
});

it('plans the graphics in the analysis and places them with a block', function () {
    $analysis = Schemas::analysis()['properties'];
    $content = LessonFactory::fixture('fotosynthese');
    $content['abschnitte'][1]['bloecke'][] = ['typ' => 'grafik', 'nr' => 2, 'herkunft' => 'foto'];

    expect($analysis)->not->toHaveKey('hero_plan')
        ->and(JsonSchema::errors(['grafik_plaene' => [
            ['nr' => 1, 'plan' => ['muster' => 'regler', 'idee' => 'Regler'], 'hinweis' => null],
            ['nr' => 2, 'plan' => null, 'hinweis' => 'Passt nicht zu den Fotos.'],
        ]], ['type' => 'object', 'properties' => ['grafik_plaene' => $analysis['grafik_plaene']], 'required' => ['grafik_plaene'], 'additionalProperties' => false]))->toBe([])
        ->and(JsonSchema::errors($content, Schemas::content()))->toBe([]);
});
