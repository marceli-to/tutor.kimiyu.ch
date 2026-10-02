<?php

namespace App\Actions\Generation;

use App\Lessons\Ai\LanguageModel;
use App\Lessons\Ai\ModelException;
use App\Lessons\Ai\ModelRequest;
use App\Lessons\Ai\ModelResponse;
use App\Lessons\Ai\UsageAwareModelException;
use App\Lessons\GenerationFailed;
use App\Lessons\LessonGone;
use App\Models\Lesson;

/**
 * One API call with an entry in the cost log, even when it fails.
 */
class CallModel
{
    public function __construct(private LanguageModel $model) {}

    /**
     * @throws GenerationFailed when the lesson belongs to no child any more
     * @throws ModelException on API errors
     * @throws LessonGone when the lesson was deleted during the call
     */
    public function handle(Lesson $lesson, ModelRequest $request): ModelResponse
    {
        $started = hrtime(true);
        // Load the child only once, not again on every call
        $lesson->loadMissing('child');
        $userId = $lesson->child?->user_id;

        // Without a child there is no account for the costs: don't call at all
        if ($userId === null) {
            throw new GenerationFailed('Diese Lernseite gehört zu keinem Kind mehr.');
        }

        $log = fn (string $status, ?ModelResponse $response, ?string $error = null) => $lesson->generations()->create([
            'user_id' => $userId,
            'step' => $request->step,
            'model' => $response->model ?? $request->model(),
            'status' => $status,
            'input_tokens' => $response->inputTokens ?? 0,
            'output_tokens' => $response->outputTokens ?? 0,
            'cache_read_tokens' => $response->cacheReadTokens ?? 0,
            'cache_write_tokens' => $response->cacheWriteTokens ?? 0,
            'cost_usd' => $response?->costUsd() ?? 0,
            'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
            'error' => $error ? mb_substr($error, 0, 2000) : null,
        ]);

        try {
            $response = $this->model->generate($request);
        } catch (UsageAwareModelException $e) {
            $log('error', $e->response, $e->detail ?? $e->getMessage());

            throw $e;
        } catch (ModelException $e) {
            $log('error', null, $e->detail ?? $e->getMessage());

            throw $e;
        }

        $log('ok', $response);

        // The parent may have deleted the lesson while the call was running: write nothing back
        if (! $lesson->stillExists()) {
            throw new LessonGone;
        }

        return $response;
    }
}
