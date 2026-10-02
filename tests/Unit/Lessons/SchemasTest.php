<?php

use App\Lessons\Ai\FakeLanguageModel;
use App\Lessons\Ai\Schemas;
use Database\Factories\LessonFactory;
use Tests\Support\JsonSchema;

it('only uses features the structured output supports', function (array $schema) {
    expect(JsonSchema::unsupportedKeywords($schema))->toBe([]);
})->with([
    'analysis' => fn () => Schemas::analysis(),
    'page' => fn () => Schemas::part('page'),
    'modules' => fn () => Schemas::modulesResult(),
    'repair page' => fn () => Schemas::part('page'),
    'repair modules' => fn () => Schemas::part('modules'),
    'check' => fn () => Schemas::checkResult(),
    'graphic' => fn () => Schemas::graphic(),
    'quiz' => fn () => Schemas::quizResult(),
]);

it('allows modules without quiz but a new quiz always has questions', function () {
    $modules = FakeLanguageModel::defaultResponse('modules');
    $modules['modules']['quiz'] = null;

    expect(JsonSchema::errors($modules, Schemas::modulesResult()))->toBe([])
        ->and(JsonSchema::errors(['quiz' => null], Schemas::quizResult()))->not->toBe([]);
});

it('never sends the whole page as one schema', function () {
    // Die API lehnt das Schema der ganzen Seite ab: «The compiled grammar is too large».
    // Auch Analyse und Textteil zusammen sind zu gross, deshalb schreibt ein eigener Schritt den Textteil.
    expect(Schemas::analysis()['properties'])->not->toHaveKey('page')
        ->and(Schemas::part('page')['properties']['page']['properties'])->not->toHaveKey('modules');
});

it('keeps the schemas small enough for the api', function () {
    // Die API kompiliert jedes Schema zu einer Grammatik und lehnt zu grosse ab
    // («The compiled grammar is too large»). Die Grenze ist nicht dokumentiert; gemessen mit echten
    // Aufrufen: die frühere Analyse mit Textteil (5261 Bytes JSON) war zu gross; die Analyse ohne
    // Textteil (1586) und {page: page()} (3721; mit den englischen Schlüsseln 1677 und 3692, geprüft 2026-10-02) gehen durch, ebenso ein Schema mit 4409 Bytes.
    // Die Länge des JSON ist nur eine Faustregel für die Grösse der Grammatik (anyOf und enum zählen
    // mehr als Text). Schlägt dieser Test fehl, das Schema mit einem echten Aufruf prüfen, bevor die
    // Grenze erhöht wird.
    expect(strlen(json_encode(Schemas::analysis())))->toBeLessThan(2500)
        ->and(strlen(json_encode(Schemas::part('page'))))->toBeLessThan(4500);
});

it('keeps every other schema below the measured limit as well', function (array $schema) {
    // Same limit for every structured answer: each one is compiled to a grammar, and 4409 bytes
    // is the largest schema measured to go through (see above)
    expect(strlen(json_encode($schema)))->toBeLessThan(4500);
})->with([
    'modules' => fn () => Schemas::modulesResult(),
    'module part' => fn () => Schemas::part('modules'),
    'quiz' => fn () => Schemas::quizResult(),
    'check' => fn () => Schemas::checkResult(),
    'graphic' => fn () => Schemas::graphic(),
]);

it('has a list of additions in the analysis and an origin on every item', function () {
    $analysis = Schemas::analysis()['properties'];
    $page = Schemas::page()['properties'];
    $modules = Schemas::modules()['properties'];
    $origin = ['type' => 'string', 'enum' => ['photo', 'added']];

    expect($analysis)->toHaveKey('additions')
        ->and($analysis['additions']['items'])->toBe(['type' => 'string'])
        ->and($modules['quiz']['anyOf'][0]['items']['properties']['origin'])->toMatchArray($origin)
        ->and($modules['sorting']['anyOf'][0]['properties']['terms']['items']['properties']['origin'])->toMatchArray($origin)
        ->and($modules['flashcards']['anyOf'][0]['properties']['entries']['items']['properties']['origin'])->toMatchArray($origin)
        ->and($modules['cloze']['anyOf'][0]['properties']['origin'])->toMatchArray($origin);

    foreach ($page['sections']['items']['properties']['blocks']['items']['anyOf'] as $block) {
        expect($block['properties']['origin'])->toMatchArray($origin)
            ->and($block['required'])->toContain('origin');
    }
});

it('accepts both fixtures as page content', function (string $fixture) {
    expect(JsonSchema::errors(LessonFactory::fixture($fixture), Schemas::content()))->toBe([]);
})->with(LessonFactory::FIXTURES);

it('accepts both fixture graphics', function (string $fixture) {
    expect(JsonSchema::errors(LessonFactory::fixture("$fixture.graphic"), Schemas::graphic()))->toBe([]);
})->with(LessonFactory::FIXTURES);

it('matches the responses of the fake model', function (string $step, Closure $schema) {
    expect(JsonSchema::errors(FakeLanguageModel::defaultResponse($step), $schema()))->toBe([]);
})->with([
    ['analysis', fn () => Schemas::analysis()],
    ['page', fn () => Schemas::part('page')],
    ['modules', fn () => Schemas::modulesResult()],
    ['repair-page', fn () => Schemas::part('page')],
    ['repair-modules', fn () => Schemas::part('modules')],
    ['check', fn () => Schemas::checkResult()],
    ['graphic', fn () => Schemas::graphic()],
]);

it('rejects content with an unknown field', function () {
    $content = LessonFactory::fixture('fotosynthese');
    $content['meta']['farbe'] = 'grün';

    expect(JsonSchema::errors($content, Schemas::content()))->toContain('$.meta.farbe ist nicht erlaubt');
});

it('lets the analysis report unreadable photos without content', function () {
    $response = [
        'source' => ['readable' => false, 'problem' => 'Das Foto ist unscharf.'],
        'subject' => '',
        'summary' => '',
        'additions' => [],
        'graphic_plans' => [],
    ];

    expect(JsonSchema::errors($response, Schemas::analysis()))->toBe([]);
});

it('plans the graphics in the analysis and places them with a block', function () {
    $analysis = Schemas::analysis()['properties'];
    $content = LessonFactory::fixture('fotosynthese');
    $content['sections'][1]['blocks'][] = ['type' => 'graphic', 'number' => 2, 'origin' => 'photo'];

    expect($analysis)->not->toHaveKey('hero_plan')
        ->and(JsonSchema::errors(['graphic_plans' => [
            ['number' => 1, 'plan' => ['pattern' => 'sliders', 'idea' => 'Regler'], 'note' => null],
            ['number' => 2, 'plan' => null, 'note' => 'Passt nicht zu den Fotos.'],
        ]], ['type' => 'object', 'properties' => ['graphic_plans' => $analysis['graphic_plans']], 'required' => ['graphic_plans'], 'additionalProperties' => false]))->toBe([])
        ->and(JsonSchema::errors($content, Schemas::content()))->toBe([]);
});
