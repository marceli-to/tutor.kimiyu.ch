<?php

use App\Lessons\Speech\Texts;

it('speaks only the word itself', function (string $text, string $spoken) {
	expect(Texts::spokenText($text))->toBe($spoken);
})->with([
	'infinitive in brackets' => ['parlé (parler)', 'parlé'],
	'plural after a slash' => ['le livre / les livres', 'le livre'],
	'plain word' => ['  chat ', 'chat'],
	'dash stays' => ['aller – allé', 'aller – allé'],
	'nothing before the bracket' => ['(se) laver', '(se) laver'],
]);

it('collects the vocabulary and the flashcard fronts once each', function () {
	$content = json_decode(file_get_contents(database_path('fixtures/lessons/passe-compose.json')), true);

	$texts = Texts::texts($content);

	expect($texts)->toContain('parlé (parler)')
		->toContain('aller – allé')
		->toContain($content['modules']['flashcards']['entries'][0]['front'])
		->and($texts)->toBe(array_values(array_unique($texts)));
});

it('finds nothing without vocabulary or flashcards', function () {
	expect(Texts::texts(['sections' => [['blocks' => [['type' => 'text', 'text' => 'Hallo']]]], 'modules' => ['flashcards' => null]]))
		->toBe([]);
});

it('hashes the spoken text with language, voice and model', function () {
	$hash = Texts::hash('chat', 'fr-FR', 'voice-1', 'eleven_v4');

	expect($hash)->toMatch('/^[0-9a-f]{64}$/')
		->and(Texts::hash('chat', 'fr-FR', 'voice-1', 'eleven_v4'))->toBe($hash)
		->and(Texts::hash('chat', 'fr-FR', 'voice-2', 'eleven_v4'))->not->toBe($hash)
		->and(Texts::hash('chat', 'fr-FR', 'voice-1', 'eleven_flash_v2_5'))->not->toBe($hash)
		->and(Texts::hash('chat', 'en-GB', 'voice-1', 'eleven_v4'))->not->toBe($hash);
});
