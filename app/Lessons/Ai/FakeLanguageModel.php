<?php

namespace App\Lessons\Ai;

use Closure;
use Database\Factories\LessonFactory;
use Throwable;

/**
 * Ersatz für Claude in Tests und lokal (LESSON_FAKE_AI=true).
 *
 * Ohne vorbereitete Antworten liefert es die Fotosynthese-Fixtures. In Tests lassen sich
 * pro Schritt Antworten, Closures oder Exceptions einreihen.
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
