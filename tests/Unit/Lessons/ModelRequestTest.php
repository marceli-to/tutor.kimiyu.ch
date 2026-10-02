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
        'modules' => ['model' => 'claude-sonnet-5-5', 'effort' => 'medium'],
        'graphic' => ['model' => null, 'effort' => 'medium'],
    ]);
});

it('uses the model and effort configured for the step', function () {
    expect(modelRequest('modules')->model())->toBe('claude-sonnet-5-5')
        ->and(modelRequest('modules')->effort())->toBe('medium');
});

it('falls back to the prefix of the step', function () {
    expect(modelRequest('graphic-repair')->effort())->toBe('medium');
});

it('falls back to the global default for unknown steps and empty values', function () {
    expect(modelRequest('analysis')->model())->toBe('claude-opus-5-5')
        ->and(modelRequest('analysis')->effort())->toBe('high')
        ->and(modelRequest('graphic')->model())->toBe('claude-opus-5-5');
});

it('writes the page with the model and effort of the analysis', function () {
    $env = ['LESSON_MODEL_ANALYSIS' => 'claude-test-analysis', 'LESSON_EFFORT_ANALYSIS' => 'max'];

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

    expect($config['models']['page'])->toBe(['model' => 'claude-test-analysis', 'effort' => 'max'])
        ->and($config['models']['analysis'])->toBe($config['models']['page'])
        ->and($config['max_tokens']['page'])->toBe(32000);
});
