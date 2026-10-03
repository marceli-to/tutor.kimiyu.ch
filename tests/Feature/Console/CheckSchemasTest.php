<?php

use App\Lessons\Ai\ClaudeLanguageModel;
use App\Lessons\Ai\ModelException;
use App\Lessons\Ai\ModelRequest;
use App\Lessons\Ai\ModelResponse;
use App\Lessons\Ai\Schemas;
use App\Lessons\Ai\UsageAwareModelException;
use App\Lessons\Profile;
use Illuminate\Support\Collection;

/**
 * Replaces the real API client: $answer decides per request what the API does.
 */
function fakeClaude(Closure $answer): Collection
{
	$requests = collect();

	app()->instance(ClaudeLanguageModel::class, Mockery::mock(ClaudeLanguageModel::class, function ($mock) use ($answer, $requests) {
		$mock->shouldReceive('generate')->andReturnUsing(function (ModelRequest $request) use ($answer, $requests) {
			$requests->push($request);

			return $answer($request);
		});
	}));

	return $requests;
}

function truncated(): UsageAwareModelException
{
	return new UsageAwareModelException('Abgeschnitten.', 'max_tokens', new ModelResponse([], 'claude-opus-5-5', 10, 1));
}

it('sends every schema with one output token and the model of its step', function () {
	config(['lessons.models.modules.model' => 'claude-sonnet-5-5', 'lessons.models.analysis.model' => 'claude-opus-5-5']);
	$requests = fakeClaude(fn () => throw truncated());

	$this->artisan('lessons:check-schemas')->assertSuccessful();

	$steps = $requests->map(fn (ModelRequest $request) => $request->step);

	expect($requests->every(fn (ModelRequest $request) => $request->maxTokens === 1))->toBeTrue()
		->and($steps->unique()->values()->all())->toEqualCanonicalizing([
			'analysis', 'page', 'modules', 'repair-page', 'repair-modules', 'regenerate-quiz', 'check', 'graphic', 'graphic-repair',
		])
		->and($steps->filter(fn ($step) => $step === 'page')->count())->toBe(count(Profile::cases()))
		->and($requests->firstWhere('step', 'modules')->model())->toBe('claude-sonnet-5-5')
		->and($requests->firstWhere('step', 'page')->schema)->toBe(Schemas::part('page', Profile::cases()[0]));
});

it('fails when the api rejects a grammar as too large', function () {
	fakeClaude(fn (ModelRequest $request) => $request->step === 'page' && $request->schema === Schemas::part('page', Profile::Geometry)
		? throw new ModelException('Die Anfrage an die KI war ungültig.', 'The compiled grammar is too large, simplify the schema')
		: throw truncated());

	$this->artisan('lessons:check-schemas')
		->expectsOutputToContain('TOO LARGE')
		->assertFailed();
});

it('fails on any other api error and shows it', function () {
	fakeClaude(fn () => throw new ModelException('Der Zugang zur KI ist falsch eingerichtet.', 'invalid x-api-key'));

	$this->artisan('lessons:check-schemas')
		->expectsOutputToContain('invalid x-api-key')
		->assertFailed();
});
