<?php

use App\Lessons\GraphicValidator;
use Database\Factories\LessonFactory;

function graphicFixture(array $changes = []): array
{
	return [...LessonFactory::fixture('oekosystem.graphic'), ...$changes];
}

it('accepts both fixture graphics', function (string $fixture) {
	expect(GraphicValidator::errors(LessonFactory::fixture("$fixture.graphic")))->toBe([]);
})->with(LessonFactory::FIXTURES);

it('allows the svg namespace but no other addresses', function () {
	expect(GraphicValidator::errors(graphicFixture()))->toBe([])
		->and(GraphicValidator::errors(graphicFixture(['markup' => '<img src="https://example.com/a.png">'])))
		->toContain('Im Feld «markup» steht eine externe Adresse. Die Grafik darf nichts nachladen.')
		->and(GraphicValidator::errors(graphicFixture(['css' => '@import url(//fonts.example.org/x.css);'])))
		->toContain('Im Feld «css» steht eine externe Adresse. Die Grafik darf nichts nachladen.');
});

it('rejects script and style elements in the markup', function (string $tag) {
	expect(GraphicValidator::errors(graphicFixture(['markup' => "<div></div><{$tag}>x</{$tag}>"])))
		->toContain("Das Markup enthält ein <{$tag}>-Element. Skript und CSS gehören in die Felder «script» und «css».");
})->with(['script', 'style', 'iframe']);

it('rejects inline event handlers', function () {
	expect(GraphicValidator::errors(graphicFixture(['markup' => '<button onclick="go()">Los</button>'])))
		->toContain('Das Markup enthält Inline-Event-Handler (onclick usw.). Ereignisse im Skript mit addEventListener verbinden.');
});

it('rejects network and storage access in the script', function (string $call) {
	expect(GraphicValidator::errors(graphicFixture(['script' => "(function(){ {$call}('x'); })();"])))
		->toContain("Das Skript verwendet «{$call}». Die Grafik darf weder Netzwerk noch Speicher nutzen.");
})->with(['fetch', 'localStorage', 'WebSocket']);

it('rejects tags that would close the surrounding element', function () {
	$errors = GraphicValidator::errors(graphicFixture(['css' => 'a{}</style><script>', 'script' => 'x="</script>"']));

	expect($errors)->toContain('Das CSS enthält «</style».')
		->toContain('Das Skript enthält «</script».');
});

it('rejects oversized parts, unknown patterns and the sharp s', function () {
	expect(GraphicValidator::errors(graphicFixture(['script' => str_repeat('a', 40_001)])))
		->toContain('Das Feld «script» ist zu gross (höchstens 40 KB).')
		->and(GraphicValidator::errors(graphicFixture(['pattern' => 'karussell'])))
		->toContain('Das Feld «pattern» enthält kein bekanntes Hero-Muster.')
		->and(GraphicValidator::errors(graphicFixture(['markup' => '<p>Grösse und Maß</p>'])))
		->toContain('Die Grafik enthält ein «ß». In der Schweiz schreibt man «ss».');
});
