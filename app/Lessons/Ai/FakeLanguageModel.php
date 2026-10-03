<?php

namespace App\Lessons\Ai;

use Closure;
use Database\Factories\LessonFactory;
use Throwable;

/**
 * Stand-in for Claude in tests and locally (LESSON_FAKE_AI=true).
 *
 * Without prepared answers it returns the photosynthesis fixtures. In tests answers,
 * closures or exceptions can be queued per step.
 */
class FakeLanguageModel implements LanguageModel
{
	/** @var array<string, list<array<string, mixed>|Closure(ModelRequest): array<string, mixed>|Throwable>> */
	private array $queue = [];

	/** @var list<ModelRequest> */
	public array $requests = [];

	public static function install(): self
	{
		$fake = new self;
		app()->instance(LanguageModel::class, $fake);

		return $fake;
	}

	/**
	 * @param  array<string, mixed>|Closure(ModelRequest): array<string, mixed>|Throwable  $response
	 */
	public function push(string $step, array|Closure|Throwable $response): self
	{
		$this->queue[$step][] = $response;

		return $this;
	}

	public function generate(ModelRequest $request): ModelResponse
	{
		$this->requests[] = $request;

		$response = isset($this->queue[$request->step]) && $this->queue[$request->step] !== []
			? array_shift($this->queue[$request->step])
			: self::defaultResponse($request->step);

		if ($response instanceof Throwable) {
			throw $response;
		}

		if ($response instanceof Closure) {
			$response = $response($request);
		}

		return new ModelResponse($response, 'fake', inputTokens: 1000, outputTokens: 500);
	}

	/**
	 * @return list<ModelRequest>
	 */
	public function requestsFor(string $step): array
	{
		return array_values(array_filter($this->requests, fn (ModelRequest $r) => $r->step === $step));
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function defaultResponse(string $step, string $fixture = 'fotosynthese'): array
	{
		$content = LessonFactory::fixture($fixture);
		$graphic = LessonFactory::fixture("$fixture.graphic");

		$page = Prompts::page($content);

		return match ($step) {
			'analysis' => [
				'source' => ['readable' => true, 'problem' => null],
				'subject' => 'Biologie',
				'summary' => 'Zusammenfassung der Buchseite zum Thema '.$content['meta']['topic'].'.',
				'additions' => [],
				'title' => $content['meta']['title'],
				'key_idea' => $content['meta']['key_idea'],
				'sections' => array_map(fn (array $section) => ['title' => $section['title'], 'goal' => "Erklärt «{$section['title']}»."], $content['sections']),
				'graphic_plans' => [['number' => 1, 'plan' => ['pattern' => $graphic['pattern'], 'idea' => $graphic['description']], 'note' => null]],
			],
			'page', 'repair-page' => ['page' => $page],
			'modules', 'repair-modules' => ['modules' => $content['modules']],
			'check' => ['corrections' => []],
			'graphic', 'graphic-repair' => $graphic,
			default => [],
		};
	}
}
