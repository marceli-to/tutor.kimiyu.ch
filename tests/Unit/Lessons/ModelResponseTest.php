<?php

use App\Lessons\Ai\ModelResponse;

it('calculates the cost from the configured prices', function () {
	config()->set('lessons.pricing.test-model', ['input' => 4.0, 'output' => 20.0, 'cache_read' => 0.2, 'cache_write' => 5.0]);

	$response = new ModelResponse([], 'test-model', inputTokens: 10_000, outputTokens: 5_000, cacheReadTokens: 20_000, cacheWriteTokens: 2_000);

	// 0.04 + 0.10 + 0.004 + 0.01
	expect($response->costUsd())->toEqualWithDelta(0.154, 0.00001);
});

it('logs unknown models with zero cost', function () {
	expect((new ModelResponse([], 'unbekannt', inputTokens: 1000))->costUsd())->toBe(0.0);
});
