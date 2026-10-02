<?php

use App\Lessons\Ai\ModelRequest;

function modelRequest(string $step): ModelRequest
{
    return new ModelRequest(step: $step, system: '', prompt: '', schema: [], maxTokens: 100);
}

beforeEach(function () {
    config()->set('services.anthropic.model', 'claude-opus-5-5');
    config()->set('services.anthropic.effort', 'high');
    config()->set('lessons.models', [
        'module' => ['model' => 'claude-sonnet-5-5', 'effort' => 'medium'],
        'grafik' => ['model' => null, 'effort' => 'medium'],
    ]);
});

it('uses the model and effort configured for the step', function () {
    expect(modelRequest('module')->model())->toBe('claude-sonnet-5-5')
        ->and(modelRequest('module')->effort())->toBe('medium');
});

it('falls back to the prefix of the step', function () {
    expect(modelRequest('grafik-reparatur')->effort())->toBe('medium');
});

it('falls back to the global default for unknown steps and empty values', function () {
    expect(modelRequest('analyse')->model())->toBe('claude-opus-5-5')
        ->and(modelRequest('analyse')->effort())->toBe('high')
        ->and(modelRequest('grafik')->model())->toBe('claude-opus-5-5');
});

it('writes the page with the model and effort of the analysis', function () {
    $env = ['LESSON_MODEL_ANALYSE' => 'claude-test-analyse', 'LESSON_EFFORT_ANALYSE' => 'max'];

    foreach ($env as $key => $value) {
        $_ENV[$key] = $_SERVER[$key] = $value;
    }

    try {
        $config = require config_path('lessons.php');
    } finally {
        foreach ($env as $key => $value) {
            unset($_ENV[$key], $_SERVER[$key]);
        }
    }

    expect($config['models']['seite'])->toBe(['model' => 'claude-test-analyse', 'effort' => 'max'])
        ->and($config['models']['analyse'])->toBe($config['models']['seite'])
        ->and($config['max_tokens']['seite'])->toBe(32000);
});
