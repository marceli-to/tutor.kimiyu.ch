<?php

use Anthropic\Client;
use App\Lessons\Ai\ClaudeLanguageModel;
use App\Lessons\Ai\ModelException;
use App\Lessons\Ai\ModelRequest;

it('fails the step cleanly when the effort is unknown', function () {
    config()->set('lessons.models.modules', ['model' => 'claude-sonnet-5-5', 'effort' => 'hoch']);

    // Ungültige Adresse: Falls doch eine Anfrage rausginge, gäbe es einen anderen Fehler statt eines Netzwerk-Calls
    $model = new ClaudeLanguageModel(
        client: new Client(apiKey: 'test', baseUrl: 'http://127.0.0.1:9'),
        fallbacks: false,
        timeout: 5,
    );

    try {
        $model->generate(new ModelRequest(step: 'modules', system: '', prompt: 'Test', schema: [], maxTokens: 100));
        $this->fail('Es wurde keine ModelException geworfen.');
    } catch (ModelException $e) {
        expect($e->getMessage())->toBe('Die KI ist falsch eingerichtet.')
            ->and($e->detail)->toContain('«hoch»')
            ->and($e->detail)->toContain('modules')
            ->and($e->retryable)->toBeFalse();
    }
});
