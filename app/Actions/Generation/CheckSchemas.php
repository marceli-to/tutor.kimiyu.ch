<?php

namespace App\Actions\Generation;

use App\Lessons\Ai\ClaudeLanguageModel;
use App\Lessons\Ai\ModelException;
use App\Lessons\Ai\ModelRequest;
use App\Lessons\Ai\Schemas;
use App\Lessons\Ai\UsageAwareModelException;
use App\Lessons\Profile;

/**
 * Asks the API to compile every schema the prompts send, with the model of each step.
 *
 * The API rejects grammars that compile too large, and the byte size of a schema doesn't predict
 * it. One output token per request, so a full run costs less than a cent. Always the real API,
 * also when the fake model is switched on.
 */
class CheckSchemas
{
	public function __construct(private ClaudeLanguageModel $model) {}

	/**
	 * @return list<array{name: string, model: string, bytes: int, status: 'ok'|'too_large'|'error', error: ?string}>
	 */
	public function handle(): array
	{
		return array_map($this->check(...), $this->requests());
	}

	/**
	 * Same schemas per step as in Prompts.
	 *
	 * @return list<array{0: string, 1: ModelRequest}>
	 */
	private function requests(): array
	{
		$requests = [
			['analysis', $this->request('analysis', Schemas::analysis())],
			['regenerate-quiz', $this->request('regenerate-quiz', Schemas::quizResult())],
			['check', $this->request('check', Schemas::checkResult())],
			['graphic', $this->request('graphic', Schemas::graphic())],
			['graphic-repair', $this->request('graphic-repair', Schemas::graphic())],
		];

		foreach (Profile::cases() as $profile) {
			$requests[] = ["page ({$profile->value})", $this->request('page', Schemas::part('page', $profile))];
			$requests[] = ["modules ({$profile->value})", $this->request('modules', Schemas::modulesResult($profile))];
			$requests[] = ["repair-page ({$profile->value})", $this->request('repair-page', Schemas::part('page', $profile))];
			$requests[] = ["repair-modules ({$profile->value})", $this->request('repair-modules', Schemas::part('modules', $profile))];
		}

		return $requests;
	}

	/**
	 * @param  array<string, mixed>  $schema
	 */
	private function request(string $step, array $schema): ModelRequest
	{
		return new ModelRequest(step: $step, system: 'Schema-Prüfung', prompt: 'x', schema: $schema, maxTokens: 1);
	}

	/**
	 * @param  array{0: string, 1: ModelRequest}  $entry
	 * @return array{name: string, model: string, bytes: int, status: 'ok'|'too_large'|'error', error: ?string}
	 */
	private function check(array $entry): array
	{
		[$name, $request] = $entry;
		$status = 'ok';
		$error = null;

		try {
			$this->model->generate($request);
		} catch (UsageAwareModelException $e) {
			// The grammar compiled and the answer stopped after the one token: that's the expected outcome
			if ($e->detail !== 'max_tokens') {
				[$status, $error] = ['error', $e->detail ?? $e->getMessage()];
			}
		} catch (ModelException $e) {
			$detail = $e->detail ?? $e->getMessage();
			[$status, $error] = str_contains($detail, 'grammar is too large') ? ['too_large', null] : ['error', $detail];
		}

		return [
			'name' => $name,
			'model' => $request->model(),
			'bytes' => strlen((string) json_encode($request->schema)),
			'status' => $status,
			'error' => $error,
		];
	}
}
